<?php
// modules/auditchain/Controllers/AdminAuditChainController.php
namespace Modules\Auditchain\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Auditchain\Services\AuditChainService;

/**
 * /admin/audit-chain
 *
 *   GET  /admin/audit-chain                       — health overview
 *   POST /admin/audit-chain/verify                — run on-demand verify
 *   GET  /admin/audit-chain/breaks                — break log + ack flow
 *   POST /admin/audit-chain/breaks/{id}/ack       — acknowledge a break
 */
class AdminAuditChainController
{
    private Auth              $auth;
    private AuditChainService $svc;
    private Database          $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new AuditChainService();
        $this->db   = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $stats   = $this->svc->stats();
        $recent  = $this->db->fetchAll("
            SELECT r.*, u.username AS triggered_by_username
            FROM audit_chain_runs r
            LEFT JOIN users u ON u.id = r.triggered_by
            ORDER BY r.id DESC LIMIT 25
        ");

        return Response::view('auditchain::admin.index', [
            'stats'  => $stats,
            'recent' => $recent,
            'user'   => $this->auth->user(),
        ]);
    }

    public function verify(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $dayFrom = (string) $request->post('day_from', date('Y-m-d', time() - 30 * 86400));
        $dayTo   = (string) $request->post('day_to',   date('Y-m-d'));

        // Basic shape check — YYYY-MM-DD only.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayTo)) {
            return Response::redirect('/admin/audit-chain')
                ->withFlash('error', 'Dates must be YYYY-MM-DD.');
        }

        try {
            $result = $this->svc->verifyRange($dayFrom, $dayTo, (int) $this->auth->id());
            $this->auth->auditLog('auditchain.verify_run', null, null, null, [
                'day_from' => $dayFrom, 'day_to' => $dayTo, 'rows' => $result['rows'], 'breaks' => $result['breaks'],
            ]);
            return Response::redirect('/admin/audit-chain')
                ->withFlash('success', sprintf(
                    'Verified %s..%s — %d rows, %d break(s), %dms.',
                    $dayFrom, $dayTo, $result['rows'], $result['breaks'], $result['duration_ms']
                ));
        } catch (\Throwable $e) {
            return Response::redirect('/admin/audit-chain')
                ->withFlash('error', 'Verify failed: ' . $e->getMessage());
        }
    }

    public function breaks(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $rows = $this->svc->recentBreaks(200);

        return Response::view('auditchain::admin.breaks', [
            'rows' => $rows,
            'user' => $this->auth->user(),
        ]);
    }

    public function ackBreak(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $notes = trim((string) $request->post('notes', ''));
        $this->svc->acknowledgeBreak($id, (int) $this->auth->id(), $notes !== '' ? $notes : null);
        $this->auth->auditLog('auditchain.break_acknowledged', 'audit_chain_breaks', $id, null, [
            'notes' => $notes,
        ]);

        return Response::redirect('/admin/audit-chain/breaks')
            ->withFlash('success', 'Break acknowledged.');
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('auditchain.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage the audit chain.');
        return Response::redirect('/admin');
    }
}
