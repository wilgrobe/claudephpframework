<?php
// modules/policies/Controllers/PolicyController.php
namespace Modules\Policies\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Policies\Services\PolicyService;

/**
 * Public + user-facing endpoints:
 *   GET  /policies/{slug}                 — show current version (public)
 *   GET  /policies/{slug}/v/{versionId}   — show specific historical version
 *   GET  /policies/accept                 — blocking modal for unaccepted policies
 *   POST /policies/accept                 — record acceptance of all listed
 *   GET  /account/policies                — my acceptance history
 */
class PolicyController
{
    private Auth          $auth;
    private PolicyService $svc;
    private Database      $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new PolicyService();
        $this->db   = Database::getInstance();
    }

    /** GET /policies/{slug} */
    public function show(Request $request, string $slug): Response
    {
        $kind = $this->svc->findKindBySlug($slug);
        if (!$kind) return Response::view('errors.404', [], 404);

        $version = $this->svc->findCurrentVersion((int) $kind['id']);
        if (!$version) {
            return Response::view('policies::public.show', [
                'kind'    => $kind,
                'version' => null,
                'user'    => $this->auth->user(),
            ]);
        }

        return Response::view('policies::public.show', [
            'kind'    => $kind,
            'version' => $version,
            'user'    => $this->auth->user(),
        ]);
    }

    /** GET /policies/{slug}/v/{versionId} */
    public function showVersion(Request $request, string $slug, int $versionId): Response
    {
        $kind    = $this->svc->findKindBySlug($slug);
        $version = $this->svc->findVersion($versionId);
        if (!$kind || !$version || (int) $version['kind_id'] !== (int) $kind['id']) {
            return Response::view('errors.404', [], 404);
        }
        return Response::view('policies::public.show', [
            'kind'    => $kind,
            'version' => $version,
            'user'    => $this->auth->user(),
        ]);
    }

    /** GET /policies/accept — the blocking modal */
    public function acceptForm(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $unaccepted = $this->svc->unacceptedFor((int) $this->auth->id());

        // No pending policies — send the user back where they came from
        if (empty($unaccepted)) {
            return Response::redirect('/dashboard');
        }

        return Response::view('policies::account.accept', [
            'unaccepted' => $unaccepted,
            'user'       => $this->auth->user(),
        ]);
    }

    /** POST /policies/accept */
    public function acceptSubmit(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId     = (int) $this->auth->id();
        $unaccepted = $this->svc->unacceptedFor($userId);
        if (empty($unaccepted)) return Response::redirect('/dashboard');

        // Require every listed kind to be checked. The form submits a
        // hidden array of version_ids the user is acknowledging; we
        // intersect with the actual unaccepted set so a stale form
        // (e.g. a new policy bumped between page render + submit)
        // can't bypass the new one.
        $checked = $request->post('accept_versions', []);
        if (!is_array($checked)) $checked = [];
        $checked = array_map('intval', $checked);

        $expected = array_map(fn($u) => (int) $u['version_id'], $unaccepted);
        $missing  = array_diff($expected, $checked);
        if (!empty($missing)) {
            return Response::redirect('/policies/accept')
                ->withFlash('error', 'Please confirm acceptance of every listed policy to continue.');
        }

        foreach ($unaccepted as $u) {
            $this->svc->recordAcceptance($userId, (int) $u['kind_id'], (int) $u['version_id']);
            $this->auth->auditLog('policies.accepted', 'policy_versions', (int) $u['version_id'], null, [
                'kind_slug'     => $u['kind_slug'],
                'version_label' => $u['version_label'],
            ]);
        }

        // Send the user back to where they were originally headed
        // before the gate intercepted them. Falls back to /dashboard
        // when no intended URL was captured (direct visit to the
        // accept page).
        $intended = '/dashboard';
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['__intended_after_policy_accept'])) {
            $candidate = (string) $_SESSION['__intended_after_policy_accept'];
            unset($_SESSION['__intended_after_policy_accept']);
            // Anti-open-redirect: only honor relative paths starting
            // with `/`, no scheme, no double-slash (which browsers can
            // interpret as protocol-relative).
            if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
                $intended = $candidate;
            }
        }

        return Response::redirect($intended)
            ->withFlash('success', 'Thanks — your acceptance has been recorded.');
    }

    /** GET /account/policies — my history */
    public function accountHistory(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $userId  = (int) $this->auth->id();
        $history = $this->svc->userHistory($userId);

        // Also surface what's still pending so the user can re-review
        $pending = $this->svc->unacceptedFor($userId);

        // Page-chrome Batch C: fragment + chrome wrap. Slug
        // `account.policies` mirrors the URL.
        return Response::view('policies::account.index', [
            'history' => $history,
            'pending' => $pending,
            'user'    => $this->auth->user(),
        ])->withLayout('account.policies');
    }
}
