<?php
// modules/accessibility/Controllers/AdminA11yController.php
namespace Modules\Accessibility\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Accessibility\Services\A11yLintService;

/**
 * /admin/a11y — accessibility lint dashboard.
 *
 *   GET  /admin/a11y           overview + most recent scan
 *   POST /admin/a11y/rescan    re-runs the lint
 */
class AdminA11yController
{
    private Auth             $auth;
    private A11yLintService  $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new A11yLintService();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        // Lint runs in-process — for a typical framework with ~200
        // template files, this is sub-second. If you grow into the
        // thousands of templates, move to a queued job + cached result.
        $findings = $this->svc->lintAll();
        $summary  = $this->svc->summarise($findings);

        return Response::view('accessibility::admin.index', [
            'findings' => $findings,
            'summary'  => $summary,
            'roots'    => $this->svc->defaultRoots(),
            'user'     => $this->auth->user(),
        ]);
    }

    public function rescan(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();
        $this->auth->auditLog('accessibility.lint_run');
        return Response::redirect('/admin/a11y')
            ->withFlash('success', 'Re-scan complete.');
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('accessibility.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to view accessibility tools.');
        return Response::redirect('/admin');
    }
}
