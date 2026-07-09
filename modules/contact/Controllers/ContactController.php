<?php
// modules/contact/Controllers/ContactController.php
namespace Modules\Contact\Controllers;

use Core\Http\Request;
use Core\Response;
use Core\Session;
use Modules\Contact\Services\ContactService;

/**
 * Public contact form submission handler.
 *
 * POST /contact — accepts the form, validates, persists, notifies.
 * On success: flash success + 302 back to /contact (or whatever the
 * form's `return_url` field requests, validated against same-origin).
 * On rejection: flash error + 302 back with form values preserved so
 * the user doesn't lose what they typed.
 */
final class ContactController
{
    /**
     * Render the public contact form. If an admin has created a custom
     * `pages` row at slug='contact', the framework's existing catch-all
     * /{slug} renderer takes precedence (routes registered before this
     * module would have already matched). When no custom row exists,
     * this default view ships the form so /contact always works.
     */
    public function show(Request $request): Response
    {
        // Honor master switch — when the form is disabled site-wide,
        // show a friendly "currently unavailable" page instead of 404.
        $enabled = true;
        try {
            $enabled = (string) (function_exists('setting') ? setting('contact_form_enabled', '1') : '1') === '1';
        } catch (\Throwable) {}

        // Repopulate previously-typed values if we're rendering after a
        // failed validation (controller flashes them via session).
        $old    = Session::flash('contact_old') ?? [];
        $errors = Session::flash('contact_errors') ?? [];

        return Response::view('contact::public.show', [
            'pageTitle'    => 'Contact us',
            'formEnabled'  => $enabled,
            'old'          => $old,
            'errors'       => $errors,
            'siteName'     => (string) (function_exists('setting') ? setting('site_name', 'us') : 'us'),
        ]);
    }

    public function submit(Request $request): Response
    {
        $payload = [
            'name'         => (string) ($_POST['name']         ?? ''),
            'email'        => (string) ($_POST['email']        ?? ''),
            'phone'        => (string) ($_POST['phone']        ?? ''),
            'subject'      => (string) ($_POST['subject']      ?? ''),
            'body'         => (string) ($_POST['body']         ?? $_POST['message'] ?? ''),
            'website'      => (string) ($_POST['website']      ?? ''),   // honeypot
            'rendered_at'  => (int)    ($_POST['rendered_at']  ?? 0),
        ];

        $ip = self::clientIp();
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $back = self::resolveReturnUrl((string) ($_POST['return_url'] ?? '/contact'));

        // CAPTCHA gate — enforced on prod (Turnstile/reCAPTCHA/hCaptcha);
        // degrades to pass when no provider is configured (local/dev). The
        // session lock is released around the synchronous provider round-trip.
        $capToken  = \Core\Services\CaptchaService::tokenFromRequest($_POST);
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($wasActive) session_write_close();
        $capOk     = \Core\Services\CaptchaService::verify($capToken, $ip);
        if ($wasActive) session_start();
        if (!$capOk) {
            Session::flash('contact_old', $payload);
            Session::flash('error', 'Please complete the anti-spam check and try again.');
            return Response::redirect($back);
        }

        $result = ContactService::submit($payload, $ip, $ua);

        if ($result['ok'] ?? false) {
            // Honeypot triggered = silently drop, lie to bots with success
            if (!empty($result['silent_drop'])) {
                Session::flash('success', "Thanks — we'll be in touch soon.");
                return Response::redirect($back);
            }
            Session::flash('success', "Thanks — your message reached us. We'll reply soon.");
            return Response::redirect($back);
        }

        // Rejection paths — preserve form values via flash so the next
        // render of /contact can repopulate them. Per-field validation
        // errors get a separate flash key the form view can read.
        Session::flash('contact_old', $payload);
        if (!empty($result['errors'])) {
            Session::flash('contact_errors', $result['errors']);
            Session::flash('error', 'Please fix the highlighted fields and try again.');
        } else {
            $msg = match ($result['reason'] ?? '') {
                ContactService::REASON_RATE_LIMITED => 'You\'ve sent a lot of messages recently — please wait a few minutes and try again.',
                ContactService::REASON_TOO_FAST     => 'That was awfully fast! If you\'re a real person, try again in a moment.',
                ContactService::REASON_DISABLED     => 'The contact form is currently disabled.',
                default                              => 'Sorry — something went wrong. Please try again.',
            };
            Session::flash('error', $msg);
        }
        return Response::redirect($back);
    }

    /**
     * Best-effort client IP detection. Honors X-Forwarded-For only when
     * TRUST_PROXY=1 is set in .env (matches the framework's existing
     * `Cookie::isSecureRequest` convention for trusting front-proxies).
     */
    private static function clientIp(): string
    {
        $trustProxy = (bool) (int) ($_ENV['TRUST_PROXY'] ?? 0);
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if ($first !== '') return $first;
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Allow only same-origin path-absolute return URLs so a hostile
     * form posting `return_url=https://evil.com` can't 302 the user
     * offsite mid-flash. Falls back to /contact on anything dodgy.
     */
    private static function resolveReturnUrl(string $candidate): string
    {
        if ($candidate === '' || $candidate[0] !== '/') return '/contact';
        if (str_starts_with($candidate, '//'))         return '/contact';
        if (preg_match('/[\r\n]/', $candidate))        return '/contact';
        return $candidate;
    }
}
