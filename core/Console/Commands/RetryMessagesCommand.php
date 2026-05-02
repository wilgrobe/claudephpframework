<?php
// core/Console/Commands/RetryMessagesCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Services\MessageRetryService;

/**
 * Drain a batch of failed email/SMS rows out of message_log.
 * Paired with the opportunistic drain that runs after each web request;
 * this CLI form is for crontab or manual invocation with a custom batch size.
 */
class RetryMessagesCommand extends Command
{
    public function name(): string        { return 'retry-messages'; }
    public function description(): string { return 'Retry up to N failed email/SMS rows in message_log (default 20)'; }
    public function usage(): string       { return 'php artisan retry-messages [N]'; }

    public function handle(array $argv): int
    {
        $limit = isset($argv[2]) ? max(1, (int) $argv[2]) : 20;

        $this->line('[' . date('Y-m-d H:i:s') . "] Retrying up to $limit failed message(s)...");
        $svc = new MessageRetryService();
        $r   = $svc->run($limit);
        $this->line("  picked:    {$r['picked']}");
        $this->line("  succeeded: {$r['succeeded']}");
        $this->line("  failed:    {$r['failed']}");
        if (!empty($r['ids'])) {
            $this->line('  ids:       ' . implode(', ', $r['ids']));
        }
        $this->line('[' . date('Y-m-d H:i:s') . '] Done.');
        return 0;
    }
}
