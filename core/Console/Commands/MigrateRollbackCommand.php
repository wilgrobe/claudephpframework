<?php
// core/Console/Commands/MigrateRollbackCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Container\Container;

class MigrateRollbackCommand extends Command
{
    private Container $container;
    public function __construct(Container $container) { $this->container = $container; }

    public function name(): string        { return 'migrate:rollback'; }
    public function description(): string { return 'Roll back the most recent migration batch'; }

    public function handle(array $argv): int
    {
        $migrator = MigrateCommand::makeMigrator($this->container);
        $rolled   = $migrator->rollback();
        if (empty($rolled)) {
            $this->line('  Nothing to roll back.');
        } else {
            $this->line('  Rolled back ' . count($rolled) . ' migration(s):');
            foreach ($rolled as $n) $this->line("      ✗ $n");
        }
        return 0;
    }
}
