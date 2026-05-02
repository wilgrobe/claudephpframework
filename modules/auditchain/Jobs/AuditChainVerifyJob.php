<?php
// modules/auditchain/Jobs/AuditChainVerifyJob.php
namespace Modules\Auditchain\Jobs;

use Core\Database\Database;
use Core\Queue\Job;
use Core\Services\NotificationService;
use Modules\Auditchain\Services\AuditChainService;

/**
 * Daily verification job. Verifies the most recent N days of the
 * audit-chain. Recently-written days are the highest-value scope
 * for daily verification — older days don't change unless a tamper
 * happened, and a focused verify catches that fast.
 *
 * Deeper retroactive sweeps (e.g. "verify the last year") are
 * available via the admin UI's on-demand button.
 *
 * On detected breaks: dispatches a `notifications` row to every
 * superadmin with the count of new breaks. The break detail lives
 * on /admin/audit-chain.
 */
class AuditChainVerifyJob extends Job
{
    /** Default look-back window for the daily verify. */
    public const LOOKBACK_DAYS = 7;

    public function handle(): void
    {
        $svc = new AuditChainService();
        $db  = Database::getInstance();

        $dayTo   = date('Y-m-d');
        $dayFrom = date('Y-m-d', time() - self::LOOKBACK_DAYS * 86400);

        try {
            $result = $svc->verifyRange($dayFrom, $dayTo, null);
        } catch (\Throwable $e) {
            error_log('[auditchain] verify failed: ' . $e->getMessage());
            return;
        }

        $msg = sprintf(
            '[auditchain] verified %s..%s — %d rows, %d breaks, %dms',
            $dayFrom, $dayTo,
            $result['rows'], $result['breaks'], $result['duration_ms']
        );
        error_log($msg);

        if ($result['breaks'] > 0) {
            $this->notifyAdmins($result['breaks']);
        }
    }

    private function notifyAdmins(int $breakCount): void
    {
        try {
            $db = Database::getInstance();
            $admins = $db->fetchAll("SELECT id FROM users WHERE is_superadmin = 1 AND is_active = 1");
            $notify = new NotificationService();
            foreach ($admins as $a) {
                $notify->send(
                    (int) $a['id'],
                    'auditchain.break_detected',
                    'Audit-chain break detected',
                    "Daily verification found {$breakCount} new chain break(s). Review at /admin/audit-chain.",
                    ['breaks' => $breakCount, 'action_url' => '/admin/audit-chain'],
                    'in_app,email'
                );
            }
        } catch (\Throwable $e) {
            error_log('[auditchain] admin notify failed: ' . $e->getMessage());
        }
    }
}
