<?php
// core/Database/Migrator.php
namespace Core\Database;

use DirectoryIterator;

/**
 * Migrator — discovers, runs, rolls back, and records migrations.
 *
 * Storage layout:
 *   database/migrations/              Core framework migrations (PHP)
 *   database/*.sql                    Legacy SQL files (grandfathered)
 *   modules/{name}/migrations/        Per-module migrations (PHP)
 *
 * Tracking table: `schema_migrations` (auto-created on first `install()`)
 *   migration   VARCHAR(255) UNIQUE   — filename without .php/.sql extension
 *   batch       INT UNSIGNED          — groups a single `migrate` invocation for rollback
 *   ran_at      TIMESTAMP
 *
 * Filename convention (ordering is lexicographic):
 *   YYYY_MM_DD_HHMMSS_snake_case_name.php
 *
 * Hybrid behavior: on install(), any existing database/*.sql filenames are
 * recorded as already-applied with batch = 0 so migrate won't attempt to
 * replay them. If you want to re-run one, delete its row from
 * schema_migrations first.
 */
class Migrator
{
    private Database $db;

    /** @var string[] directories to scan for PHP migrations */
    private array $paths;

    public function __construct(Database $db, array $paths = [])
    {
        $this->db    = $db;
        $this->paths = $paths ?: [BASE_PATH . '/database/migrations'];
    }

    /** Add a module's migrations directory to the scan list. */
    public function addPath(string $path): void
    {
        if (!in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    // ── Setup ─────────────────────────────────────────────────────────────────

    /**
     * Create the schema_migrations table if missing, and grandfather existing
     * database/*.sql files as batch 0 so migrate() won't try to re-run them.
     * Safe to call multiple times.
     */
    public function install(): array
    {
        $created  = false;
        $seeded   = [];

        if (!$this->tableExists('schema_migrations')) {
            $this->db->query("
                CREATE TABLE schema_migrations (
                    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    batch     INT UNSIGNED NOT NULL,
                    ran_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $created = true;

            // Grandfather legacy SQL files from database/ (NOT database/migrations/)
            foreach (glob(BASE_PATH . '/database/*.sql') ?: [] as $sqlFile) {
                $name = pathinfo($sqlFile, PATHINFO_FILENAME);
                $this->db->insert('schema_migrations', [
                    'migration' => $name,
                    'batch'     => 0,
                ]);
                $seeded[] = $name;
            }
        }

        return ['created' => $created, 'seeded' => $seeded];
    }

    // ── Run ───────────────────────────────────────────────────────────────────

    /**
     * Run all pending PHP migrations in order. Each migrate() call shares one
     * batch number so rollback reverses them as a group.
     *
     * @return array{ran: string[], batch: int}
     */
    public function migrate(): array
    {
        $this->install();

        $pending = $this->pendingMigrations();
        if (empty($pending)) {
            return ['ran' => [], 'batch' => $this->lastBatch()];
        }

        $batch = $this->lastBatch() + 1;
        $ran   = [];

        foreach ($pending as $name => $path) {
            $this->runUp($name, $path, $batch);
            $ran[] = $name;
        }

        return ['ran' => $ran, 'batch' => $batch];
    }

    /**
     * Roll back the most recent batch.
     *
     * @return string[] migration names that were rolled back
     */
    public function rollback(): array
    {
        $this->install();
        $batch = $this->lastBatch();
        if ($batch <= 0) return [];

        $rows = $this->db->fetchAll(
            "SELECT migration FROM schema_migrations WHERE batch = ? ORDER BY id DESC",
            [$batch]
        );

        $rolled = [];
        foreach ($rows as $row) {
            $name = $row['migration'];
            $path = $this->findMigration($name);
            if ($path === null) {
                // Migration file is gone — remove the row but warn.
                $this->db->delete('schema_migrations', 'migration = ?', [$name]);
                $rolled[] = "$name (file missing; record removed)";
                continue;
            }
            $this->runDown($name, $path);
            $rolled[] = $name;
        }
        return $rolled;
    }

    // ── Status ────────────────────────────────────────────────────────────────

    /**
     * One row per discovered migration, with its applied status.
     *
     * @return array<array{migration: string, ran: bool, batch: ?int, path: string}>
     */
    public function status(): array
    {
        $this->install();
        $applied = $this->appliedMap();
        $all     = $this->discover();
        $status  = [];

        foreach ($all as $name => $path) {
            $status[] = [
                'migration' => $name,
                'ran'       => isset($applied[$name]),
                'batch'     => $applied[$name] ?? null,
                'path'      => $path,
            ];
        }

        // Also surface applied migrations whose file is missing (e.g. legacy .sql)
        foreach ($applied as $name => $batch) {
            if (!isset($all[$name])) {
                $status[] = [
                    'migration' => $name,
                    'ran'       => true,
                    'batch'     => $batch,
                    'path'      => '(legacy or missing)',
                ];
            }
        }
        return $status;
    }

    // ── Scaffolding ───────────────────────────────────────────────────────────

    /** Create a new empty migration file under the first registered path. */
    public function make(string $name): string
    {
        $dir = $this->paths[0];
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $timestamp = date('Y_m_d_His');
        $slug      = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($name))) ?: 'migration';
        $slug      = trim($slug, '_');
        $filename  = "{$timestamp}_{$slug}.php";
        $path      = "$dir/$filename";

        file_put_contents($path, <<<'PHP'
<?php
use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            -- Write your CREATE / ALTER / INSERT here.
        ");
    }

    public function down(): void
    {
        $this->db->query("
            -- Reverse of up().
        ");
    }
};

PHP);
        return $path;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function runUp(string $name, string $path, int $batch): void
    {
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [$name] must return an instance of Core\\Database\\Migration");
        }
        // Inject the Database (anonymous class extends Migration — ctor already takes Database)
        // But `require` returned one built with no ctor args? We require the file to return
        // `new class extends Migration { ... }` which picks up the protected $db via a helper
        // setter on the base. Simpler: reflection-set it.
        $this->hydrate($migration);

        $migration->up();
        $this->db->insert('schema_migrations', [
            'migration' => $name,
            'batch'     => $batch,
        ]);
    }

    private function runDown(string $name, string $path): void
    {
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [$name] must return an instance of Core\\Database\\Migration");
        }
        $this->hydrate($migration);
        $migration->down();
        $this->db->delete('schema_migrations', 'migration = ?', [$name]);
    }

