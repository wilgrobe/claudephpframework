<?php
// modules/audit-log-viewer/Controllers/AuditLogController.php
namespace Modules\AuditLogViewer\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Modules\AuditLogViewer\Services\AuditLogService;

/**
 *   GET /admin/audit-log        — filterable queue
 *   GET /admin/audit-log/{id}   — detail with old/new JSON diff
 *
 * Gate: `audit.view` permission (or admin baseline via RequireAdmin
 * at the route layer). Read-only everywhere.
 */
class AuditLogController
{
    private Auth            $auth;
    private AuditLogService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new AuditLogService();
    }

    private function gate(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        if (!$this->auth->can('audit.view') && !$this->auth->can('admin.access')) {
            return new Response('Forbidden', 403);
        }
        return null;
    }

    public function index(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $filters = array_filter([
            'actor_user_id' => (int) $request->query('actor_user_id', 0) ?: null,
            'action'        => trim((string) $request->query('action')) ?: null,
            'model'         => trim((string) $request->query('model')) ?: null,
            'model_id'      => (int) $request->query('model_id', 0) ?: null,
            'date_from'     => trim((string) $request->query('date_from')) ?: null,
            'date_to'       => trim((string) $request->query('date_to')) ?: null,
            'q'             => trim((string) $request->query('q')) ?: null,
        ], static fn($v) => $v !== null && $v !== '');

        $page = max(1, (int) $request->query('page', 1));
        $res  = $this->svc->list($filters, $page, 50);

        return Response::view('audit_log_viewer::admin.index', $res + [
            'filters'      => $filters,
            'top_actions'  => $this->svc->topActions(50),
            'chain_status' => $this->svc->chainStatus(),
        ]);
    }

    public function show(Request $request): Response
    {
        if ($g = $this->gate()) return $g;
        $id = (int) $request->param(0);
        $row = $this->svc->find($id);
        if (!$row) return new Response('Not found', 404);

        return Response::view('audit_log_viewer::admin.show', [
            'row'       => $row,
            'values'    => $this->svc->decodeValues($row),
            'prev_id'   => $this->svc->neighbourId($id, 'prev'),
            'next_id'   => $this->svc->neighbourId($id, 'next'),
        ]);
    }

    /**
     * GET /admin/audit-log.csv — streams a filtered CSV slice for
     * compliance / forensic exports. Same filter shape as the
     * index view. Capped at 100k rows per call so a runaway query
     * can't exhaust apex memory.
     */
    public function export(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $filters = array_filter([
            'actor_user_id' => (int) $request->query('actor_user_id', 0) ?: null,
            'action'        => trim((string) $request->query('action')) ?: null,
            'model'         => trim((string) $request->query('model')) ?: null,
            'model_id'      => (int) $request->query('model_id', 0) ?: null,
            'date_from'     => trim((string) $request->query('date_from')) ?: null,
            'date_to'       => trim((string) $request->query('date_to')) ?: null,
            'q'             => trim((string) $request->query('q')) ?: null,
        ], static fn($v) => $v !== null && $v !== '');

        // Buffer CSV in memory — adequate for the 100k row cap. Streaming
        // straight to STDOUT would require Response to support callbacks
        // which isn't wired in this framework.
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, [
            'id', 'created_at', 'actor_user_id', 'actor_username',
            'emulated_user_id', 'emulated_username', 'superadmin_mode',
            'action', 'model', 'model_id', 'ip_address', 'user_agent',
            'old_values', 'new_values', 'notes',
        ]);
        $rows = $this->svc->streamForExport($filters, function (array $r) use ($fh): void {
            fputcsv($fh, [
                $r['id'] ?? '',
                $r['created_at'] ?? '',
                $r['actor_user_id'] ?? '',
                $r['actor_username'] ?? '',
                $r['emulated_user_id'] ?? '',
                $r['emulated_username'] ?? '',
                $r['superadmin_mode'] ?? '0',
                $r['action'] ?? '',
                $r['model'] ?? '',
                $r['model_id'] ?? '',
                $r['ip_address'] ?? '',
                $r['user_agent'] ?? '',
                $r['old_values'] ?? '',
                $r['new_values'] ?? '',
                $r['notes'] ?? '',
            ]);
        });
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'audit-log-' . date('Ymd-His') . '.csv';
        return new Response($csv, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => "attachment; filename=\"$filename\"",
            'X-Audit-Export-Rows'       => (string) $rows,
            'Cache-Control'             => 'no-store',
        ]);
    }
}
