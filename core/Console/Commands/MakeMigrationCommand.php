<?php
// core/Console/Commands/MakeMigrationCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Container\Container;

/**
 * Create a new empty migration stub under database/migrations/ (or a
 * module's migrations/ when --module=<name> is passed).
 *
 *   php artisan make:migration create_widgets_table
 *   php artisan make:migration add_status_to_content --module=content
 */
class MakeMigrationCommand extends Command
{
    private Container $container;
    public function __construct(Container $container) { $this->container = $container; }

    public function name(): string        { return 'make:migration'; }
    public function description(): string { return 'Create a new migration stub'; }
    public function usage(): string       { return 'php artisan make:migration NAME [--module=<name>]'; }

    public function handle(array $argv): int
    {
        $name = $this->arg($argv, 2);
        if (empty($name) || str_starts_with($name, '--')) {
            $this->error('Usage: ' . $this->usage());
            return 1;
        }

        $module = $this->option($argv, 'module');
        $paths  = $module !== null && $module !== ''
            ? [BASE_PATH . '/modules/' . strtolower($module) . '/migrations']
            : [BASE_PATH . '/database/migrations'];

        // Reuse the Migrator's make() helper — it knows the filename + stub
        // conventions and owns directory creation.
        $migrator = MigrateCommand::makeMigrator($this->container);
        // Point the migrator at the target directory for make() — its own
        // $paths[0] is what it writes into. Use reflection because the API
        // is otherwise read-only regarding paths.
        $ref = new \ReflectionObject($migrator);
        $prop = $ref->getProperty('paths');
        $prop->setAccessible(true);
        $prop->setValue($migrator, $paths);

        $path = $migrator->make($name);
        $this->success('created ' . substr($path, strlen(BASE_PATH) + 1));
        return 0;
    }
}
