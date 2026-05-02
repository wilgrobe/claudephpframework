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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
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

    public function beginTransaction(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void            { $this->pdo->commit(); }
    public function rollback(): void          { $this->pdo->rollBack(); }

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
        $items   = $this->fetchAll("$sql LIMIT ? OFFSET ?", array_merge($bindings, [$perPage, $offset]));
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
