<?php
// modules/feedback/Services/IssueReportNotifier.php
namespace Modules\Feedback\Services;

use Core\Database\Database;
use Core\Services\MailService;
use Core\Services\NotificationService;

/**
 * Pushes a freshly-filed issue report at the site's operators.
 *
 * An admin queue nobody thinks to open is not an alerting system. When a
 * paying customer says something is broken, someone needs to know now — so
 * every report emails the configured address and raises an in-app
 * notification for site admins.
 *
 * Both channels are best-effort: the report is already stored by the time
 * this runs, and a mail-transport hiccup must never turn a saved report into
 * an error page for the person who filed it. The caller swallows throwables;
 * this class additionally isolates the two channels from each other so a
 * failing one still lets the other through.
 */
final class IssueReportNotifier
{
    /** Role slugs treated as "operators" for the in-app notification. */
    private const ADMIN_ROLES = ['super-admin', 'admin'];

    /** Cap on in-app fan-out — a big site shouldn't notify 200 people. */
    private const MAX_IN_APP = 10;

    /**
     * @param array{intent:string,message:string,severity:string,email:?string,name:?string,context:array} $report
     */
    public function announce(int $id, array $report): void
    {
        try { $this->email($id, $report); }  catch (\Throwable $e) { error_log('[feedback] issue email failed: ' . $e->getMessage()); }
        try { $this->inApp($id, $report); }  catch (\Throwable $e) { error_log('[feedback] issue notification failed: ' . $e->getMessage()); }
    }

    // ── Email ─────────────────────────────────────────────────────────

    private function email(int $id, array $report): void
    {
        $to = IssueWidget::notifyEmail();
        if ($to === null) return;

        $site     = (string) (setting('site_name', '') ?: 'Your site');
        $blocking = ($report['severity'] ?? 'normal') === 'blocking';
        $subject  = sprintf('%s[%s] Issue report #%d', $blocking ? '🔴 BLOCKING — ' : '', $site, $id);

        (new MailService())->send($to, $subject, $this->html($id, $report), $this->text($id, $report));
    }

    private function html(int $id, array $report): string
    {
        $e   = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ctx = $report['context'] ?? [];

        // Name when we have one, otherwise the account email — and only append
        // the account email when it adds something, so an unnamed account
        // doesn't render as "a@b.com (a@b.com)".
        $accountEmail = (string) ($ctx['user']['email'] ?? '');
        $who = trim((string) ($ctx['user']['name'] ?? ''));
        if ($who === '') {
            $who = $accountEmail ?: (string) ($report['email'] ?? '') ?: 'a signed-out visitor';
        } elseif ($accountEmail !== '') {
            $who .= ' (' . $accountEmail . ')';
        }

        $rows = [
            'Reported by' => $who,
            'Roles'       => implode(', ', (array) ($ctx['user']['roles'] ?? [])) ?: '—',
            'Page'        => $ctx['page']['url'] ?? '—',
            'Browser'     => $ctx['request']['user_agent'] ?? '—',
            'Viewport'    => $ctx['client']['viewport'] ?? '—',
            'When'        => $ctx['request']['server_time'] ?? '—',
            'Reply to'    => $report['email'] ?: '— (no reply requested)',
        ];

        $h = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;line-height:1.6;color:#111;">';

        if (($report['severity'] ?? '') === 'blocking') {
            $h .= '<p style="background:#fee2e2;border-left:4px solid #dc2626;padding:.6rem .8rem;margin:0 0 1rem;font-weight:600;color:#991b1b;">'
                . 'The reporter marked this as blocking — they could not carry on.</p>';
        }

        $h .= '<h2 style="margin:0 0 1rem;font-size:17px;">Issue report #' . (int) $id . '</h2>'
            . '<p style="margin:0 0 .3rem;font-weight:600;color:#374151;">What they were trying to do</p>'
            . '<blockquote style="margin:0 0 1rem;padding:.6rem .9rem;background:#f9fafb;border-left:3px solid #d1d5db;white-space:pre-wrap;">' . $e($report['intent']) . '</blockquote>'
            . '<p style="margin:0 0 .3rem;font-weight:600;color:#374151;">What happened instead</p>'
            . '<blockquote style="margin:0 0 1.25rem;padding:.6rem .9rem;background:#f9fafb;border-left:3px solid #d1d5db;white-space:pre-wrap;">' . $e($report['message']) . '</blockquote>'
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-bottom:1.25rem;">';

        foreach ($rows as $k => $v) {
            $h .= '<tr>'
                . '<td style="padding:.25rem .9rem .25rem 0;color:#6b7280;vertical-align:top;white-space:nowrap;">' . $e($k) . '</td>'
                . '<td style="padding:.25rem 0;color:#111;word-break:break-all;">' . $e($v) . '</td>'
                . '</tr>';
        }
        $h .= '</table>';

        // The diagnostics that usually settle the question on their own.
        $errors = (array) ($ctx['diagnostics']['js_errors'] ?? []);
        $failed = (array) ($ctx['diagnostics']['failed_requests'] ?? []);

        if ($errors || $failed) {
            $h .= '<p style="margin:0 0 .4rem;font-weight:600;color:#374151;">What the browser saw</p>'
                . '<div style="background:#0f172a;color:#e2e8f0;border-radius:6px;padding:.75rem .9rem;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.55;overflow-x:auto;">';
            foreach (array_slice($failed, -6) as $f) {
                $h .= '<div>' . $e(sprintf('%s %s → %s',
                        $f['method'] ?? 'GET', $f['url'] ?? '', $f['status'] ?? '?')) . '</div>';
            }
            foreach (array_slice($errors, -6) as $err) {
                $line = is_array($err) ? ($err['message'] ?? json_encode($err)) : $err;
                $h .= '<div style="color:#fca5a5;">' . $e($line) . '</div>';
            }
            $h .= '</div>';
        }

        $base = $this->baseUrl();
        $h .= '<p style="margin:1.25rem 0 0;">'
            . '<a href="' . $e($base . '/admin/site-feedback?kind=issue') . '" '
            . 'style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:.55rem 1rem;border-radius:6px;font-weight:600;font-size:13px;">'
            . 'Open the issue queue</a></p>'
            . '<p style="margin:1rem 0 0;font-size:12px;color:#9ca3af;">'
            . 'Full diagnostics — click trail, console errors, browser environment — are attached to this report in the admin queue.</p>'
            . '</div>';

        return $h;
    }

