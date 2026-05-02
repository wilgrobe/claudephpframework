<?php
// modules/ccpa/Controllers/AdminCcpaController.php
namespace Modules\Ccpa\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Ccpa\Services\CcpaService;

/**
 * Admin endpoint at /admin/ccpa — opt-out records + stats. Settings
 * for the module live on the existing /admin/settings/access page so
 * the regulatory toggles are co-located with cookie consent + similar.
 */
class AdminCcpaController
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

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $stats   = $this->svc->statsLast90Days();
        $recent  = $this->svc->recentOptOuts(100);

        return Response::view('ccpa::admin.index', [
            'stats'  => $stats,
            'recent' => $recent,
            'user'   => $this->auth->user(),
        ]);
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('ccpa.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to view CCPA opt-out records.');
        return Response::redirect('/admin');
    }
}
