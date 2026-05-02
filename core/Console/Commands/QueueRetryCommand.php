<?php
// core/Console/Commands/QueueRetryCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\DatabaseQueue;

/**
 * queue:retry {id} — reset a failed job back to pending.
 *
 * Resets attempts to 0 and available_at to NOW, so the next worker tick
 * will pick it up. If the underlying cause of the failure isn't fixed,
 * it'll fail again — that's by design. Check queue:list --status=failed
 * before bulk-retrying.
 */
class QueueRetryCommand extends Command
{
    public function name(): string        { return 'queue:retry'; }
    public function description(): string { return 'Reset a failed job back to pending so it runs on the next drain'; }
    public function usage(): string       { return 'php artisan queue:retry <job-id>'; }

    public function handle(array $argv): int
    {
        $id = (int) ($argv[2] ?? 0);
        if ($id <= 0) {
            $this->error('Usage: ' . $this->usage());
            return 1;
        }

        /** @var DatabaseQueue $queue */
        $queue = app(DatabaseQueue::class);
        $ok    = $queue->retry($id);

        if (!$ok) {
            $this->warn("No failed job with id=$id (may already be pending/running, or the id doesn't exist).");
            return 1;
        }

        $this->success("Job $id reset to pending.");
        return 0;
    }
}
