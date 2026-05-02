<?php
// core/Queue/Worker.php
namespace Core\Queue;

/**
 * Worker — drain phase of the queue.
 *
 * Pull up to N ready jobs via DatabaseQueue::reserve(), instantiate each one
 * from its stored class + payload, run handle(), and finalise the row
 * according to whether it threw.
 *
 * Intentionally synchronous and single-pass. Long-running daemons are out of
 * scope for the cron-driven architecture — schedule:run calls runBatch() once
 * per cron tick and exits.
 */
class Worker
{
    /** Unique per worker process — stamped into jobs.reserved_by for forensics. */
    private string $workerId;

    public function __construct(private DatabaseQueue $queue, ?string $workerId = null)
    {
        $this->workerId = $workerId ?? (gethostname() ?: 'unknown') . ':' . getmypid();
    }

    /**
     * Reserve up to $limit ready jobs from $queue, run each, and finalise.
     *
     * @return array{picked:int, succeeded:int, failed:int, released:int, ids:int[]}
     */
    public function runBatch(string $queue = 'default', int $limit = 10): array
    {
        $claimed = $this->queue->reserve($this->workerId, $queue, $limit);

        $stats = ['picked' => count($claimed), 'succeeded' => 0, 'failed' => 0, 'released' => 0, 'ids' => []];

        foreach ($claimed as $row) {
            $id = (int) $row['id'];
            $stats['ids'][] = $id;

            try {
                $this->runOne($row);
                $this->queue->complete($id);
                $stats['succeeded']++;
            } catch (\Throwable $e) {
                $attempts    = (int) $row['attempts'];                // already incremented by reserve()
                $maxAttempts = (int) $row['max_attempts'];
                $msg         = $this->formatError($e);

                if ($attempts >= $maxAttempts) {
                    // Terminal failure — forward to Sentry so production
                    // job deaths surface alongside web exceptions. Transient
                    // retries (below) intentionally stay out of Sentry to
                    // avoid flooding: attempt-1 failures are usually just
                    // hiccups that backoff + retry resolves.
                    \Core\Services\SentryService::captureException($e);
                    $this->queue->fail($id, $msg);
                    $stats['failed']++;
                } else {
                    $backoff = $this->computeBackoff($row, $attempts);
                    $until   = (new \DateTimeImmutable())->modify("+{$backoff} seconds");
                    $this->queue->release($id, $until, $msg);
                    $stats['released']++;
                }
            }
        }

        return $stats;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function runOne(array $row): void
    {
        $class = (string) $row['class'];
        if (!class_exists($class)) {
            throw new \RuntimeException("Job class does not exist: $class");
        }
        if (!is_subclass_of($class, Job::class)) {
            throw new \RuntimeException("Job class is not a Core\\Queue\\Job: $class");
        }

        $payload = json_decode((string) $row['payload'], true) ?? [];
        if (!is_array($payload)) {
            throw new \RuntimeException("Job payload is not a JSON object: {$row['id']}");
        }

        /** @var Job $job */
        $job = $class::fromPayload($payload);
        $job->handle();
    }

    /**
     * Use the job's own backoff() if possible (for per-job tuning), else a
     * plain default.
     *
     * We memoize one Job instance per class in this worker process — backoff()
     * is, by contract, a pure function of $attemptThatFailed, so a single
     * instance serves every failed row of that class in the batch. Avoids
     * `new $class()` per failure, which matters when a queue is backed up and
     * the same job class fails 100s of times in a drain cycle.
     *
     * @var array<class-string<Job>, Job|false> false = construction failed, skip next time too
     */
    private array $jobInstanceCache = [];

    private function computeBackoff(array $row, int $attemptThatFailed): int
    {
        $class = (string) $row['class'];

        if (!array_key_exists($class, $this->jobInstanceCache)) {
            $this->jobInstanceCache[$class] = false;
            if (class_exists($class) && is_subclass_of($class, Job::class)) {
                try {
                    $this->jobInstanceCache[$class] = new $class();
                } catch (\Throwable) {
                    // Constructor refused a no-arg call; fall through to default.
                }
            }
        }

        $tmp = $this->jobInstanceCache[$class];
        if ($tmp instanceof Job) {
            return $tmp->backoff($attemptThatFailed);
        }

        // Static fallback mirrors Job::backoff's defaults.
        return match (true) {
            $attemptThatFailed <= 1 => 60,
            $attemptThatFailed === 2 => 300,
            default                 => 900,
        };
    }

    private function formatError(\Throwable $e): string
    {
        return $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    }
}
