<?php
// modules/gdpr/Controllers/AdminGdprController.php
namespace Modules\Gdpr\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Gdpr\Services\DataExporter;
use Modules\Gdpr\Services\DataPurger;
use Modules\Gdpr\Services\DsarService;
use Modules\Gdpr\Services\GdprRegistry;

/**
 * Admin-side GDPR surface: /admin/gdpr
 *
 *   GET  /admin/gdpr                                — DSAR queue + stats
 *   GET  /admin/gdpr/handlers                       — registry inspection
 *   GET  /admin/gdpr/dsar/{id}                      — DSAR detail
 *   POST /admin/gdpr/dsar/{id}/status               — change DSAR status
 *   POST /admin/gdpr/dsar/{id}/build-export         — kick export from DSAR
 *   POST /admin/gdpr/users/{userId}/erase           — admin-initiated erasure
 *   POST /admin/gdpr/users/{userId}/restrict        — admin-initiated restriction
 *   POST /admin/gdpr/users/{userId}/build-export    — admin-initiated export
 */
class AdminGdprController
{
    private Auth         $auth;
    private Database     $db;
    private DsarService  $dsar;
    private DataExporter $exporter;
    private DataPurger   $purger;
    private GdprRegistry $registry;

    public function __construct()
    {
        $this->auth     = Auth::getInstance();
        $this->db       = Database::getInstance();
        $this->dsar     = new DsarService();
        $this->exporter = new DataExporter();
        $this->purger   = new DataPurger();
        $this->registry = new GdprRegistry();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $filter = (string) $request->query('status', 'all');
        $rows   = $this->dsar->recent(100, $filter === 'all' ? null : $filter);

        $stats = $this->db->fetchOne("
            SELECT
              SUM(status = 'pending')      AS pending,
              SUM(status = 'verified')     AS verified,
              SUM(status = 'in_progress')  AS in_progress,
              SUM(status = 'completed')    AS completed,
              SUM(status = 'denied')       AS denied,
              SUM(sla_due_at < NOW() AND status IN ('pending','verified','in_progress')) AS overdue,
              COUNT(*)                     AS total
            FROM dsar_requests
            WHERE requested_at > NOW() - INTERVAL 90 DAY
        ") ?: [];

        // Pending erasures across all users (the grace-window queue)
        $pendingErasures = $this->db->fetchAll("
            SELECT id, username, email, deletion_requested_at, deletion_grace_until,
                   TIMESTAMPDIFF(HOUR, NOW(), deletion_grace_until) AS hours_remaining
            FROM users
            WHERE deletion_requested_at IS NOT NULL
              AND deletion_grace_until > NOW()
              AND deleted_at IS NULL
            ORDER BY deletion_grace_until ASC
            LIMIT 50
        ");

        return Response::view('gdpr::admin.index', [
            'rows'             => $rows,
            'stats'            => $stats,
            'filter'           => $filter,
            'pendingErasures'  => $pendingErasures,
        ]);
    }

    public function handlers(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        return Response::view('gdpr::admin.handlers', [
            'handlers' => $this->registry->all(),
        ]);
    }

    public function dsarShow(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $row = $this->dsar->find($id);
        if (!$row) return Response::redirect('/admin/gdpr');

        $exports = $this->db->fetchAll(
            "SELECT * FROM data_exports WHERE dsar_id = ? ORDER BY id DESC",
            [$id]
        );

        return Response::view('gdpr::admin.dsar_show', [
            'row'     => $row,
            'exports' => $exports,
        ]);
    }

    public function dsarSetStatus(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $status = (string) $request->post('status', '');
        $notes  = (string) $request->post('notes', '');
        if (!in_array($status, ['pending','verified','in_progress','completed','denied','expired'], true)) {
            return Response::redirect('/admin/gdpr/dsar/' . $id)
                ->withFlash('error', 'Invalid status.');
        }

        $this->dsar->setStatus($id, $status, (int) $this->auth->id(), $notes ?: null);
        $this->auth->auditLog('gdpr.dsar.status_changed', 'dsar_requests', $id, null, ['status' => $status]);

        return Response::redirect('/admin/gdpr/dsar/' . $id)
            ->withFlash('success', "DSAR marked {$status}.");
    }

    public function userErase(Request $request, int $userId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $confirm = (string) $request->post('confirm', '');
        if (strtolower(trim($confirm)) !== 'erase') {
            return Response::redirect('/admin/users/' . $userId)
                ->withFlash('error', 'Please type "erase" exactly to confirm.');
        }

        try {
            $stats = $this->purger->purge($userId, (int) $this->auth->id());
            return Response::redirect('/admin/users/' . $userId)
                ->withFlash('success', sprintf(
                    'User #%d erased. %d tables erased, %d anonymised, %d kept.',
                    $userId,
                    $stats['tables_erased'],
                    $stats['tables_anonymized'],
                    $stats['tables_kept']
                ));
        } catch (\Throwable $e) {
            error_log('Admin erase failed for user ' . $userId . ': ' . $e->getMessage());
            return Response::redirect('/admin/users/' . $userId)
                ->withFlash('error', 'Erasure failed: ' . $e->getMessage());
        }
    }

    public function userBuildExport(Request $request, int $userId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $dsarId = (int) $request->post('dsar_id', 0) ?: null;
        try {
            $exportId = $this->exporter->buildForUser($userId, $dsarId);
            $this->auth->auditLog('gdpr.export.admin_built', 'data_exports', $exportId, null, [
                'target_user_id' => $userId,
                'dsar_id'        => $dsarId,
            ]);
            return Response::redirect($dsarId ? '/admin/gdpr/dsar/' . $dsarId : '/admin/users/' . $userId)
                ->withFlash('success', 'Export built. Use the download link in the user\'s data exports.');
        } catch (\Throwable $e) {
            return Response::redirect('/admin/gdpr')
                ->withFlash('error', 'Export failed: ' . $e->getMessage());
        }
    }

    public function userRestrict(Request $request, int $userId): Response
    {
        if (!$this->canManage()) return $this->denied();

        $user = $this->db->fetchOne("SELECT processing_restricted_at FROM users WHERE id = ?", [$userId]);
        if (!$user) return Response::redirect('/admin/users');

        $isRestricted = !empty($user['processing_restricted_at']);
        $newValue     = $isRestricted ? null : date('Y-m-d H:i:s');

        $this->db->update('users', [
            'processing_restricted_at' => $newValue,
        ], 'id = ?', [$userId]);

        $this->auth->auditLog(
            $isRestricted ? 'gdpr.restriction.lifted_by_admin' : 'gdpr.restriction.applied_by_admin',
            'users',
            $userId
        );

        return Response::redirect('/admin/users/' . $userId)
            ->withFlash('success', $isRestricted ? 'Processing restriction lifted.' : 'Processing restriction applied.');
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('gdpr.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage GDPR / DSAR.');
        return Response::redirect('/admin');
    }
}
