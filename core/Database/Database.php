<?php
// core/Database/Database.php
namespace Core\Database;

use PDO;
use PDOException;

/**
 * Database — PDO wrapper with prepared-statement enforcement.
 *
 * ALL queries go through PDO prepared statements; raw string
 * interpolation is never used, preventing SQL injection entirely.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $cfg = require BASE_PATH . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'], $cfg['database']
        );

        // PHP 8.4+ introduces class-based driver constants. Prefer the new
        // Pdo\Mysql::ATTR_FOUND_ROWS; fall back to the deprecated
        // PDO::MYSQL_ATTR_FOUND_ROWS on older PHP versions.
        $foundRowsAttr = defined('Pdo\\Mysql::ATTR_FOUND_ROWS')
            ? \Pdo\Mysql::ATTR_FOUND_ROWS
            : PDO::MYSQL_ATTR_FOUND_ROWS;

        $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // force true prepared statements
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            $foundRowsAttr               => true,
        ]);

        // Strict SQL mode for safer inserts
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Discard the cached singleton so the next getInstance() reads
     * config/database.php afresh and constructs a new PDO. Useful for
     * multi-tenant flows that need to swap the active connection
     * mid-process (e.g. the tenant:create command running framework
     * migrations against a freshly-provisioned tenant database before
     * returning to the central DB).
     *
     * Existing PDO references already handed out continue to work —
     * they're independent objects, not proxies. Reset only affects
     * what subsequent getInstance() calls return.
     *
     * Phase 43.196c L1 — DEVELOPER WARNING. Singleton services bound
     * in the container that captured `Database::getInstance()` in
     * their constructor STILL hold the OLD PDO after a swap. Their
     * queries continue to read the pre-swap DB even though the rest
     * of the request thinks it's on the new one. Real symptom: a
     * tenant-context swap inside a controller invocation makes a
     * cached `MailService` (or other DB-using singleton) query
     * central instead of the tenant.
     *
     * Mitigation patterns:
     *   - Always resolve `Database::getInstance()` at method-call
     *     time, NOT at service-constructor time.
     *   - For long-lived singletons that genuinely need DB access,
     *     have them hold a closure `(fn() => Database::getInstance())`
     *     instead of the PDO directly.
     *   - When swapping is necessary (TenantProvisioner / artisan
     *     `tenant:migrate`), wrap with try/finally restoring both
     *     `$_ENV['DB_DATABASE']` AND calling `Database::resetInstance`
     *     so the central singleton rebuilds on next getInstance().
     */
    public static function resetInstance(): void
    {
        // Phase 43.202b H7 — refuse mid-transaction reset. Pre-fix
        // discarding the singleton while txDepth > 0 left the OLD
        // PDO holding an uncommitted transaction with no API to find
        // / commit / rollback it; MySQL would eventually rollback on
        // connection close, but during the window data state was
        // inconsistent + `inTransaction()` on the NEW instance lied
        // to downstream code. Now: throw a clear LogicException so
        // operators see the issue rather than running into mystery
        // partial-commit bugs.
        if (self::$instance !== null) {
            try {
                if (self::$instance->inTransaction()) {
                    throw new \LogicException(
                        "Database::resetInstance refused — singleton has an in-flight transaction. " .
                        "Caller must commit() / rollBack() first, or use unsafeResetInstance() if abandoning the tx is intentional."
                    );
                }
            } catch (\LogicException $e) {
                throw $e;
            } catch (\Throwable) {
                // inTransaction() check failed on a half-dead PDO; safe to discard.
            }
        }
        self::$instance = null;
    }

    /**
     * Phase 43.202b H7 — escape hatch for the rare case where
     * abandoning the in-flight transaction is the deliberate choice
     * (test harnesses, recovery after detected corruption). MySQL
     * will rollback on connection close anyway; this just bypasses
     * the safety check + the LogicException.
     */
    public static function unsafeResetInstance(): void
    {
        self::$instance = null;
    }

    // ── Query builder entry point ─────────────────────────────────────────────

    /**
     * Start a chainable query on $table.
     *
     *   $db->table('users')->where('id', 42)->first();
     *   $db->table('content_items')->whereIn('owner_group_id', $ids)->paginate(20);
     *
     * The builder preserves this wrapper's prepared-statement guarantee —
     * values are always bound, identifiers are whitelisted.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    // ── Core Execute ──────────────────────────────────────────────────────────

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt;
        } catch (\PDOException $e) {
            // MySQL 1615 "Prepared statement needs to be re-prepared": the
            // server evicted this table's definition from its cache, which
            // stales the cached server-side prepared statement. On this
            // multi-tenant install table_definition_cache (2000) is far
            // smaller than the live table count (~8700 across 18 tenant DBs +
            // central), so eviction is PERSISTENT — even a fresh re-prepare
            // re-evicts instantly. So we can't just re-prepare; we fall back
            // to an EMULATED prepare for this one statement, where the query
            // is assembled client-side + sent as plain SQL with NO server-side
            // statement, so 1615 cannot occur. The failed statement never
            // executed, so this is safe. ROOT-CAUSE FIX (operator): raise
            // MySQL table_definition_cache + table_open_cache above the table
            // count on the DB host. Was intermittently breaking register/login.
            if ((int) ($e->errorInfo[1] ?? 0) === 1615) {
                $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
                try {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($bindings);
                    return $stmt;
                } finally {
                    $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
                }
            }
            throw $e;
        }
    }

    // ── Read Helpers ──────────────────────────────────────────────────────────

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->query($sql, $bindings)->fetch();
        return $row ?: null;
    }

    /** Alias kept for backward compatibility */
    public function fetch(string $sql, array $bindings = []): ?array
    {
        return $this->fetchOne($sql, $bindings);
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed
    {
        return $this->query($sql, $bindings)->fetchColumn($col);
    }

    // ── Write Helpers ─────────────────────────────────────────────────────────

    /**
     * Build and execute a parameterized INSERT.
     * Column names are whitelisted by the caller via array keys.
     * Values are bound as parameters — never interpolated.
     */
    public function insert(string $table, array $data): int
    {
        $this->assertSafeIdentifier($table);
        $cols        = array_keys($data);
        $this->assertSafeIdentifiers($cols);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $this->query("INSERT INTO `$table` ($colList) VALUES ($placeholders)", array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert many rows in a single statement.
     *
     * All $rows must share the same column set (taken from $rows[0]'s keys);
     * any row whose keys don't match the header set has its values aligned
     * to the header order so a stray key on a later row can't shift columns.
     *
     * Returns the number of rows inserted (rowCount of the prepared
     * statement). lastInsertId is NOT returned because driver behavior
     * varies for batched inserts: some return the first id, some the
     * last; relying on either invites bugs when auto_increment_increment
     * isn't 1. If callers need ids back, they should fall back to single-
     * row inserts.
     */
    public function insertMany(string $table, array $rows): int
    {
        if (empty($rows)) return 0;
        $this->assertSafeIdentifier($table);
        $cols = array_keys($rows[0]);
        $this->assertSafeIdentifiers($cols);

        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        $bindings = [];
        $allPlaceholders = [];
        foreach ($rows as $row) {
            $allPlaceholders[] = $rowPlaceholder;
            foreach ($cols as $c) {
                $bindings[] = $row[$c] ?? null;
            }
        }
        $sql = "INSERT INTO `$table` ($colList) VALUES " . implode(', ', $allPlaceholders);
        $stmt = $this->query($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Build and execute a parameterized UPDATE.
     * $where is a raw SQL fragment for the WHERE clause;
     * $whereBindings are its bound parameters.
     */
    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $this->assertSafeIdentifier($table);
        $cols = array_keys($data);
        $this->assertSafeIdentifiers($cols);
        $setParts = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $stmt = $this->query(
            "UPDATE `$table` SET $setParts WHERE $where",
            array_merge(array_values($data), $whereBindings)
        );
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $whereBindings = []): int
    {
        $this->assertSafeIdentifier($table);
        $stmt = $this->query("DELETE FROM `$table` WHERE $where", $whereBindings);
        return $stmt->rowCount();
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    /**
     * Phase 43.196b H3 — savepoint-based nested-transaction support.
     *
     * Pre-fix: `beginTransaction()` blindly called `PDO::beginTransaction()`.
     * If already inside a transaction, PDO throws (or, on some drivers,
     * silently no-ops). Real failure mode: a block-render callback (or
     * other inner code path) inside a wrapping transaction calls
     * `beginTransaction()` itself. PDO throws → caller's catch flagged
     * the inner work as failed → outer code might roll back assuming
     * the inner tried/failed, OR the inner caller catches its own
     * throw and assumes "no tx open" → inner's rollback unwinds the
     * OUTER tx silently (the actual Phase 16 bug shape that prompted
     * the per-ledger inTransaction guards).
     *
     * Now: at depth 0, real BEGIN. At depth N>0, SAVEPOINT sp_N.
     * Commit pops the savepoint (RELEASE) or commits the outer real
     * tx. Rollback rolls back to the savepoint (or full rollback on
     * outer). Caller code uses the same beginTransaction/commit/
     * rollback API regardless of nesting.
     */
    private int $txDepth = 0;

    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            // SAVEPOINT identifier must be a bare word — `sp_N` is safe.
            $this->pdo->exec('SAVEPOINT sp_' . $this->txDepth);
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth <= 0) {
            throw new \RuntimeException('Database::commit called outside a transaction');
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT sp_' . $this->txDepth);
        }
    }

    public function rollback(): void
    {
        if ($this->txDepth <= 0) {
            // Tolerate over-rollback by no-op'ing — matches PDO's
            // historical behavior and avoids cascading the failure.
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->txDepth);
        }
    }

    /** True if any transaction (real or savepoint-nested) is open. */
    public function inTransaction(): bool
    {
        return $this->txDepth > 0 || $this->pdo->inTransaction();
    }

    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function paginate(string $sql, array $bindings, int $page, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;
        $total   = (int) $this->fetchColumn("SELECT COUNT(*) FROM ($sql) _count", $bindings);
        // LIMIT/OFFSET inlined as ints (both already integers above) so the
        // query() 1615→emulated-prepare fallback can't trip on a quoted LIMIT.
        $items   = $this->fetchAll("$sql LIMIT " . (int) $perPage . " OFFSET " . (int) $offset, $bindings);
        return [
            'items'        => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ];
    }

    // ── Safety Assertions ─────────────────────────────────────────────────────

    /**
     * Table/column identifiers must match [a-zA-Z0-9_].
     * This guards against the rare case where a dynamic identifier
     * could slip through. Values are always bound via PDO params.
     */
    private function assertSafeIdentifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Unsafe SQL identifier detected: [$name]"
            );
        }
    }

    private function assertSafeIdentifiers(array $names): void
    {
        foreach ($names as $name) {
            $this->assertSafeIdentifier($name);
        }
    }

    public function pdo(): PDO { return $this->pdo; }
}
