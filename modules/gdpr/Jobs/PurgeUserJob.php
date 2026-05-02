<?php
// modules/gdpr/Jobs/PurgeUserJob.php
namespace Modules\Gdpr\Jobs;

use Core\Database\Database;
use Core\Queue\Job;
use Modules\Gdpr\Services\DataPurger;

/**
 * Scheduled hourly. Walks the users table for any account whose
 * deletion grace window has elapsed (`deletion_grace_until` is past
 * AND `deleted_at` is still NULL) and runs DataPurger on each one.
 *
 * Dispatching path:
 *   1. User clicks "Delete my account" on /account/data → row gets
 *      deletion_requested_at + deletion_grace_until + deletion_token.
 *   2. Cron fires `php artisan schedule:run` hourly; this job runs
 *      and purges anyone past their grace window.
 *   3. Each purge wraps in a DB transaction inside DataPurger and
 *      writes a `gdpr.user_erased` audit_log entry on success.
 */
class PurgeUserJob extends Job
{
    public function handle(): void
    {
        $db  = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        $rows = $db->fetchAll("
            SELECT id, username, email
            FROM users
            WHERE deletion_grace_until IS NOT NULL
              AND deletion_grace_until <= ?
              AND deleted_at IS NULL
            LIMIT 50
        ", [$now]);

        if (empty($rows)) return;

        $purger = new DataPurger();
        foreach ($rows as $r) {
            $userId = (int) $r['id'];
            try {
                $stats = $purger->purge($userId, null);
                error_log(sprintf(
                    '[gdpr] purged user #%d (%s) — erased=%d anonymised=%d kept=%d',
                    $userId, $r['email'],
                    $stats['tables_erased'], $stats['tables_anonymized'], $stats['tables_kept']
                ));

                // Mark any open erasure DSAR as completed.
                $db->query("
                    UPDATE dsar_requests
                       SET status='completed',
                           completed_at=NOW(),
                           notes=CONCAT(COALESCE(notes,''), '\nAuto-completed by PurgeUserJob.')
                     WHERE user_id = ?
                       AND kind = 'erasure'
                       AND status IN ('pending','verified','in_progress')
                ", [$userId]);

            } catch (\Throwable $e) {
                error_log('[gdpr] purge failed for user #' . $userId . ': ' . $e->getMessage());
                // Don't re-throw — one bad row shouldn't block the rest
                // of the batch. The grace_until column stays past, so
                // the next run will retry.
            }
        }
    }
}
