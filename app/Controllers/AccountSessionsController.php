<?php
// app/Controllers/AccountSessionsController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Services\SettingsService;

/**
 * User-facing "my active sessions" surface. Lets a logged-in user
 * review every device currently signed in as them and sign individual
 * devices out.
 *
 *   GET  /account/sessions              — list own sessions
 *   POST /account/sessions/{id}/terminate — delete one of own sessions
 *
 * Feature-gated by the `account_sessions_enabled` site setting
 * (toggle at /admin/settings/security). When off, both routes return
 * 404 so the surface is genuinely absent rather than just unlinked.
 *
 * "Current device" detection uses PHP's session_id() for the active
 * request — the row with that id in the DB is the browser the user
 * is looking at right now. Terminating your own current device logs
 * you out immediately; the page redirects to the login screen.
 */
class AccountSessionsController
{
    private Auth            $auth;
    private Database        $db;
    private SettingsService $settings;

    public function __construct()
    {
        $this->auth     = Auth::getInstance();
        $this->db       = Database::getInstance();
        $this->settings = new SettingsService();
    }

    private function gateOrHide(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        $enabled = (bool) $this->settings->get('account_sessions_enabled', true, 'site');
        if (!$enabled) return new Response('Not found', 404);
        return null;
    }

    public function index(Request $request): Response
    {
        if ($g = $this->gateOrHide()) return $g;

        $userId    = (int) $this->auth->id();
        $currentId = session_id();

        $rows = $this->db->fetchAll(
            "SELECT id, ip_address, user_agent, last_activity
               FROM sessions
              WHERE user_id = ?
           ORDER BY last_activity DESC",
            [$userId]
        );

        foreach ($rows as &$r) {
            $r['is_current'] = ((string) $r['id'] === (string) $currentId);
        }
        unset($r);

        return Response::view('account.sessions', [
            'sessions'  => $rows,
            'currentId' => $currentId,
            'me'        => $this->auth->user(),
        ]);
    }

    public function terminate(Request $request): Response
    {
        if ($g = $this->gateOrHide()) return $g;

        $targetId = (string) $request->param(0);
        $userId   = (int) $this->auth->id();

        // Scope the delete — users can only kick their OWN sessions. An
        // attacker guessing another user's session id would match zero
        // rows here because user_id = (current auth) filters it out.
        $deleted = $this->db->delete(
            'sessions',
            'id = ? AND user_id = ?',
            [$targetId, $userId]
        );

        if ($deleted <= 0) {
            return Response::redirect('/account/sessions')
                ->withFlash('error', 'Session not found or not yours.');
        }

        $this->auth->auditLog(
            'session.terminate.self',
            'sessions',
            null,
            null,
            ['target_session_id' => substr($targetId, 0, 16) . '…']
        );

        // Killed the current browser? Send straight to login rather
        // than re-render a page we no longer have a valid session for.
        if ((string) $targetId === (string) session_id()) {
            return Response::redirect('/login')
                ->withFlash('success', 'You\'ve been signed out of this device.');
        }

        return Response::redirect('/account/sessions')
            ->withFlash('success', 'Device signed out.');
    }
}
