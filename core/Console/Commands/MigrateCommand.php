<?php
// core/Console/Commands/MigrateCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Container\Container;
use Core\Database\Database;
use Core\Database\Migrator;
use Core\Module\ModuleRegistry;

/**
 * Run all pending PHP migrations. First run seeds legacy database/*.sql
 * filenames into schema_migrations as already-applied (batch 0) so they
 * are not replayed against schemas already in production.
 */
class MigrateCommand extends Command
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function name(): string        { return 'migrate'; }
    public function description(): string { return 'Run pending database migrations'; }

    public function handle(array $argv): int
    {
        $migrator = self::makeMigrator($this->container);
        $install  = $migrator->install();

        if ($install['created']) {
            $this->success('created schema_migrations');
            if ($install['seeded']) {
                $this->success('grandfathered ' . count($install['seeded']) . ' legacy .sql migration(s):');
                foreach ($install['seeded'] as $n) $this->line("      - $n");
            }
        }

        $result = $migrator->migrate();
        if (empty($result['ran'])) {
            $this->line('  Nothing to migrate.');
        } else {
            $this->line("  Batch {$result['batch']}: ran " . count($result['ran']) . ' migration(s):');
            foreach ($result['ran'] as $n) $this->line("      ✓ $n");
        }
        return 0;
    }

    /**
     * Build a Migrator that scans the core migrations dir plus every
     * module's migrations/ dir. Shared with MigrateRollback/Status so all
     * three see the same set of migrations.
     */
    public static function makeMigrator(Container $container): Migrator
    {
        $paths = [BASE_PATH . '/database/migrations'];
        if ($container->has(ModuleRegistry::class)) {
            try {
                $reg = $container->get(ModuleRegistry::class);
                foreach ($reg->migrationPaths() as $p) $paths[] = $p;
            } catch (\Throwable $_) {
                // Module system may not be wired in some artisan contexts;
                // fall back to core-only rather than failing outright.
            }
        }
        return new Migrator($container->get(Database::class), $paths);
    }
}
