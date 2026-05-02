<?php
// core/Console/Commands/MigrateStatusCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Container\Container;

class MigrateStatusCommand extends Command
{
    private Container $container;
    public function __construct(Container $container) { $this->container = $container; }

    public function name(): string        { return 'migrate:status'; }
    public function description(): string { return 'Show applied + pending migrations'; }

    public function handle(array $argv): int
    {
        $migrator = MigrateCommand::makeMigrator($this->container);
        $rows     = $migrator->status();
        if (empty($rows)) {
            $this->line('  No migrations found.');
            return 0;
        }
        printf("  %-50s  %-8s  %-6s\n", 'Migration', 'Status', 'Batch');
        $this->line('  ' . str_repeat('─', 68));
        foreach ($rows as $r) {
            printf("  %-50s  %-8s  %s\n",
                substr($r['migration'], 0, 50),
                $r['ran'] ? 'applied' : 'pending',
                $r['batch'] ?? '-'
            );
        }
        return 0;
    }
}
