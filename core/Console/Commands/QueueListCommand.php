<?php
// core/Console/Commands/QueueListCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\DatabaseQueue;

/**
 * queue:list — recent jobs, optionally filtered by status.
 *
 *   php artisan queue:list
 *   php artisan queue:list --status=failed
 *   php artisan queue:list --status=failed --limit=50
 */
class QueueListCommand extends Command
{
    public function name(): string        { return 'queue:list'; }
    public function description(): string { return 'List recent jobs from the queue table'; }
    public function usage(): string       { return 'php artisan queue:list [--status=pending|running|completed|failed] [--limit=20]'; }

    public function handle(array $argv): int
    {
        $status = $this->option($argv, 'status');
        $limit  = max(1, (int) ($this->option($argv, 'limit') ?: 20));

        $valid = ['pending', 'running', 'completed', 'failed'];
        if ($status !== null && $status !== '' && !in_array($status, $valid, true)) {
            $this->error("Invalid --status: $status (valid: " . implode(', ', $valid) . ')');
            return 1;
        }

        /** @var DatabaseQueue $queue */
        $queue = app(DatabaseQueue::class);
        $rows  = $queue->recent($limit, $status ?: null);

        if (empty($rows)) {
            $this->line('No jobs.');
            return 0;
        }

        $this->line(sprintf('%-6s  %-10s  %-9s  %-5s  %-40s  %s',
            'ID', 'QUEUE', 'STATUS', 'TRIES', 'CLASS', 'CREATED'));

        foreach ($rows as $r) {
            $tries = $r['attempts'] . '/' . $r['max_attempts'];
            $class = (string) $r['class'];
            if (strlen($class) > 40) $class = '...' . substr($class, -37);
            $this->line(sprintf('%-6d  %-10s  %-9s  %-5s  %-40s  %s',
                (int) $r['id'],
                $r['queue'],
                $r['status'],
                $tries,
                $class,
                $r['created_at']
            ));
            if ($status === 'failed' && !empty($r['last_error'])) {
                $first = strtok((string) $r['last_error'], "\n");
                $this->line('         └─ ' . $first);
            }
        }

        return 0;
    }
}
