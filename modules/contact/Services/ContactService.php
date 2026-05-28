<?php
// modules/contact/Services/ContactService.php
namespace Modules\Contact\Services;

use Core\Auth\RateLimiter;
use Core\Database\Database;
use Core\Services\MailService;
use Core\Services\SettingsService;

/**
 * Default contact form for every framework install — closes the long-
 * standing "scrape-bait contact page" problem where the seeded contact
 * page exposes emails / phones / social handles directly to spammers.
 *
 * Public submit path:
 *   1) Validate (required + length caps + email format)
 *   2) Honeypot check (hidden field MUST be empty)
 *   3) Min-time check (form must have been on the page ≥3 seconds —
 *      legit users always take longer; bots POST in milliseconds)
 *   4) Per-IP rate limit via the existing core RateLimiter
 *   5) Insert into contact_messages
 *   6) Notify recipients via MailService (configurable; see settings)
 *   7) Optional autoreply to submitter
 *
 * Admin queue lifecycle: new → read → replied → archived.
 *
 * Settings (all read via SettingsService 'site' scope; defaults set
 * in the create_contact_messages migration):
 *   contact_form_enabled        bool   master switch
 *   contact_notify_enabled      bool   notify recipients on new submit
 *   contact_recipient_emails    string comma-separated; falls back to site.contact_email
 *   contact_autoreply_enabled   bool   send confirmation to submitter
 *   contact_autoreply_body      string body of autoreply (or '' = use default)
 *   contact_rate_limit_per_hour int    informational; actual limiter is the core 5/10/15min envelope
 *   contact_min_seconds         int    minimum time on page before submit accepted
 */
final class ContactService
{
    public const REASON_HONEYPOT      = 'honeypot';
    public const REASON_TOO_FAST      = 'too_fast';
    public const REASON_RATE_LIMITED  = 'rate_limited';
    public const REASON_DISABLED      = 'disabled';
    public const REASON_VALIDATION    = 'validation';

    private const MAX_NAME      = 200;
    private const MAX_EMAIL     = 254;
    private const MAX_PHONE     = 40;
    private const MAX_SUBJECT   = 255;
    private const MAX_BODY      = 8000;
    private const RATE_KEY      = 'contact-form';

