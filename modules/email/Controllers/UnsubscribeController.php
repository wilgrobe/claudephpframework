<?php
// modules/email/Controllers/UnsubscribeController.php
namespace Modules\Email\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Modules\Email\Services\SuppressionService;

/**
 * Public unsubscribe + auth-required preference center.
 *
 *   GET  /unsubscribe/{token}              — landing + click-to-confirm UI
 *   POST /unsubscribe/{token}              — confirm-from-landing form
 *   POST /unsubscribe/{token}/one-click    — RFC 8058 one-click endpoint
 *                                            (no UI, no CSRF, returns 200)
 *   GET  /account/email-preferences        — preference center (auth)
 *   POST /account/email-preferences        — save toggle states (auth)
 *
 * The /one-click endpoint is the path mail providers (Gmail, Yahoo)
 * call automatically when a user clicks "Unsubscribe" in their inbox
 * UI. It MUST work without CSRF (the call comes from the provider's
 * IP, not a browser session) and MUST be idempotent. The signed
 * token IS the proof of identity for that request.
 */
class UnsubscribeController
{
    private SuppressionService $svc;
    private Database           $db;
    private Auth               $auth;

    public function __construct()
    {
        $this->svc  = new SuppressionService();
        $this->db   = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    /** GET /unsubscribe/{token} */
    public function landing(Request $request, string $token): Response
    {
        $payload = $this->svc->verifyToken($token);
        if (!$payload) {
            return Response::view('email::public.unsubscribe_invalid', [], 410);
        }

        $cat = $this->svc->findCategory($payload['category']);
        return Response::view('email::public.unsubscribe_landing', [
            'token'    => $token,
            'email'    => $payload['email'],
            'category' => $cat ?? ['slug' => $payload['category'], 'label' => $payload['category']],
        ]);
    }

    /** POST /unsubscribe/{token} — confirm from the landing UI */
    public function confirm(Request $request, string $token): Response
    {
        $payload = $this->svc->verifyToken($token);
        if (!$payload) {
            return Response::view('email::public.unsubscribe_invalid', [], 410);
        }

        $this->svc->suppress(
            $payload['email'],
            $payload['category'],
            SuppressionService::REASON_USER_UNSUBSCRIBE,
            null,
            'Confirmed from web landing page.'
        );

        return Response::view('email::public.unsubscribe_done', [
            'email'    => $payload['email'],
            'category' => $payload['category'],
        ]);
    }

    /**
     * POST /unsubscribe/{token}/one-click
     *
     * RFC 8058 endpoint. Called by mail providers when the user clicks
     * the inbox-level "Unsubscribe" button. Returns 200 OK regardless
     * — providers retry on non-2xx. Bypasses CSRF middleware (see
     * routes.php).
     */
    public function oneClick(Request $request, string $token): Response
    {
        $payload = $this->svc->verifyToken($token);
        if ($payload) {
            $this->svc->suppress(
                $payload['email'],
                $payload['category'],
                SuppressionService::REASON_USER_UNSUBSCRIBE,
                null,
                'RFC 8058 one-click.'
            );
        }
        // Always 200 — even on bad token, so a provider retry storm
        // doesn't bury the rest of our access logs.
        return new Response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    /** GET /account/email-preferences */
    public function preferenceCenter(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $user = $this->auth->user();
        $email = (string) ($user['email'] ?? '');

        $categories  = $this->svc->listCategories();
        $suppressed  = $this->svc->listForEmail($email);

        // Page-chrome Batch C: fragment + chrome wrap. Slug
        // `account.email-preferences` mirrors the URL.
        return Response::view('email::account.preferences', [
            'user'        => $user,
            'email'       => $email,
            'categories'  => $categories,
            'suppressed'  => $suppressed,
        ])->withLayout('account.email-preferences');
    }

    /** POST /account/email-preferences */
    public function savePreferences(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $user  = $this->auth->user();
        $email = (string) ($user['email'] ?? '');
        $userId = (int) $user['id'];

        $allowed = (array) $request->post('allow', []);
        // Anything NOT in $allowed for non-transactional categories
        // becomes a suppression. Transactional categories are skipped
        // — users can't opt out of those (CAN-SPAM carve-out).
        foreach ($this->svc->listCategories() as $cat) {
            if ((int) $cat['is_transactional'] === 1) continue;
            $slug = (string) $cat['slug'];
            if (empty($allowed[$slug])) {
                $this->svc->suppress($email, $slug, SuppressionService::REASON_USER_UNSUBSCRIBE, $userId);
            } else {
                $this->svc->unsuppress($email, $slug);
            }
        }

        $this->auth->auditLog('email.preferences.saved', null, null, null, [
            'allowed' => array_keys($allowed),
        ]);

        return Response::redirect('/account/email-preferences')
            ->withFlash('success', 'Email preferences saved.');
    }
}
