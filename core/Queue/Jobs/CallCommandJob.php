<?php
// core/Queue/Jobs/CallCommandJob.php
namespace Core\Queue\Jobs;

use Core\Console\CommandRegistry;
use Core\Queue\Job;

/**
 * Bridge job — invoke an existing artisan Command from the queue.
 *
 * Used by scheduled_tasks that wrap a legacy CLI command rather than having
 * their own Job class. Seed migration for `retry-messages` is the canonical
 * example: we don't want to rewrite MessageRetryService as a new Job, we just
 * want the scheduler to run it every minute like a normal cron entry.
 *
 * Runs the command in-process so stdout/stderr are captured by whatever's
 * running the worker. A non-zero return code counts as a failure, which
 * the worker translates into release()/fail() via the same backoff policy.
 */
class CallCommandJob extends Job
{
    /** Registered command name (e.g. 'retry-messages'). */
    public string $command = '';

    /**
     * Extra positional args appended after the command name.
     * @var array<int, string>
     */
    public array $args = [];

    public function handle(): void
    {
        $registry = app(CommandRegistry::class);
        if (!$registry instanceof CommandRegistry) {
            throw new \RuntimeException('CommandRegistry is not bound in the container.');
        }

        $cmd = $registry->get($this->command);
        if ($cmd === null) {
            throw new \RuntimeException("Unknown command: {$this->command}");
        }

        // Dispatch uses $argv[1] as the command name; reassemble accordingly.
        // We don't need $argv[0]; the command handler never reads it.
        $argv = ['artisan', $this->command, ...$this->args];
        $code = (int) $cmd->handle($argv);

        if ($code !== 0) {
            throw new \RuntimeException(
                "Command '{$this->command}' exited with code $code"
            );
        }
    }
}
