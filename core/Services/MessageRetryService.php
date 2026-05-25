<?php
// core/Services/MessageRetryService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * MessageRetryService — drains the retry queue in message_log.
 *
 * A row is eligible for retry when:
 *   - status = 'failed'
 *   - attempts < max_attempts
 *   - next_attempt_at IS NULL OR next_attempt_at <= NOW()
 *
 * The actual send + backoff scheduling is handled inside MailService::resend(),
 * SmsService::resend(), and WebhookService::resend(); this service only picks
 * rows and dispatches by channel.
 *
 * Called from three places:
 *   1. Opportunistically at the end of each web request (public/index.php).
 *      Limited to a few rows per request with a probability gate so normal
 *      page loads aren't slowed down.
 *   2. Manually from the Superadmin message log UI ("Retry" button).
 *   3. CLI: `php artisan retry-messages`.
 */
class MessageRetryService
{
    private Database       $db;
    private MailService    $mail;
    private SmsService     $sms;
    private WebhookService $webhook;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->mail    = new MailService();
        $this->sms     = new SmsService();
        $this->webhook = new WebhookService();
    }

    /**
     * Pick up eligible rows and re-send them.
     *
     * @param int $limit max rows to process this pass
     * @return array{picked:int, succeeded:int, failed:int, ids:int[]}
     */
    public function run(int $limit = 10): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, channel
               FROM message_log
              WHERE status = 'failed'
                AND attempts < max_attempts
                AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
              ORDER BY next_attempt_at IS NULL DESC, next_attempt_at ASC, id ASC
              LIMIT ?",
            [$limit]
        );

        $picked = count($rows);
        $ok = $fail = 0;
        $ids = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $ids[] = $id;

            // Phase 43.201a C2 — only resend when the CAS actually won.
            // Pre-fix the UPDATE was issued but its rowCount was ignored;
            // a parallel cron tick + opportunistic mid-request drain would
            // both SELECT the same row, both attempt the CAS, and both
            // call resend() even though only one CAS actually flipped
            // status='failed' to 'queued'. Result: subscriber received
            // the email twice (and the message_log row was finalised by
            // whichever resend completed last).
            $claimed = $this->db->update('message_log',
                ['status' => 'queued'],
                'id = ? AND status = ?', [$id, 'failed']
            );
            if ($claimed === 0) {
                // Another runner already claimed this row this tick.
                continue;
            }

            $success = match ($row['channel']) {
                'email'   => $this->mail->resend($id),
                'sms'     => $this->sms->resend($id),
                'webhook' => $this->webhook->resend($id),
                default   => false,
            };

            $success ? $ok++ : $fail++;
        }

        return ['picked' => $picked, 'succeeded' => $ok, 'failed' => $fail, 'ids' => $ids];
    }

    /**
     * Force-retry a single row regardless of backoff schedule.
     * Used by the admin UI's "Retry" button.
     *
     * If the row has already hit max_attempts, this resets next_attempt_at
     * so attemptSend() can proceed, but does NOT change max_attempts — so
     * attempts will immediately equal max_attempts again after the call and
     * no further automatic retries will fire. The admin can click Retry
     * again if they want another shot.
     */
    public function retryOne(int $logId): bool
    {
        $row = $this->db->fetchOne("SELECT channel, status FROM message_log WHERE id = ?", [$logId]);
        if (!$row) return false;
        if ($row['status'] === 'sent') return true;

        // Phase 43.202b H4 — CAS-guarded claim. Pre-fix the admin
        // "Retry" path did an unconditional UPDATE then unconditionally
        // called resend(). Admin double-clicking Retry, OR admin Retry
        // racing a `run()` cron tick that just picked the row from its
        // eligible-set query, both fired resend() → subscriber
        // received email twice. Same fix as 43.201a C2 applied to
        // run() — admin path was missed. Now: CAS-guard the claim
        // against the row's current status; return false on race.
        $claimed = $this->db->update('message_log',
            ['status' => 'queued', 'next_attempt_at' => null],
            'id = ? AND status = ?', [$logId, $row['status']]
        );
        if ($claimed === 0) return false;

        return match ($row['channel']) {
            'email'   => $this->mail->resend($logId),
            'sms'     => $this->sms->resend($logId),
            'webhook' => $this->webhook->resend($logId),
            default   => false,
        };
    }
}
