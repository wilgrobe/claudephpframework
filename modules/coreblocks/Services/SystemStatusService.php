<?php
// modules/coreblocks/Services/SystemStatusService.php
namespace Modules\Coreblocks\Services;

use Core\Database\Database;

/**
 * SystemStatusService — composite health probes for the
 * coreblocks.system_status block.
 *
 * Each probe returns ['ok' => bool, 'note' => string]. Probes are
 * cheap reads against tables that already exist; no remote calls,
 * no API keys. If a probe needs a more thorough check (TLS handshake
 * to SMTP, end-to-end queue dispatch) wire that in via a dedicated
 * health-check job and have the probe consume the cached result.
 *
 * Per-probe try/catch keeps a single broken signal from breaking the
 * whole tile — admins want to see "DB ✓ / queue ✓ / mail ✗" not
 * "page 500'd because mail probe threw."
 */
class SystemStatusService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Run every probe and return a structured status snapshot.
     *
     * @return array{
     *     all_ok: bool,
     *     probes: array<string, array{ok: bool, note: string}>,
     * }
     */
    public function snapshot(): array
    {
        $probes = [
            'database' => $this->probeDatabase(),
            'queue'    => $this->probeQueue(),
            'mail'     => $this->probeMail(),
            'sessions' => $this->probeSessions(),
        ];
        $allOk = true;
        foreach ($probes as $p) if (!$p['ok']) { $allOk = false; break; }
        return ['all_ok' => $allOk, 'probes' => $probes];
    }

    /** @return array{ok: bool, note: string} */
    private function probeDatabase(): array
    {
        try {
            $this->db->fetchColumn("SELECT 1");
            return ['ok' => true, 'note' => 'connected'];
        } catch (\Throwable $e) {
            return $this->probeError('connection', $e);
        }
    }

    /**
     * Format a thrown exception into a short admin-readable note for
     * the system-status tile. The block this drives is admin-only so
     * leaking SQL state / column names / file paths to the rendered
     * note is fine — admins already have full access to the codebase.
     *
     * Truncates to 80 chars so a long stack-trace string doesn't push
     * the row out of its column. Strips newlines because the note is
     * rendered inline.
     *
     * @return array{ok: bool, note: string}
     */
    private function probeError(string $label, \Throwable $e): array
    {
        $msg = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        if ($msg === '') $msg = $e::class;
        if (mb_strlen($msg) > 80) $msg = mb_substr($msg, 0, 77) . '…';
        return ['ok' => false, 'note' => "$label probe error: $msg"];
    }

    /**
     * Queue health: any jobs in 'failed' state with no scheduled retry,
     * OR no scheduled task has run in the last 10 minutes (suggesting
     * `php artisan schedule:run` isn't being invoked). Either condition
     * trips the probe.
     */
    private function probeQueue(): array
    {
        try {
            $stuckJobs = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM jobs
                  WHERE status = 'failed' AND attempts >= max_attempts"
            );
            if ($stuckJobs > 0) {
                return ['ok' => false, 'note' => "$stuckJobs job" . ($stuckJobs === 1 ? '' : 's') . ' stuck in failed'];
            }

            // If scheduled_tasks table exists, check the most recent
            // last_run_at. Older than 10 minutes implies the scheduler
            // isn't being invoked (cron/systemd timer down).
            $hasSchedTable = (bool) $this->db->fetchColumn(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'scheduled_tasks' LIMIT 1"
            );
            if ($hasSchedTable) {
                // Column is `enabled` (not `active`) — matches the
                // scheduler's own dispatcher predicate.
                $lastRun = $this->db->fetchColumn(
                    "SELECT MAX(last_run_at) FROM scheduled_tasks WHERE enabled = 1"
                );
                if ($lastRun !== null && strtotime((string) $lastRun) < (time() - 600)) {
                    $age = (int) round((time() - strtotime((string) $lastRun)) / 60);
                    return ['ok' => false, 'note' => "scheduler idle for {$age}m"];
                }
            }
            return ['ok' => true, 'note' => 'no failures'];
        } catch (\Throwable $e) {
            return $this->probeError('queue', $e);
        }
    }

    /**
     * Mail health: any rows in message_log with status='failed' AND
     * attempts >= max_attempts (terminal, no retry coming) within the
     * last 24 hours. A handful of failures during normal operation
     * isn't a red flag; the probe picks up sustained breakage.
     */
    private function probeMail(): array
    {
        try {
            $recentFailures = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM message_log
                  WHERE channel = 'email'
                    AND status = 'failed'
                    AND attempts >= max_attempts
                    AND last_attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            if ($recentFailures > 0) {
                return ['ok' => false, 'note' => "$recentFailures failed send" . ($recentFailures === 1 ? '' : 's') . ' in 24h'];
            }
            return ['ok' => true, 'note' => 'no recent failures'];
        } catch (\Throwable $e) {
            return $this->probeError('mail', $e);
        }
    }

    /**
     * Sessions health: row count in the sessions table. A non-zero count
     * confirms the DB-backed session handler is writing rows; a zero
     * count on a populated site means the session handler is broken
     * (handler not registered, table missing, write permission denied).
     */
    private function probeSessions(): array
    {
        try {
            $rows = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM sessions");
            if ($rows === 0) {
                return ['ok' => false, 'note' => 'sessions table empty'];
            }
            return ['ok' => true, 'note' => "$rows row" . ($rows === 1 ? '' : 's')];
        } catch (\Throwable $e) {
            return $this->probeError('sessions', $e);
        }
    }
}
