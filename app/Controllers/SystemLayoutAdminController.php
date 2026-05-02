<?php
// app/Controllers/SystemLayoutAdminController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Container\Container;
use Core\Database\Database;
use Core\Module\BlockRegistry;
use Core\Request;
use Core\Response;
use Core\Services\SystemLayoutService;

/**
 * /admin/system-layouts — manage layouts attached to surfaces that
 * aren't rows in the `pages` table (the dashboard, future admin
 * landing pages, etc).
 *
 * Architectural mirror of pages.PageController's layout editor; the
 * placement form is identical (row/col/sort_order/block_key/settings/
 * visible_to/_delete), so the same `_layout_row.php` partial is reused.
 *
 * Read-only "create new system layout" surface NOT included in v1 —
 * system layouts are seeded by migrations (one per surface the
 * framework knows about). Admins edit the seeded layouts; the set is
 * fixed by what code references it.
 *
 * Gated by RequireSuperadmin at the route layer; this controller adds
 * a defensive double-check.
 */
class SystemLayoutAdminController
{
    private Auth                $auth;
    private Database            $db;
    private SystemLayoutService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
        $this->svc  = new SystemLayoutService();
    }

    /**
     * GET /admin/system-layouts
     *
     * List every row in system_layouts. The seed migration shipped two:
     * `dashboard_stats` (top stats strip) and `dashboard_main` (content +
     * sidebar). Future migrations may add more. For each, show row/col
     * counts + placement count so admins can pick the one to edit.
     */
    public function index(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $rows = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT sl.name, sl.`rows`, sl.cols, sl.gap_pct, sl.max_width_px, sl.updated_at,
                        (SELECT COUNT(*) FROM system_block_placements sbp WHERE sbp.system_name = sl.name) AS placement_count
                   FROM system_layouts sl
                  ORDER BY sl.name"
            );
        } catch (\Throwable) {
            // Tables missing on a fresh install — show an empty list
            // and let the admin run migrations.
        }

        return Response::view('admin.system_layouts.index', [
            'layouts' => $rows,
            'user'    => $this->auth->user(),
        ]);
    }

    /**
     * GET /admin/system-layouts/{name}
     *
     * Editor — same form structure the page-layout editor uses.
     */
    public function edit(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $name = $this->canonicalName($request->param(0));
        if ($name === null) {
            return Response::redirect('/admin/system-layouts')
                ->withFlash('error', 'Invalid system layout name.');
        }

        $composer = $this->svc->get($name);
        if ($composer === null) {
            // No row yet — let the admin save with defaults and create
            // the row on first POST. Pre-fill the form with the same
            // defaults PageLayoutService uses for new pages.
            $layout = [
                'rows'         => 1,
                'cols'         => 2,
                'col_widths'   => [70, 27],
                'row_heights'  => [100],
                'gap_pct'      => 3,
                'max_width_px' => 1280,
            ];
            $placements = [];
        } else {
            $layout     = $composer['layout'];
            $placements = $composer['placements'];
        }

        /** @var BlockRegistry $registry */
        $registry         = Container::global()->get(BlockRegistry::class);
        $blocksByCategory = $registry->byCategory();

        return Response::view('admin.system_layouts.edit', [
            'name'             => $name,
            'layout'           => $layout,
            'placements'       => $placements,
            'hasLayout'        => $composer !== null,
            'blocksByCategory' => $blocksByCategory,
            'user'             => $this->auth->user(),
        ]);
    }

    /**
     * POST /admin/system-layouts/{name}
     *
     * Single round-trip: saves layout + placements, mirroring the
     * page-layout editor's save semantics (placements with empty
     * block_key or _delete=1 are dropped).
     */
    public function save(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $name = $this->canonicalName($request->param(0));
        if ($name === null) {
            return Response::redirect('/admin/system-layouts')
                ->withFlash('error', 'Invalid system layout name.');
        }

        $this->svc->saveLayout($name, [
            'rows'         => $request->post('rows'),
            'cols'         => $request->post('cols'),
            'col_widths'   => $request->post('col_widths'),
            'row_heights'  => $request->post('row_heights'),
            'gap_pct'      => $request->post('gap_pct'),
            'max_width_px' => $request->post('max_width_px'),
        ]);

        $rawPlacements = (array) $request->post('placements', []);
        $clean = [];
        foreach ($rawPlacements as $p) {
            if (!empty($p['_delete']))   continue;
            if (empty($p['block_key']))  continue;
            $clean[] = [
                'row'        => $p['row']        ?? 0,
                'col'        => $p['col']        ?? 0,
                'sort_order' => $p['sort_order'] ?? 0,
                'block_key'  => $p['block_key'],
                'settings'   => $p['settings']   ?? null,
                'visible_to' => $p['visible_to'] ?? 'any',
            ];
        }
        $this->svc->savePlacements($name, $clean);

        $this->auth->auditLog('system_layout.save', 'system_layouts', null, null, [
            'name'            => $name,
            'placement_count' => count($clean),
        ]);

        return Response::redirect("/admin/system-layouts/" . rawurlencode($name))
            ->withFlash('success', "Layout \"$name\" saved.");
    }

    /**
     * Canonicalise + validate a system layout name from the URL. Names
     * are limited to letters, digits, underscores so they can't host
     * SQL injection or weird filesystem-y characters. Length caps to
     * the 64-char column width.
     */
    private function canonicalName(mixed $raw): ?string
    {
        $name = trim((string) $raw);
        if ($name === '' || mb_strlen($name) > 64) return null;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) return null;
        return $name;
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
    }
}
