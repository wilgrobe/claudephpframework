<?php
// core/Queue/DatabaseQueue.php
namespace Core\Queue;

use Core\Database\Database;

/**
 * DatabaseQueue — push/reserve/complete jobs in the `jobs` table.
 *
 * Storage pattern (see the create_jobs_table migration):
 *   - Rows start status='pending', available_at=NOW (or a future backoff time).
 *   - reserve() atomically claims a batch by stamping reserved_by/reserved_at
 *     so parallel workers can't double-claim.
 *   - complete() / fail() finalise the row. Transient failure calls release()
 *     with a backoff, which sets status back to 'pending' and pushes out
 *     available_at.
 *
 * Not a SearchEngine-style Contract yet — we'll introduce Core\Contracts\Queue
 * when/if a Redis driver lands. Keep this file the single place that writes to
 * `jobs` so that refactor is a one-file swap.
 */
class DatabaseQueue
{
    public function __construct(private Database $db) {}

    // ── Dispatch ──────────────────────────────────────────────────────────

    /** Push a Job instance onto its declared queue. Returns the new job id. */
    public function push(Job $job, ?\DateTimeImmutable $availableAt = null): int
    {
        return $this->pushRaw(
            class:        $job::class,
            payload:      $job->toPayload(),
            queue:        $job->queue(),
            maxAttempts:  $job->maxAttempts(),
            availableAt:  $availableAt,
        );
    }

    /**
     * Push by class name + payload. Used by the scheduler, which has the
     * declarative config but doesn't materialize the Job object itself.
     */
    public function pushRaw(
        string $class,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?\DateTimeImmutable $availableAt = null,
    ): int {
        return $this->db->insert('jobs', [
            'queue'        => $queue,
            'class'        => $class,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => ($availableAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    // ── Dequeue ───────────────────────────────────────────────────────────

    /**
     * Atomically claim up to $limit jobs for this worker.
     *
     * Two queries total via a unique per-call reservation tag:
     *   1. UPDATE ... SET reserved_by = <tag> ... ORDER BY id LIMIT N
     *      — stamps up to N unreserved rows with a tag only this worker+call
     *        will ever use (worker id + monotonic counter + random bytes).
     *        MySQL's row locking inside UPDATE prevents double-claim across
     *        concurrent workers.
     *   2. SELECT * WHERE reserved_by = <tag>  — fetches exactly what we won.
     *
     * The tag is also what stays in reserved_by for the job's lifetime, so
     * forensic queries ("which worker handled job #123?") still work —
     * tag format is "<workerId>#<sequence>" so the worker prefix is intact.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reserve(string $workerId, string $queue = 'default', int $limit = 10): array
    {
        $tag    = $workerId . '#' . $this->nextReservationSequence();
        $nowStr = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // One UPDATE claims the whole batch. ORDER BY id ASC preserves FIFO
        // across the queue even when multiple workers are active (combined
        // with the idx_ready index, this is a range scan of ~$limit rows).
        $this->db->query(
            "UPDATE jobs
                SET status      = 'running',
                    reserved_by = ?,
                    reserved_at = ?,
                    attempts    = attempts + 1
              WHERE status      = 'pending'
                AND queue       = ?
                AND available_at <= NOW()
                AND reserved_by IS NULL
              ORDER BY id ASC
              LIMIT ?",
            [$tag, $nowStr, $queue, $limit]
        );

        // Fetch just what we won. Concurrent workers hold different tags,
        // so there's no cross-talk here.
        return $this->db->fetchAll(
            "SELECT * FROM jobs WHERE reserved_by = ? ORDER BY id ASC",
            [$tag]
        );
    }

    /** Monotonic counter so a single long-running worker can't produce
     *  duplicate reservation tags across calls. Scoped to the PHP process. */
    private static int $reservationSeq = 0;
    private function nextReservationSequence(): string
    {
        self::$reservationSeq++;
        // Randomness guards against two workers that happen to share hostname
        // AND pid AND sequence counter (extremely unlikely, but cheap to defend).
        return self::$reservationSeq . '.' . bin2hex(random_bytes(4));
    }

    // ── Finalise ──────────────────────────────────────────────────────────

    public function complete(int $jobId): void
    {
        $this->db->update('jobs',
            [
                'status'       => 'completed',
                'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_error'   => null,
                'reserved_by'  => null,
            ],
            'id = ?', [$jobId]
        );
    }

    /**
     * Transient failure — release back to pending with a future available_at.
     * If attempts have hit max_attempts, fail() instead (caller decides).
     */
    public function release(int $jobId, \DateTimeImmutable $availableAt, string $error): void
    {
        $this->db->update('jobs',
            [
                'status'       => 'pending',
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'last_error'   => $this->truncate($error),
                'reserved_by'  => null,
                'reserved_at'  => null,
            ],
            'id = ?', [$jobId]
        );
    }

    /** Terminal failure — status=failed, keep last_error for forensics. */
    public function fail(int $jobId, string $error): void
    {
        $this->db->update('jobs',
            [
                'status'      => 'failed',
                'last_error'  => $this->truncate($error),
                'reserved_by' => null,
            ],
            'id = ?', [$jobId]
        );
    }

    // ── Admin helpers (for queue:list / queue:retry) ──────────────────────

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 20, ?string $status = null): array
    {
        if ($status !== null) {
            return $this->db->fetchAll(
                "SELECT id, queue, class, status, attempts, max_attempts,
                        available_at, reserved_by, last_error, created_at
                   FROM jobs WHERE status = ? ORDER BY id DESC LIMIT ?",
                [$status, $limit]
            );
        }
        return $this->db->fetchAll(
            "SELECT id, queue, class, status, attempts, max_attempts,
                    available_at, reserved_by, last_error, created_at
               FROM jobs ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }

    /** Reset a failed job to pending (manual admin retry). */
    public function retry(int $jobId): bool
    {
        $affected = $this->db->update('jobs',
            [
                'status'       => 'pending',
                'attempts'     => 0,
                'available_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'reserved_by'  => null,
                'reserved_at'  => null,
                'last_error'   => null,
            ],
            'id = ? AND status = ?', [$jobId, 'failed']
        );
        return $affected === 1;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** Errors can be huge (stack traces); cap at TEXT-safe size. */
    private function truncate(string $s, int $max = 60000): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 20) . "\n...[truncated]" : $s;
    }
}
