<?php
// modules/ccpa/Controllers/CcpaController.php
namespace Modules\Ccpa\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Ccpa\Services\CcpaService;

/**
 * Public + auth user surfaces:
 *
 *   GET  /do-not-sell             — opt-out form (works for guest +
 *                                   signed-in user)
 *   POST /do-not-sell             — submit opt-out
 *   POST /do-not-sell/withdraw    — opt back IN (rare; allowed from
 *                                   the same form)
 *
 * Page returns 404 when ccpa_enabled is off so the URL doesn't sit
 * around as a confusing dead link.
 */
class CcpaController
{
    private Auth        $auth;
    private CcpaService $svc;
    private Database    $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new CcpaService();
        $this->db   = Database::getInstance();
    }

    public function show(Request $request): Response
    {
        if (!$this->svc->isEnabled()) {
            return Response::view('errors.404', [], 404);
        }

        $user = $this->auth->user();
        $email = $user['email'] ?? null;
        $isOptedOut = $this->svc->isOptedOut(
            $user ? (int) $user['id'] : null,
            $email
        );
        $hasGpcSignal = $this->svc->requestHasGpcSignal();

        return Response::view('ccpa::public.show', [
            'user'         => $user,
            'isOptedOut'   => $isOptedOut,
            'hasGpcSignal' => $hasGpcSignal,
            'honorsGpc'    => $this->svc->honorsGpc(),
        ]);
    }

    public function submit(Request $request): Response
    {
        if (!$this->svc->isEnabled()) {
            return Response::view('errors.404', [], 404);
        }

        $user  = $this->auth->user();
        $email = $user
            ? (string) $user['email']
            : trim((string) $request->post('email', ''));

        // Guests must supply an email so the opt-out can be matched
        // against future records bearing the same address.
        if (!$user && $email === '') {
            return Response::redirect('/do-not-sell')
                ->withFlash('error', 'Please provide your email address so we can record your opt-out.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect('/do-not-sell')
                ->withFlash('error', 'That email doesn\'t look valid.');
        }

        $id = $this->svc->recordOptOut(
            CcpaService::SOURCE_SELF,
            $user ? (int) $user['id'] : null,
            $email !== '' ? $email : null,
            'Self-service form submission.'
        );

        if ($user) {
            $this->auth->auditLog('ccpa.opt_out_recorded', null, null, null, [
                'opt_out_id' => $id,
                'source'     => 'self_service',
            ]);
        }

        return Response::redirect('/do-not-sell')
            ->withFlash('success', 'Your opt-out has been recorded. We won\'t sell or share your personal information.');
    }

    public function withdraw(Request $request): Response
    {
        if (!$this->svc->isEnabled()) {
            return Response::view('errors.404', [], 404);
        }

        $user = $this->auth->user();
        $email = $user
            ? (string) $user['email']
            : trim((string) $request->post('email', ''));

        $n = $this->svc->withdrawOptOut(
            $user ? (int) $user['id'] : null,
            $email !== '' ? $email : null
        );

        if ($user) {
            $this->auth->auditLog('ccpa.opt_out_withdrawn', null, null, null, [
                'rows_withdrawn' => $n,
            ]);
        }

        return Response::redirect('/do-not-sell')
            ->withFlash('success',
                $n > 0 ? 'You\'ve opted back in.' : 'No active opt-out found to withdraw.'
            );
    }
}
