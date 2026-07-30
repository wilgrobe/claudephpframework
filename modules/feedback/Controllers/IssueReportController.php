<?php
// modules/feedback/Controllers/IssueReportController.php
namespace Modules\Feedback\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Database\Database;
use Modules\Feedback\Services\IssueWidget;
use Modules\Feedback\Services\IssueReportNotifier;

/**
 * Accepts issue reports from the corner widget.
 *
 * The reporter answers two questions — what they were trying to do, and what
 * happened instead. Everything else that makes a report diagnosable is
 * captured without them having to know or care: who they are, where they
 * were, and what their browser saw go wrong.
 *
 * Trust boundary: identity, IP, and user agent are read SERVER-side and
 * overwrite anything the client sent under the same name. The client's
 * contribution (viewport, timezone, JS errors, failed requests, click
 * breadcrumbs) is untrusted — it lands in its own `client`/`diagnostics`
 * sub-arrays, size-capped and string-coerced, and is only ever rendered
 * escaped in the admin queue.
 */
class IssueReportController
{
    /** Reports per IP (or per user) allowed inside the throttle window. */
    private const THROTTLE_MAX     = 5;
    private const THROTTLE_MINUTES = 15;

    /** Hard caps on untrusted client payload. */
    private const MAX_TEXT      = 5000;   // per free-text answer
    private const MAX_CONTEXT   = 60000;  // raw client JSON before decode
    private const MAX_ENTRIES   = 25;     // per diagnostics list
    private const MAX_ENTRY_LEN = 500;    // per diagnostics entry

    /** Query-string keys whose VALUES are masked before a URL is stored. */
    private const SECRET_QS = ['token', '_token', 'password', 'passwd', 'secret', 'key', 'api_key', 'apikey', 'code', 'signature', 'sig', 'auth'];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function submit(Request $request): Response
    {
        // Hidden means closed: the same predicate that decides whether the
        // launcher renders decides whether a POST is accepted.
        if (!IssueWidget::visible()) {
            return Response::json(['ok' => false, 'error' => 'Issue reporting is not available.'], 404);
        }

        // Honeypot — a bot fills every field it finds; the real widget leaves
        // this one empty. Answer with success so the bot doesn't retry.
        if (trim((string) ($request->post('website') ?? '')) !== '') {
            return Response::json(['ok' => true, 'id' => 0]);
        }

        $auth   = Auth::getInstance();
        $user   = $auth->check() ? $auth->user() : null;
        $userId = $user ? (int) $user['id'] : null;
        $ip     = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        if ($this->throttled($ip, $userId)) {
            return Response::json([
                'ok'    => false,
                'error' => 'You’ve sent several reports just now — please give us a few minutes to read them.',
            ], 429);
        }

        // ── The two questions ─────────────────────────────────────────
        $intent  = trim((string) ($request->post('intent')  ?? ''));
        $message = trim((string) ($request->post('message') ?? ''));

        $errors = [];
        if (mb_strlen($intent) < 4)  $errors['intent']  = 'Tell us what you were trying to do.';
        if (mb_strlen($message) < 4) $errors['message'] = 'Tell us what happened instead.';
        if (mb_strlen($intent) > self::MAX_TEXT)  $errors['intent']  = 'That’s a bit long — please shorten it.';
        if (mb_strlen($message) > self::MAX_TEXT) $errors['message'] = 'That’s a bit long — please shorten it.';

        // Email: prefilled from the account when signed in, typed by guests.
        // Only needed if they want a reply.
        $wantsReply = !empty($request->post('request_response')) ? 1 : 0;
        $email      = trim((string) ($request->post('email') ?? '')) ?: (string) ($user['email'] ?? '');
        $email      = $email !== '' ? $email : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That email doesn’t look right.';
        }
        if ($wantsReply && $email === null) {
            $errors['email'] = 'Add an email so we can reply.';
        }

        if ($errors) {
            return Response::json(['ok' => false, 'errors' => $errors], 422);
        }

        $severity = $request->post('severity') === 'blocking' ? 'blocking' : 'normal';
        $context  = $this->buildContext($request, $user, $ip);
        $pageUrl  = (string) ($context['page']['url'] ?? '');

