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
 *   - complete() / fail() finalize the row. Transient failure calls release()
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

    // ── Finalize ──────────────────────────────────────────────────────────

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

    /**
     * Phase 43.95 — delete a failed job permanently (operator discard).
     * Refuses to delete pending/running/completed jobs so an admin
     * misfire can't accidentally cancel an in-flight job. Returns true
     * on successful delete.
     */
    public function deleteFailed(int $jobId): bool
    {
        $affected = $this->db->delete('jobs', 'id = ? AND status = ?', [$jobId, 'failed']);
        return $affected === 1;
    }

    /**
     * Phase 43.95 — full row read for the admin detail view. Includes
     * the JSON payload + full last_error (not the recent() summary).
     * Returns null when the job doesn't exist.
     */
    public function findFull(int $jobId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, queue, class, payload, status, attempts, max_attempts,
                    available_at, reserved_at, reserved_by, last_error,
                    completed_at, created_at
               FROM jobs WHERE id = ? LIMIT 1",
            [$jobId]
        );
        return $row ?: null;
    }

    /**
     * Phase 43.96 — bulk retry every failed job. Atomic — one UPDATE
     * covers the whole set so partial-progress can't leave half the
     * jobs in inconsistent state. Returns affected row count.
     */
    public function retryAllFailed(): int
    {
        return $this->db->update('jobs',
            [
                'status'       => 'pending',
                'attempts'     => 0,
                'available_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'reserved_by'  => null,
                'reserved_at'  => null,
                'last_error'   => null,
            ],
            'status = ?', ['failed']
        );
    }

    /**
     * Phase 43.96 — bulk delete every failed job. Destructive; caller
     * (admin UI) is responsible for the confirm() dialog. Returns
     * deleted row count.
     */
    public function deleteAllFailed(): int
    {
        return $this->db->delete('jobs', 'status = ?', ['failed']);
    }

    /**
     * Phase 43.109 — scoped bulk variants: only failed jobs whose class
     * matches $class. Operator triage pattern: pattern detector flagged
     * 90% of failures share a class; retry/delete just those without
     * touching the legit transient errors of other classes.
     */
    public function retryAllFailedByClass(string $class): int
    {
        if ($class === '') return 0;
        return $this->db->update('jobs',
            [
                'status'       => 'pending',
                'attempts'     => 0,
                'available_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'reserved_by'  => null,
                'reserved_at'  => null,
                'last_error'   => null,
            ],
            'status = ? AND class = ?', ['failed', $class]
        );
    }

    public function deleteAllFailedByClass(string $class): int
    {
        if ($class === '') return 0;
        return $this->db->delete('jobs', 'status = ? AND class = ?', ['failed', $class]);
    }

    /**
     * Phase 43.100 — release reserved-but-idle-too-long jobs back to
     * pending so a crashed/killed worker doesn't leave them stuck
     * forever. The health check already surfaces the count; this is
     * the actuator that fixes it.
     *
     * `$minutesIdle` matches the threshold SystemHealthChecker uses
     * (10 minutes default) so the operator sees the same set the
     * health page warned about.
     *
     * Returns released row count.
     */
    public function recoverStaleReserved(int $minutesIdle = 10): int
    {
        $minutesIdle = max(1, $minutesIdle);
        // Phase 43.200b H4 — decrement attempts when releasing stale-
        // reserved rows. Pre-fix the worker that picked the job up
        // already bumped attempts on reserve(); if killed mid-batch
        // (SIGTERM/OOM/deploy) attempts incremented without ever
        // calling handle(). After 10 min recovery the row came back
        // to pending but its attempts counter was 1 of 3 without
        // any execution. Two unlucky deploys in 30 min could burn
        // the entire retry budget on a job that never ran. Now:
        // GREATEST(0, attempts - 1) since the prior bump was phantom.
        //
        // NB: in-flight jobs use status='running' (set by reserve()),
        // not 'reserved' — the framework's status enum has no
        // 'reserved' value.
        $stmt = $this->db->pdo()->prepare(
            "UPDATE jobs
                SET status       = 'pending',
                    reserved_by  = NULL,
                    reserved_at  = NULL,
                    available_at = NOW(),
                    attempts     = GREATEST(0, attempts - 1)
              WHERE status = 'running'
                AND reserved_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$minutesIdle]);
        return $stmt->rowCount();
    }

    /**
     * Phase 43.100 — count stale-reserved jobs (preview before
     * calling recoverStaleReserved). Same WHERE as the recovery so
     * the count matches what gets released.
     */
    public function countStaleReserved(int $minutesIdle = 10): int
    {
        $minutesIdle = max(1, $minutesIdle);
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM jobs WHERE status = 'running' AND reserved_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutesIdle]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Phase 43.95 — counts by status for the admin queue header. One
     * query, four numbers; cheap on the existing PK + status index.
     */
    public function statusCounts(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS n FROM jobs GROUP BY status"
        );
        $out = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** Errors can be huge (stack traces); cap at TEXT-safe size. */
    private function truncate(string $s, int $max = 60000): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 20) . "\n...[truncated]" : $s;
    }
}
