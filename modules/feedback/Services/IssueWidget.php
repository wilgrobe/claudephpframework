<?php
// modules/feedback/Services/IssueWidget.php
namespace Modules\Feedback\Services;

use Core\Auth\Auth;

/**
 * Settings resolution for the "Report an issue" widget — the corner bubble +
 * footer link that lets a customer file a bug report from wherever they hit it.
 *
 * One place decides whether the widget shows, who sees it, and where reports
 * are announced, because three surfaces need the same answer: the footer
 * partial (renders the launcher), the widget view (renders the panel), and
 * IssueReportController (accepts the POST). A mismatch between them would
 * either show a form that 404s on submit or hide a form that still accepts
 * anonymous writes.
 *
 * Every key is off/neutral by default — an existing site that migrates but
 * never opts in renders exactly as it did before.
 *
 * Settings (site scope):
 *   builder.feedback.widget.enabled   '1' to turn the widget on         (default '0')
 *   builder.feedback.widget.audience  'members' | 'everyone'            (default 'members')
 *   builder.feedback.widget.launcher  'both' | 'bubble' | 'footer'      (default 'both')
 *   builder.feedback.notify_email     where new reports are emailed     (default: site email)
 *   builder.feedback.notify_sms      mobile texted on a new report     (default: off)
 */
final class IssueWidget
{
    /** Is the issue-report widget switched on for this site? */
    public static function enabled(): bool
    {
        return function_exists('setting')
            && (string) setting('builder.feedback.widget.enabled', '0') === '1';
    }

    /** 'members' (signed-in only) or 'everyone' (guests too). */
    public static function audience(): string
    {
        $a = (string) setting('builder.feedback.widget.audience', 'members');
        return $a === 'everyone' ? 'everyone' : 'members';
    }

    /** Which launchers to render: 'both', 'bubble' (corner only), 'footer' (link only). */
    public static function launcher(): string
    {
        $l = (string) setting('builder.feedback.widget.launcher', 'both');
        return in_array($l, ['both', 'bubble', 'footer'], true) ? $l : 'both';
    }

    public static function showsBubble(): bool { return self::launcher() !== 'footer'; }
    public static function showsFooterLink(): bool { return self::launcher() !== 'bubble'; }

    /**
     * Should the current visitor see (and be allowed to use) the widget?
     * Enabled + audience match. Called by the launcher AND by the submit
     * endpoint, so what's hidden can't be posted to.
     */
    public static function visible(): bool
    {
        if (!self::enabled()) return false;
        if (self::audience() === 'everyone') return true;

        try {
            return Auth::getInstance()->check();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Where a new report is announced. Explicit setting wins; otherwise the
     * site's own contact address. Returns null when nothing is configured —
     * the report still saves to the admin queue, it just doesn't email.
     */
    public static function notifyEmail(): ?string
    {
        $candidates = [
            (string) setting('builder.feedback.notify_email', ''),
            (string) setting('site_email', ''),
            (string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''),
        ];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL)) return $c;
        }
        return null;
    }

    /**
     * Mobile number texted when a report comes in, or null when off.
     *
     * Deliberately has NO fallback, unlike notifyEmail(). An email that lands
     * in the wrong inbox is a nuisance; a text that goes to a number inherited
     * from some other setting wakes a stranger up and costs money per send. So
     * this stays silent until someone types a number on purpose.
     *
     * Returns E.164 (+15205551234). Anything that cannot be read as a real
     * number returns null rather than being passed to the gateway to fail.
     */
    public static function notifySms(): ?string
    {
        if (!function_exists('setting')) return null;

        $raw = trim((string) setting('builder.feedback.notify_sms', ''));
        if ($raw === '') return null;

        return self::normaliseNumber($raw);
    }

    /**
     * Best-effort E.164. Returns null when the input cannot be trusted.
     *
     * Assumes +1 for a bare 10-digit number because this is a US product with
     * a US sending number; a longer number must carry its own country code,
     * and is rejected rather than guessed at.
     */
    public static function normaliseNumber(string $raw): ?string
    {
        $plus   = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') return null;

        if ($plus)                                        return '+' . $digits;
        if (strlen($digits) === 10)                       return '+1' . $digits;
        if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;

        return null;
    }
}
