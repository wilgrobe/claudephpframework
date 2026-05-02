<?php
// modules/retention/Jobs/RetentionSweepJob.php
namespace Modules\Retention\Jobs;

use Core\Database\Database;
use Core\Queue\Job;
use Modules\Retention\Services\RetentionService;

/**
 * Daily retention sweep. Walks every enabled rule in retention_rules
 * and applies it. Logs an audit_log row on completion with totals.
 *
 * Wire into the scheduler with:
 *   php artisan schedule:create retention.sweep daily
 *
 * (or set up an admin-facing scheduling UI dispatch on /admin/scheduling).
 *
 * Self-throttled by RetentionService::MAX_CHUNKS_PER_RUN (100k rows
 * per rule per execution) so a backlog doesn't block the queue worker
 * for hours. Subsequent days will catch up.
 */
class RetentionSweepJob extends Job
{
    public function handle(): void
    {
        $svc   = new RetentionService();
        $stats = $svc->runAll(null, false);

        $msg = sprintf(
            '[retention] sweep complete: %d rules run, %d rows affected, %d errors',
            $stats['rules_run'], $stats['rows_affected'], $stats['errors']
        );
        error_log($msg);

        try {
            $db  = Database::getInstance();
            $row = [
                'actor_user_id' => null,
                'action'        => 'retention.sweep',
                'model'         => null,
                'model_id'      => null,
                'old_values'    => null,
                'new_values'    => json_encode($stats),
                'ip_address'    => null,
                'user_agent'    => 'cron',
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            if (class_exists(\Modules\Auditchain\Services\AuditChainService::class)) {
                (new \Modules\Auditchain\Services\AuditChainService($db))
                    ->sealAndInsert($db, $row);
            } else {
                $db->insert('audit_log', $row);
            }
        } catch (\Throwable $e) {
            error_log('[retention] failed to write audit row: ' . $e->getMessage());
        }
    }
}
