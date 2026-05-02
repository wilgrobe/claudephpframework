<?php
// core/Console/Commands/ScheduleRunCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\Worker;
use Core\Scheduling\Scheduler;

/**
 * schedule:run — the master cycle.
 *
 * Designed to be invoked by a single frequent cron (every minute). Each tick:
 *   1. Scheduler::tick()   — promote due scheduled_tasks into jobs rows.
 *   2. Worker::runBatch()  — drain up to --max ready jobs.
 *
 * Doing both in one process keeps the cron setup to one line and avoids a
 * gap between "scheduled fire" and "work runs". For higher throughput (or
 * a long-running daemon), run `php artisan queue:work` separately.
 */
class ScheduleRunCommand extends Command
{
    public function name(): string        { return 'schedule:run'; }
    public function description(): string { return 'Promote due scheduled tasks, then drain a batch of ready jobs'; }
    public function usage(): string       { return 'php artisan schedule:run [--queue=default] [--max=20]'; }

    public function handle(array $argv): int
    {
        $queueName = $this->option($argv, 'queue') ?: 'default';
        $max       = max(1, (int) ($this->option($argv, 'max') ?: 20));

        $this->line('[' . date('Y-m-d H:i:s') . "] schedule:run queue=$queueName max=$max");

        /** @var Scheduler $scheduler */
        $scheduler = app(Scheduler::class);
        $tickResult = $scheduler->tick();
        $this->line("  scheduled: promoted {$tickResult['promoted']}"
            . ($tickResult['names'] ? ' (' . implode(', ', $tickResult['names']) . ')' : ''));

        /** @var Worker $worker */
        $worker = app(Worker::class);
        $drainResult = $worker->runBatch($queueName, $max);
        $this->line("  drain:     picked {$drainResult['picked']}, "
            . "succeeded {$drainResult['succeeded']}, "
            . "released {$drainResult['released']}, "
            . "failed {$drainResult['failed']}");

        $this->line('[' . date('Y-m-d H:i:s') . '] done.');
        return 0;
    }
}
