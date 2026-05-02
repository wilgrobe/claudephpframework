<?php
// core/Queue/Job.php
namespace Core\Queue;

/**
 * Base class for queueable jobs.
 *
 *   class SendWelcomeEmail extends Job {
 *       public int $userId = 0;
 *       public function handle(): void {
 *           app(MailService::class)->send(...);
 *       }
 *   }
 *
 *   // Dispatch:
 *   app(DatabaseQueue::class)->push(new SendWelcomeEmail(userId: 42));
 *
 * Serialization defaults to "all public properties" — override toPayload()
 * and fromPayload() if you need tighter control (e.g. to avoid serializing
 * a cached object or to rename keys).
 *
 * Jobs must be newable with no constructor arguments so fromPayload() can
 * reconstruct them on the worker side. Use public properties + named args
 * at dispatch time; the properties travel through the DB unchanged.
 */
abstract class Job
{
    /** Run the work. Resolve collaborators via app(...) / Container. */
    abstract public function handle(): void;

    /** Queue name this job dispatches onto. Override for dedicated queues. */
    public function queue(): string
    {
        return 'default';
    }

    /** How many tries before status=failed. Inclusive of the first attempt. */
    public function maxAttempts(): int
    {
        return 3;
    }

    /**
     * Seconds to wait before the job is retryable after a transient failure.
     * Indexed by the attempt number that JUST failed (1-based).
     * Default: 60s, 5min, 15min — overshoot-safe for most I/O.
     */
    public function backoff(int $attemptThatFailed): int
    {
        return match (true) {
            $attemptThatFailed <= 1 => 60,
            $attemptThatFailed === 2 => 300,
            default                 => 900,
        };
    }

    /** Serialize for storage in jobs.payload. */
    public function toPayload(): array
    {
        // get_object_vars() from inside the class sees protected/private too,
        // but callers only see public. We want public-only for portability —
        // a job reconstituted in another process shouldn't depend on private
        // implementation state that might have drifted.
        $ref = new \ReflectionObject($this);
        $out = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $out[$p->getName()] = $p->getValue($this);
        }
        return $out;
    }

    /** Reconstitute from a storage payload. Safe default: set public props. */
    public static function fromPayload(array $payload): static
    {
        /** @var static $job */
        $job = new static();
        $ref = new \ReflectionObject($job);
        foreach ($payload as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                if ($prop->isPublic()) {
                    $prop->setValue($job, $value);
                }
            }
        }
        return $job;
    }
}