    /** Inject $this->db into a Migration built by `new class extends Migration` (no-arg). */
    private function hydrate(Migration $m): void
    {
        $ref = new \ReflectionClass($m);
        $prop = $ref->getProperty('db');
        $prop->setValue($m, $this->db);
    }

    /**
     * Migration files found on disk, keyed by name (filename without .php).
     * Sorted lexicographically — naming convention puts timestamp first.
     *
     * @return array<string, string> name => absolute path
     */
    public function discover(): array
    {
        $found = [];
        foreach ($this->paths as $dir) {
            if (!is_dir($dir)) continue;
            foreach (new DirectoryIterator($dir) as $f) {
                if ($f->isDot() || !$f->isFile()) continue;
                if ($f->getExtension() !== 'php')  continue;
                $name = $f->getBasename('.php');
                $found[$name] = $f->getPathname();
            }
        }
        ksort($found);
        return $found;
    }

    /** @return array<string, string> migrations not yet applied */
    private function pendingMigrations(): array
    {
        $applied = $this->appliedMap();
        return array_filter(
            $this->discover(),
            fn($_, $name) => !isset($applied[$name]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @return array<string, int> migration name => batch number */
    private function appliedMap(): array
    {
        $rows = $this->db->fetchAll("SELECT migration, batch FROM schema_migrations");
        $out = [];
        foreach ($rows as $r) $out[$r['migration']] = (int) $r['batch'];
        return $out;
    }

    private function lastBatch(): int
    {
        return (int) $this->db->fetchColumn("SELECT COALESCE(MAX(batch), 0) FROM schema_migrations");
    }

    private function findMigration(string $name): ?string
    {
        $all = $this->discover();
        return $all[$name] ?? null;
    }

    private function tableExists(string $table): bool
    {
        $found = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return (int) $found > 0;
    }
}