    /**
     * Validate + insert + notify. Returns:
     *   {ok: true, id: int} on success
     *   {ok: false, reason: string, errors?: array} on rejection
     */
    public static function submit(array $payload, string $ip, string $userAgent): array
    {
        // Master switch — if admin disabled the form entirely, refuse.
        if (!self::settingBool('contact_form_enabled', true)) {
            return ['ok' => false, 'reason' => self::REASON_DISABLED];
        }

        // 1) Honeypot — the form ships with a hidden field that legit
        //    users never see. Bots that auto-fill every input fail it.
        $honeypot = trim((string) ($payload['website'] ?? ''));
        if ($honeypot !== '') {
            error_log('[Contact] honeypot tripped from ip=' . $ip);
            // Lie to bots — return success so they don't retry with
            // different field names. Don't insert anything.
            return ['ok' => true, 'id' => 0, 'silent_drop' => true];
        }

        // 2) Min-time check — form embeds a timestamp at render; if the
        //    POST arrives less than N seconds later, treat as a bot.
        $minSeconds   = max(0, self::settingInt('contact_min_seconds', 3));
        $renderedAt   = (int) ($payload['rendered_at'] ?? 0);
        if ($minSeconds > 0 && $renderedAt > 0) {
            $age = time() - $renderedAt;
            if ($age < $minSeconds) {
                error_log("[Contact] too-fast submit from ip={$ip} age={$age}s");
                return ['ok' => false, 'reason' => self::REASON_TOO_FAST];
            }
        }

        // 3) Per-IP rate limit. Existing core limiter is keyed by
        //    (action, ip); we use action='contact-form'. Hits the
        //    same backoff envelope as auth (5 → backoff, 10 → 15min
        //    lockout) which is sane for a contact form.
        try {
            $limiter = new RateLimiter();
            if ($limiter->tooManyAttempts(self::RATE_KEY, $ip)) {
                return ['ok' => false, 'reason' => self::REASON_RATE_LIMITED];
            }
        } catch (\Throwable $e) {
            error_log('[Contact] rate limiter unavailable: ' . $e->getMessage());
            // Fail open — better to accept than refuse silently when
            // the limiter table is missing.
        }

        // 4) Validation.
        $name    = trim((string) ($payload['name'] ?? ''));
        $email   = trim((string) ($payload['email'] ?? ''));
        $phone   = trim((string) ($payload['phone'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body    = trim((string) ($payload['body'] ?? $payload['message'] ?? ''));

        $errors = [];
        if ($name === '')                           $errors['name']    = 'Please tell us your name.';
        if ($email === '')                          $errors['email']   = 'Please share an email so we can reply.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'That email address looks invalid.';
        if ($body === '')                           $errors['body']    = 'Please add a message.';

        if (mb_strlen($name)    > self::MAX_NAME)    $errors['name']    = "Keep it under " . self::MAX_NAME . " characters.";
        if (mb_strlen($email)   > self::MAX_EMAIL)   $errors['email']   = "Email is too long.";
        if (mb_strlen($phone)   > self::MAX_PHONE)   $errors['phone']   = "Phone is too long.";
        if (mb_strlen($subject) > self::MAX_SUBJECT) $errors['subject'] = "Keep the subject under " . self::MAX_SUBJECT . " characters.";
        if (mb_strlen($body)    > self::MAX_BODY)    $errors['body']    = "Keep your message under " . self::MAX_BODY . " characters.";

        if (!empty($errors)) {
            // Record the rate-limit hit so a bot probing validators
            // doesn't get unlimited tries.
            try { (new RateLimiter())->hit(self::RATE_KEY, $ip); } catch (\Throwable) {}
            return ['ok' => false, 'reason' => self::REASON_VALIDATION, 'errors' => $errors];
        }

        // 5) Insert.
        $db = Database::getInstance();
        $id = $db->insert('contact_messages', [
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone === '' ? null : $phone,
            'subject'    => $subject === '' ? null : $subject,
            'body'       => $body,
            'ip'         => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'status'     => 'new',
        ]);

        // Record the rate-limit hit after success too — limiter
        // tracks attempts, success or fail.
        try { (new RateLimiter())->hit(self::RATE_KEY, $ip); } catch (\Throwable) {}

        // 6) Notify recipients (best-effort — a mail failure must
        //    not roll back the insert; the message is in the DB).
        if (self::settingBool('contact_notify_enabled', true)) {
            self::notifyRecipients($id, $name, $email, $phone, $subject, $body);
        }

        // 7) Optional autoreply to submitter.
        if (self::settingBool('contact_autoreply_enabled', false)) {
            self::sendAutoreply($email, $name);
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Notify each configured recipient. Recipients list:
     *   - settings.contact_recipient_emails (comma-separated) WINS
     *   - settings.contact_email falls back when the list is empty
     * Multiple recipients each get a separate email so reply-all
     * doesn't accidentally cc admins back to the submitter.
     */
    private static function notifyRecipients(
        int $id, string $name, string $email,
        ?string $phone, ?string $subject, string $body
    ): void {
        $recipients = self::resolveRecipients();
        if (empty($recipients)) {
            error_log('[Contact] notify skipped — no recipients configured (set settings.contact_recipient_emails or site.contact_email)');
            return;
        }

        $siteName = (string) self::setting('site_name', 'Your site');
        $emailSubject = '[Contact] ' . ($subject !== null && $subject !== '' ? $subject : 'New message from ' . $name);
        $textBody = self::buildNotifyText($id, $name, $email, $phone, $subject, $body, $siteName);
        $htmlBody = self::buildNotifyHtml($id, $name, $email, $phone, $subject, $body, $siteName);

        try {
            $mail = new MailService();
            foreach ($recipients as $to) {
                try {
                    $mail->send($to, $emailSubject, $htmlBody, $textBody);
                } catch (\Throwable $e) {
                    error_log("[Contact] notify failed for {$to}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('[Contact] MailService unavailable: ' . $e->getMessage());
        }
    }

    private static function sendAutoreply(string $submitterEmail, string $submitterName): void
    {
        $siteName = (string) self::setting('site_name', 'Your site');
        $body = trim((string) self::setting('contact_autoreply_body', ''));
        if ($body === '') {
            $body = "Hi {$submitterName},\n\nThanks for reaching out to {$siteName}. We received your message and will reply within two business days.\n\n— The {$siteName} team";
        } else {
            // Allow simple template tokens for the operator's custom body
            $body = str_replace(
                ['{name}', '{site_name}'],
                [$submitterName, $siteName],
                $body
            );
        }

        try {
            $mail = new MailService();
            $mail->send(
                $submitterEmail,
                "We got your message — {$siteName}",
                nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $body
            );
        } catch (\Throwable $e) {
            error_log('[Contact] autoreply failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve effective recipient list — explicit list overrides the
     * legacy site.contact_email single-address fallback. Validates
     * each address; bad entries log + drop silently.
     */
    public static function resolveRecipients(): array
    {
        $list = trim((string) self::setting('contact_recipient_emails', ''));
        if ($list === '') {
            // Fallback to the legacy single-address site setting that
            // ships with the framework's baseline data.
            $legacy = trim((string) self::setting('contact_email', ''));
            $list = $legacy;
        }
        if ($list === '') return [];

        $candidates = preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = [];
        foreach ($candidates as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $addr;
            } else {
                error_log("[Contact] dropped invalid recipient address: {$addr}");
            }
        }
        return array_values(array_unique($valid));
    }

    // ─────────────────────────────────────────────────────────────
    // Admin queue helpers
    // ─────────────────────────────────────────────────────────────

    public static function listForAdmin(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR body LIKE ?)';
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }
        $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $db = Database::getInstance();
        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM contact_messages {$clause}",
            $params
        );

        // LIMIT + OFFSET need PDO::PARAM_INT binding — emulated prepares
        // string-cast otherwise + MySQL syntax-errors on quoted ints.
        // Drop to the raw PDO for this one query.
        $sql = "SELECT id, name, email, phone, subject, body, ip, status,
                       created_at, read_at, replied_at, archived_at
                FROM contact_messages
                {$clause}
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?";
        $list = $db->pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $list->bindValue($i++, $p);
        }
        $list->bindValue($i++, $perPage, \PDO::PARAM_INT);
        $list->bindValue($i++, $offset, \PDO::PARAM_INT);
        $list->execute();

        return ['rows' => $list->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    public static function statusCounts(): array
    {
        $rows = Database::getInstance()->fetchAll("
            SELECT status, COUNT(*) AS n
            FROM contact_messages
            GROUP BY status
        ");
        $counts = ['new' => 0, 'read' => 0, 'replied' => 0, 'archived' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    public static function get(int $id): ?array
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM contact_messages WHERE id = ?",
            [$id]
        );
        return $row ?: null;
    }

    public static function markRead(int $id): bool
    {
        $stmt = Database::getInstance()->query(
            "UPDATE contact_messages
             SET status = 'read', read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND status = 'new'",
            [$id]
        );
        return $stmt->rowCount() > 0;
    }

    public static function markReplied(int $id): bool
    {
        Database::getInstance()->query(
            "UPDATE contact_messages
             SET status = 'replied', replied_at = NOW(),
                 read_at = COALESCE(read_at, NOW())
             WHERE id = ?",
            [$id]
        );
        return true;
    }

    public static function archive(int $id): bool
    {
        Database::getInstance()->query(
            "UPDATE contact_messages
             SET status = 'archived', archived_at = NOW()
             WHERE id = ?",
            [$id]
        );
        return true;
    }

    public static function delete(int $id): bool
    {
        Database::getInstance()->query("DELETE FROM contact_messages WHERE id = ?", [$id]);
        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // Settings helpers — thin wrappers around SettingsService
    // ─────────────────────────────────────────────────────────────

    private static function setting(string $key, mixed $default = null): mixed
    {
        // Delegates to the global setting() helper which shares the
        // bulk-warmed SettingsService cache; fallback gets a private
        // instance if the container isn't booted yet (CLI / early
        // bootstrap contexts).
        if (function_exists('setting')) {
            try { return setting($key, $default); } catch (\Throwable) {}
        }
        try {
            return (new SettingsService())->get($key, $default, 'site');
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function settingBool(string $key, bool $default): bool
    {
        $raw = self::setting($key, $default ? '1' : '0');
        if (is_bool($raw)) return $raw;
        if (is_int($raw))  return $raw !== 0;
        $s = strtolower(trim((string) $raw));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private static function settingInt(string $key, int $default): int
    {
        $raw = self::setting($key, $default);
        if (is_int($raw)) return $raw;
        if (is_numeric($raw)) return (int) $raw;
        return $default;
    }

    // ─────────────────────────────────────────────────────────────
    // Email body builders
    // ─────────────────────────────────────────────────────────────

    private static function buildNotifyText(
        int $id, string $name, string $email,
        ?string $phone, ?string $subject, string $body, string $siteName
    ): string {
        $lines = [
            "New contact form submission on {$siteName}",
            str_repeat('-', 60),
            "From:     {$name} <{$email}>",
        ];
        if ($phone !== null && $phone !== '')  $lines[] = "Phone:    {$phone}";
        if ($subject !== null && $subject !== '') $lines[] = "Subject:  {$subject}";
        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';
        $lines[] = str_repeat('-', 60);
        $lines[] = "Reply directly to this email to respond.";
        $lines[] = "Open in admin: /admin/contact-messages/{$id}";
        return implode("\n", $lines);
    }

    private static function buildNotifyHtml(
        int $id, string $name, string $email,
        ?string $phone, ?string $subject, string $body, string $siteName
    ): string {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $appUrl = (string) ($_ENV['APP_URL'] ?? '');
        $link = rtrim($appUrl, '/') . "/admin/contact-messages/{$id}";

        $rows = "<tr><td style='padding:4px 12px 4px 0;color:#6b7280;'>From:</td><td><strong>{$e($name)}</strong> &lt;{$e($email)}&gt;</td></tr>";
        if ($phone !== null && $phone !== '')      $rows .= "<tr><td style='padding:4px 12px 4px 0;color:#6b7280;'>Phone:</td><td>{$e($phone)}</td></tr>";
        if ($subject !== null && $subject !== '')  $rows .= "<tr><td style='padding:4px 12px 4px 0;color:#6b7280;'>Subject:</td><td>{$e($subject)}</td></tr>";

        return "<body style='font-family:system-ui,-apple-system,sans-serif;color:#111;'>
<div style='max-width:600px;margin:0 auto;padding:24px;'>
  <h2 style='margin:0 0 16px;font-size:18px;'>New contact form submission</h2>
  <p style='color:#6b7280;font-size:13px;margin:0 0 16px;'>From <strong>{$e($siteName)}</strong></p>
  <table style='border-collapse:collapse;font-size:14px;margin-bottom:16px;'>{$rows}</table>
  <div style='background:#f9fafb;border-left:3px solid #4f46e5;padding:12px 16px;white-space:pre-wrap;font-size:14px;line-height:1.6;'>{$e($body)}</div>
  <p style='margin-top:24px;font-size:13px;color:#6b7280;'>
    Reply directly to this email to respond to {$e($name)}, or <a href='{$e($link)}' style='color:#4f46e5;'>open in admin</a>.
  </p>
</div>
</body>";
    }
}
