<?php
// modules/loginanomaly/Controllers/AdminAnomalyController.php
namespace Modules\Loginanomaly\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Loginanomaly\Services\LoginAnomalyService;

/**
 * /admin/security/anomalies — review surface for detected sign-in
 * anomalies. Lists recent findings with severity badges + ack flow.
 */
class AdminAnomalyController
{
    private Auth                $auth;
    private LoginAnomalyService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new LoginAnomalyService();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $stats   = $this->svc->statsLast30Days();
        $recent  = $this->svc->recentAnomalies(200);

        return Response::view('loginanomaly::admin.index', [
            'stats'  => $stats,
            'recent' => $recent,
            'user'   => $this->auth->user(),
        ]);
    }

    public function ack(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();
        $this->svc->acknowledge($id, (int) $this->auth->id());
        $this->auth->auditLog('loginanomaly.acknowledged', 'login_anomalies', $id);
        return Response::redirect('/admin/security/anomalies')
            ->withFlash('success', 'Acknowledged.');
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('loginanomaly.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to view login anomalies.');
        return Response::redirect('/admin');
    }
}
