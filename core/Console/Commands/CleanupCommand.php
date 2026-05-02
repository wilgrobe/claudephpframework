<?php
// core/Console/Commands/CleanupCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Services\SessionCleanupService;

/**
 * Purge stale sessions, 2FA challenges, expired login attempts, and tokens.
 * Intended to run from cron every ~15 minutes (see README).
 */
class CleanupCommand extends Command
{
    public function name(): string        { return 'cleanup'; }
    public function description(): string { return 'Purge stale sessions, 2FA challenges, login attempts, tokens'; }

    public function handle(array $argv): int
    {
        $this->line('[' . date('Y-m-d H:i:s') . '] Running cleanup...');
        $svc     = new SessionCleanupService();
        $results = $svc->run();
        foreach ($results as $key => $count) {
            $this->line("  $key: $count row(s) deleted");
        }
        $this->line('[' . date('Y-m-d H:i:s') . '] Done.');
        return 0;
    }
}
