<?php
// modules/siteblocks/Controllers/NewsletterController.php
namespace Modules\Siteblocks\Controllers;

use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Single-action controller backing the siteblocks.newsletter_signup block.
 *
 * Validates the posted email, upserts into newsletter_signups so a
 * resubscribe just refreshes subscribed_at + clears unsubscribed_at,
 * then redirects back to source_url (defaulting to / if missing) with
 * a success or error flash. Deliberately silent on duplicate-email so
 * the public form can't be used as an email-existence oracle.
 *
 * For external providers (Mailchimp / SendGrid / etc): subclass this
 * controller or hook a job that syncs new newsletter_signups rows on
 * INSERT. Out of scope for v1.
 */
class NewsletterController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function subscribe(Request $request): Response
    {
        $email  = strtolower(trim((string) $request->post('email', '')));
        $source = (string) ($request->post('source_url', '') ?: ($_SERVER['HTTP_REFERER'] ?? '/'));

        // Defensive: clamp lengths to schema bounds so an oversized POST
        // (raw curl, malicious form) can't hit a column-length error
        // mid-INSERT.
        if (mb_strlen($source) > 500) $source = mb_substr($source, 0, 500);

        // Validate email shape. We don't tell the user "invalid email"
        // any differently from "already subscribed" — both flow into
        // the same generic success flash so the form doesn't double as
        // an email-enumeration oracle.
        $isValid = $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($email) <= 255;

        if ($isValid) {
            try {
                $ua = isset($_SERVER['HTTP_USER_AGENT'])
                    ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
                    : null;
                $ip = $request->ip();

                // Upsert: resubscribe clears unsubscribed_at and refreshes
                // subscribed_at. ON DUPLICATE KEY catches the UNIQUE on email.
                $this->db->query(
                    "INSERT INTO newsletter_signups (email, source_url, user_agent, ip_address)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        subscribed_at   = CURRENT_TIMESTAMP,
                        unsubscribed_at = NULL,
                        source_url      = VALUES(source_url),
                        user_agent      = VALUES(user_agent),
                        ip_address      = VALUES(ip_address)",
                    [$email, $source, $ua, $ip]
                );
            } catch (\Throwable $e) {
                error_log('[newsletter.subscribe] failed: ' . $e->getMessage());
                // Fall through to the generic flash anyway — never tell
                // the user "DB write failed" on a public form.
            }
        }

        // Generic flash regardless of validation outcome. Same message
        // for "thanks, we'll keep you posted" whether the email was
        // valid + new, valid + already-subscribed, or invalid. That's
        // the consistent-response posture the password-reset flow uses.
        Session::flash('success', 'Thanks — you\'re on the list.');
        return Response::redirect($this->safeRedirect($source));
    }

    /**
     * Sanity-check the redirect target so the form can't be turned into
     * an open redirect. Same approach Auth::safeRedirect uses for the
     * post-login intended-URL — only relative paths are honoured.
     */
    private function safeRedirect(string $url): string
    {
        if ($url === '') return '/';
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $url)) return '/';
        if (!str_starts_with($url, '/')) return '/';
        return $url;
    }
}