        // ── Persist ───────────────────────────────────────────────────
        try {
            $this->db->query(
                "INSERT INTO feedback_submissions
                    (kind, prompt, intent, message, is_anonymous, user_id, page_url, severity, context,
                     name, email, request_response, consent_display, ip, user_agent, status, created_at, updated_at)
                 VALUES ('issue', 'Issue report', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'new', NOW(), NOW())",
                [
                    $intent,
                    $message,
                    // Anonymous only when there's genuinely no way to reach
                    // them. A signed-out reporter who left an email is NOT
                    // anonymous — flagging them as such hides the reply
                    // address from the very queue you'd reply from.
                    ($userId === null && $email === null) ? 1 : 0,
                    $userId,
                    mb_substr($pageUrl, 0, 500),
                    $severity,
                    json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    $user ? ($this->displayName($user) ?: null) : null,
                    $email,
                    $wantsReply,
                    $ip,
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]
            );
            $id = (int) $this->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[feedback] issue report insert failed: ' . $e->getMessage());
            return Response::json([
                'ok'    => false,
                'error' => 'We couldn’t save that — please try again, or email us directly.',
            ], 500);
        }

        // Announce it. A mail/notification failure must never fail a report
        // that's already safely stored, so this is best-effort and swallowed.
        try {
            (new IssueReportNotifier())->announce($id, [
                'intent'   => $intent,
                'message'  => $message,
                'severity' => $severity,
                'email'    => $email,
                'name'     => $user['name'] ?? null,
                'context'  => $context,
            ]);
        } catch (\Throwable $e) {
            error_log('[feedback] issue report notify failed (#' . $id . '): ' . $e->getMessage());
        }