    private function text(int $id, array $report): string
    {
        $ctx = $report['context'] ?? [];
        return "Issue report #{$id}\n\n"
            . "Trying to do:\n" . $report['intent'] . "\n\n"
            . "What happened:\n" . $report['message'] . "\n\n"
            . 'Page:  ' . ($ctx['page']['url'] ?? '—') . "\n"
            . 'Who:   ' . ($ctx['user']['email'] ?? ($report['email'] ?: 'signed-out visitor')) . "\n"
            . 'When:  ' . ($ctx['request']['server_time'] ?? '—') . "\n\n"
            . 'Queue: ' . $this->baseUrl() . "/admin/site-feedback?kind=issue\n";
    }

    /**
     * Base URL for the "open the queue" link.
     *
     * Prefers the host the report was actually filed from, because that's the
     * site whose queue holds it — on a multi-tenant install APP_URL is the
     * apex, so trusting it would send the operator to the wrong site's queue.
     * Falls back to APP_URL for non-web contexts (queue worker, cron).
     */
    private function baseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            return ($https ? 'https://' : 'http://') . $host;
        }
        return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    }

    // ── In-app ────────────────────────────────────────────────────────

    private function inApp(int $id, array $report): void
    {
        $admins = $this->adminIds();
        if (!$admins) return;

        $svc      = new NotificationService();
        $blocking = ($report['severity'] ?? 'normal') === 'blocking';
        $title    = $blocking ? 'Blocking issue reported' : 'New issue report';
        $body     = mb_substr((string) $report['message'], 0, 160);

        foreach ($admins as $uid) {
            try {
                $svc->send(
                    $uid,
                    'feedback.issue_reported',
                    $title,
                    $body,
                    ['url' => '/admin/site-feedback?kind=issue', 'report_id' => $id],
                    'in_app'
                );
            } catch (\Throwable) {
                // One bad recipient shouldn't stop the rest.
            }
        }
    }

    /** @return int[] */
    private function adminIds(): array
    {
        try {
            $in   = implode(',', array_fill(0, count(self::ADMIN_ROLES), '?'));
            $rows = Database::getInstance()->fetchAll(
                "SELECT DISTINCT ur.user_id
                   FROM user_roles ur
                   JOIN roles r ON r.id = ur.role_id
                  WHERE r.slug IN ({$in})
                  LIMIT " . self::MAX_IN_APP,
                self::ADMIN_ROLES
            );
            return array_values(array_map(static fn($r) => (int) $r['user_id'], $rows));
        } catch (\Throwable) {
            return [];
        }
    }
}
