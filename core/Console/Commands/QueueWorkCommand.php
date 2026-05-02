<?php
// core/Console/Commands/QueueWorkCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\Worker;

/**
 * queue:work — drain-only. Skips the schedule:tick phase.
 *
 * Useful when you want a separate worker fleet focused purely on processing
 * jobs (e.g. a dedicated image-processing queue). The scheduler still needs
 * to run somewhere via schedule:run.
 */
class QueueWorkCommand extends Command
{
    public function name(): string        { return 'queue:work'; }
    public function description(): string { return 'Process a batch of ready jobs from a queue (drain-only, no scheduler tick)'; }
    public function usage(): string       { return 'php artisan queue:work [--queue=default] [--max=20]'; }

    public function handle(array $argv): int
    {
        $queueName = $this->option($argv, 'queue') ?: 'default';
        $max       = max(1, (int) ($this->option($argv, 'max') ?: 20));

        $this->line('[' . date('Y-m-d H:i:s') . "] queue:work queue=$queueName max=$max");

        /** @var Worker $worker */
        $worker = app(Worker::class);
        $r = $worker->runBatch($queueName, $max);

        $this->line("  picked:    {$r['picked']}");
        $this->line("  succeeded: {$r['succeeded']}");
        $this->line("  released:  {$r['released']}");
        $this->line("  failed:    {$r['failed']}");
        if (!empty($r['ids'])) {
            $this->line('  ids:       ' . implode(', ', $r['ids']));
        }

        $this->line('[' . date('Y-m-d H:i:s') . '] done.');
        return 0;
    }
}