        return Response::json(['ok' => true, 'id' => $id]);
    }

    // ── Throttle ──────────────────────────────────────────────────────

    /**
     * Cap reports per IP/user in a sliding window, counted straight off the
     * reports table.
     *
     * Deliberately NOT Core\Auth\RateLimiter: its per-IP key is shared with
     * the login limiter, so throttling a chatty reporter there would lock
     * them out of signing in — the exact opposite of what someone reporting
     * a problem needs.
     */
    private function throttled(string $ip, ?int $userId): bool
    {
        try {
            $n = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM feedback_submissions
                  WHERE kind = 'issue'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                    AND (ip = ? OR (? IS NOT NULL AND user_id = ?))",
                [self::THROTTLE_MINUTES, $ip, $userId, $userId]
            );
            return $n >= self::THROTTLE_MAX;
        } catch (\Throwable) {
            return false; // never block a report because the check itself broke
        }
    }

    // ── Context capture ───────────────────────────────────────────────

    /**
     * Assemble the diagnostic record. Server-derived facts are collected
     * first; the client blob is sanitized and merged underneath so it can
     * never overwrite them.
     */
    private function buildContext(Request $request, ?array $user, string $ip): array
    {
        $client = $this->sanitizeClient((string) ($request->post('context') ?? ''));

        // The page URL the client reports is the useful one (it's where they
        // actually were — the POST goes to /feedback/report), but it's
        // untrusted, so it's normalized and secret-scrubbed. Referer is the
        // server-side cross-check.
        $pageUrl = $this->scrubUrl((string) ($client['page']['url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));

        return [
            'reported_at' => gmdate('c'),

            // Who — server-side, authoritative.
            'user' => $user ? [
                'id'    => (int) $user['id'],
                'name'  => $this->displayName($user),
                'email' => (string) ($user['email'] ?? ''),
                'roles' => $this->rolesFor((int) $user['id']),
            ] : ['id' => null, 'name' => null, 'email' => null, 'roles' => []],

            // Where.
            'page' => [
                'url'      => $pageUrl,
                'path'     => (string) (parse_url($pageUrl, PHP_URL_PATH) ?? ''),
                'title'    => $this->str($client['page']['title'] ?? '', 200),
                'referrer' => $this->scrubUrl((string) ($client['page']['referrer'] ?? '')),
            ],

            // The request that filed the report — server-side.
            'request' => [
                'ip'          => $ip,
                'user_agent'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'referer'     => $this->scrubUrl((string) ($_SERVER['HTTP_REFERER'] ?? '')),
                'server_time' => date('c'),
                'session'     => $this->sessionFingerprint(),
            ],

            // The install, so a report from a stale deploy is recognisable.
            'app' => [
                'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
                'env'  => (string) ($_ENV['APP_ENV'] ?? 'production'),
                'php'  => PHP_VERSION,
            ],

            // Browser environment + what the browser saw fail — untrusted.
            'client'      => $client['client']      ?? [],
            'diagnostics' => $client['diagnostics'] ?? [],
        ];
    }

    /**
     * A stable, non-reversible handle on the session so two reports from the
     * same sitting can be tied together — and so the report can be lined up
     * against server logs — without storing the session id itself (which is a
     * live credential).
     */
    private function sessionFingerprint(): string
    {
        $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        return $sid !== '' ? substr(hash('sha256', $sid), 0, 12) : '';
    }

    /**
     * A human label for the reporter.
     *
     * The users table has NO `name` column — the framework stores
     * username/first_name/last_name — so reading `$user['name']` silently
     * yields null and every report lands with an unnamed reporter. Build the
     * label from what actually exists, in descending order of how a person
     * would recognise themselves.
     */
    private function displayName(array $user): string
    {
        $full = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        foreach ([$full, (string) ($user['username'] ?? ''), (string) ($user['name'] ?? '')] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') return mb_substr($candidate, 0, 200);
        }
        return '';
    }

    /** @return string[] role slugs */
    private function rolesFor(int $userId): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT r.slug FROM roles r
                   JOIN user_roles ur ON ur.role_id = r.id
                  WHERE ur.user_id = ?",
                [$userId]
            );
            return array_values(array_map(static fn($r) => (string) $r['slug'], $rows));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Decode + hard-limit the client diagnostics blob.
     *
     * Shape kept (anything else is dropped):
     *   page:        {url, title, referrer}
     *   client:      flat scalar map (viewport, screen, dpr, timezone, …)
     *   diagnostics: {js_errors[], failed_requests[], console_errors[], breadcrumbs[]}
     */
    private function sanitizeClient(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_CONTEXT) return [];

        $in = json_decode($raw, true);
        if (!is_array($in)) return [];

        $out = ['page' => [], 'client' => [], 'diagnostics' => []];

        foreach (['url', 'title', 'referrer'] as $k) {
            if (isset($in['page'][$k])) $out['page'][$k] = $this->str($in['page'][$k], 500);
        }

        // Environment: scalars only, capped in count and length. Whatever the
        // client chooses to send comes through, so a future field added to the
        // widget JS needs no server change — but nothing nested or huge does.
        if (is_array($in['client'] ?? null)) {
            foreach (array_slice($in['client'], 0, 30, true) as $k => $v) {
                if (!is_scalar($v) && $v !== null) continue;
                $out['client'][$this->str($k, 40)] = $this->str($v, 200);
            }
        }

        foreach (['js_errors', 'failed_requests', 'console_errors', 'breadcrumbs'] as $list) {
            $vals = $in['diagnostics'][$list] ?? null;
            if (!is_array($vals)) continue;
            $clean = [];
            foreach (array_slice($vals, -self::MAX_ENTRIES) as $entry) {
                if (is_array($entry)) {
                    // e.g. a failed request {method, url, status, at}
                    $row = [];
                    foreach (array_slice($entry, 0, 8, true) as $k => $v) {
                        if (!is_scalar($v) && $v !== null) continue;
                        $val = $this->str($v, self::MAX_ENTRY_LEN);
                        $row[$this->str($k, 40)] = ($k === 'url') ? $this->scrubUrl($val) : $val;
                    }
                    if ($row) $clean[] = $row;
                } elseif (is_scalar($entry)) {
                    $clean[] = $this->str($entry, self::MAX_ENTRY_LEN);
                }
            }
            if ($clean) $out['diagnostics'][$list] = $clean;
        }

        return $out;
    }

    /**
     * Mask the values of sensitive query parameters before a URL is stored.
     * Reports routinely come from pages reached via a signed/reset link, and
     * a live token sitting in the admin queue (and in the notification email)
     * is a credential leak we'd be creating ourselves.
     */
    private function scrubUrl(string $url): string
    {
        $url = $this->str($url, 500);
        if ($url === '' || !str_contains($url, '?')) return $url;

        [$base, $qs] = explode('?', $url, 2);
        $frag = '';
        if (str_contains($qs, '#')) [$qs, $frag] = explode('#', $qs, 2);

        $parts = [];
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') continue;
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[] = in_array(strtolower(urldecode($k)), self::SECRET_QS, true)
                ? $k . '=[redacted]'
                : $k . ($v === '' ? '' : '=' . $v);
        }

        return $base . ($parts ? '?' . implode('&', $parts) : '') . ($frag !== '' ? '#' . $frag : '');
    }

    /** Coerce any scalar to a trimmed, length-capped, control-char-free string. */
    private function str(mixed $v, int $max): string
    {
        if (is_bool($v))  $v = $v ? 'true' : 'false';
        if ($v === null)  $v = '';
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '';
        return mb_substr(trim($s), 0, $max);
    }
}
