<?php
// modules/tooltip/Controllers/OverrideController.php
namespace Modules\Tooltip\Controllers;

use Core\Database\Database;
use Core\Request;
use Core\Response;
use Modules\Tooltip\Services\TooltipService;

/**
 * Per-scope content overrides (the `per-page-overrides` submodule). Routes are
 * registered only when the submodule is enabled, so these actions 404 when off.
 *   GET  /admin/tooltips/{id}/overrides
 *   POST /admin/tooltips/{id}/overrides
 *   POST /admin/tooltips/overrides/{oid}/delete
 */
class OverrideController
{
    private const SCOPES = ['page', 'route', 'user_role'];

    private Database       $db;
    private TooltipService $svc;

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->svc = new TooltipService();
    }

    public function index(Request $request): Response
    {
        $tip = $this->svc->find((int) $request->param(0));
        if (!$tip) return new Response('Tooltip not found', 404);
        $rows = $this->svc->overridesFor((int) $tip['id']);
        // Conflict hint: more than one active override per scope can both match.
        $byScope = [];
        foreach ($rows as $r) { if ((int) $r['is_active'] === 1) $byScope[$r['scope']] = ($byScope[$r['scope']] ?? 0) + 1; }
        return Response::view('tooltip::admin.overrides', [
            'tip'       => $tip,
            'overrides' => $rows,
            'scopes'    => self::SCOPES,
            'conflicts' => array_keys(array_filter($byScope, static fn ($n) => $n > 1)),
        ]);
    }

    public function store(Request $request): Response
    {
        $tip = $this->svc->find((int) $request->param(0));
        if (!$tip) return new Response('Tooltip not found', 404);
        $scope = (string) $request->post('scope', 'page');
        if (!in_array($scope, self::SCOPES, true)) $scope = 'page';
        $value = trim((string) $request->post('scope_value', ''));
        if ($value === '') return Response::redirect('/admin/tooltips/' . $tip['id'] . '/overrides')->withFlash('error', 'Scope value is required.');
        $this->db->insert('tooltip_overrides', [
            'tooltip_id'   => (int) $tip['id'],
            'scope'        => $scope,
            'scope_value'  => substr($value, 0, 200),
            'content_html' => (string) $request->post('content_html', ''),
            'is_active'    => $request->post('is_active', '1') ? 1 : 0,
        ]);
        return Response::redirect('/admin/tooltips/' . $tip['id'] . '/overrides')->withFlash('success', 'Override added.');
    }

    public function delete(Request $request): Response
    {
        $oid = (int) $request->param(0);
        $row = $this->db->fetchOne("SELECT tooltip_id FROM tooltip_overrides WHERE id = ?", [$oid]);
        if (!$row) return new Response('Not found', 404);
        $this->db->delete('tooltip_overrides', 'id = ?', [$oid]);
        return Response::redirect('/admin/tooltips/' . (int) $row['tooltip_id'] . '/overrides')->withFlash('success', 'Override removed.');
    }
}
