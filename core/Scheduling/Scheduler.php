<?php
// core/Scheduling/Scheduler.php
namespace Core\Scheduling;

use Core\Database\Database;
use Core\Queue\DatabaseQueue;
use Cron\CronExpression;

/**
 * Scheduler — the "promote due rules into jobs" half of the master cycle.
 *
 * One tick per `php artisan schedule:run`:
 *   1. Pick up every scheduled_tasks row where enabled=1 and next_run_at<=NOW.
 *   2. Enqueue a jobs row via DatabaseQueue->pushRaw() using the rule's
 *      class + payload + queue.
 *   3. Recompute next_run_at from schedule_expression via dragonmantank's
 *      CronExpression and update the row.
 *
 * `last_run_at` / `last_run_status` are bookkeeping for admin visibility —
 * they record when the rule most recently enqueued, not when the job actually
 * succeeded (that lives on the jobs row it produced).
 */
class Scheduler
{
    public function __construct(
        private Database $db,
        private DatabaseQueue $queue,
    ) {}

    /**
     * Run one tick. Returns a summary for CLI output.
     *
     * @return array{promoted:int, ids:int[], names:string[]}
     */
    public function tick(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $due = $this->db->fetchAll("
            SELECT id, name, class, payload, schedule_expression, queue, next_run_at
              FROM scheduled_tasks
             WHERE enabled = 1
               AND (next_run_at IS NULL OR next_run_at <= ?)
             ORDER BY id ASC
        ", [$now->format('Y-m-d H:i:s')]);

        $enqueuedJobIds = [];
        $names          = [];
        $skipped        = 0;

        foreach ($due as $task) {
            $payload = json_decode((string) $task['payload'], true);
            if (!is_array($payload)) $payload = [];

            // Phase 43.200a C1 — CAS guard against parallel ticks.
            // Pre-fix two concurrent `schedule:run` invocations (cron
            // + manual operator run, HA worker overlap, slow tick that
            // overlaps the next cron) both SELECTed the same due rows
            // + both enqueued the job. Every scheduled task — system
            // health probes, retry-messages, search reindex,
            // marketing:dispatch-scheduled itself — was vulnerable.
            // Phase 43.189a fixed the marketing sub-scheduler; this
            // closes the core framework scheduler same shape.
            //
            // Pre-CAS the next_run_at advance BEFORE pushing the job.
            // If the CAS rowCount=0, another tick already claimed this
            // row — skip without enqueueing. Status string for the
            // post-work UPDATE later in this iteration becomes a no-op
            // since the row was already updated by the CAS.
            $nextRunAt = $this->computeNextRunAt((string) $task['schedule_expression'], $now);
            $cas = $this->db->update('scheduled_tasks',
                [
                    'last_run_at'     => $now->format('Y-m-d H:i:s'),
                    'next_run_at'     => $nextRunAt?->format('Y-m-d H:i:s'),
                    'last_run_status' => 'claiming',
                ],
                'id = ? AND (next_run_at <=> ?)',
                [(int) $task['id'], $task['next_run_at']]
            );
            if ($cas === 0) {
                $skipped++;
                continue;
            }

            try {
                $jobId = $this->queue->pushRaw(
                    class:   (string) $task['class'],
                    payload: $payload,
                    queue:   (string) ($task['queue'] ?? 'default'),
                );
                $enqueuedJobIds[] = $jobId;
                $names[]          = (string) $task['name'];
                $status           = 'enqueued';
            } catch (\Throwable $e) {
                $status = 'error: ' . $e->getMessage();
            }

            // Update last_run_status with the actual outcome. next_run_at
            // was already advanced atomically by the CAS above.
            $this->db->update('scheduled_tasks',
                ['last_run_status' => substr($status, 0, 32)],
                'id = ?', [(int) $task['id']]
            );
        }

        return [
            'promoted' => count($enqueuedJobIds),
            'ids'      => $enqueuedJobIds,
            'names'    => $names,
        ];
    }

    /**
     * Parse schedule_expression and return the next fire time after $from.
     * Returns null if the expression is invalid — the scheduler logs this
     * via last_run_status but keeps the rule on the books; admin can fix
     * it without data loss.
     */
    public function computeNextRunAt(string $expression, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        if (!class_exists(CronExpression::class)) {
            // Dev fallback: if the lib isn't installed yet, advance by one
            // minute so the rule doesn't immediately refire in a tight loop.
            return $from->modify('+1 minute');
        }

        try {
            $cron = new CronExpression($expression);
            $next = $cron->getNextRunDate(\DateTime::createFromImmutable($from));
            return \DateTimeImmutable::createFromMutable($next);
        } catch (\Throwable) {
            return null;
        }
    }
}
