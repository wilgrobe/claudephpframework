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
 * landing pages, and as of page-chrome Batch A every module page that
 * opts in via Response::withLayout()).
 *
 * Architectural mirror of pages.PageController's layout editor; the
 * placement form is structurally compatible with the page-layout
 * editor's _layout_row.php partial — extended in Batch A with a
 * placement_type selector + slot_name input so admins can add
 * "Page content" rows to a layout that wraps a controller view.
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
     * Index — every layout grouped by owning module, with friendly
     * name, category, content-slot count, and placement total. The
     * service handles the fallback to the pre-Batch-A column set when
     * the discoverability migration hasn't yet run on a given
     * install (see SystemLayoutService::listAll).
     */
    public function index(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $rows = $this->svc->listAll();

        // Group by module for the view. NULL/empty module bucket goes
        // last under the "Other" header so unattributed layouts stay
        // visible without dominating the top of the page.
        $grouped = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['module'] ?? '')) ?: '_other';
            $grouped[$key][] = $row;
        }
        // Move _other to the end if present.
        if (isset($grouped['_other'])) {
            $other = $grouped['_other'];
            unset($grouped['_other']);
            $grouped['_other'] = $other;
        }
        ksort($grouped);
        // Re-apply: ensure _other is last after ksort.
        if (isset($grouped['_other'])) {
            $other = $grouped['_other'];
            unset($grouped['_other']);
            $grouped['_other'] = $other;
        }

        return Response::view('admin.system_layouts.index', [
            'layouts' => $rows,
            'grouped' => $grouped,
            'user'    => $this->auth->user(),
        ]);
    }

    /**
     * GET /admin/system-layouts/{name}
     *
     * Editor — same form structure the page-layout editor uses, plus
     * a layout-metadata block (friendly name, module, category,
     * description) that admins can override on top of whatever a
     * module migration seeded.
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
                'name'          => $name,
                'friendly_name' => null,
                'module'        => null,
                'category'      => null,
                'description'   => null,
                'rows'          => 1,
                'cols'          => 2,
                'col_widths'    => [70, 27],
                'row_heights'   => [100],
                'gap_pct'       => 3,
                'max_width_px'  => 1280,
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
     * Single round-trip: saves layout metadata + grid + placements,
     * mirroring the page-layout editor's save semantics. Placements
     * marked _delete=1 OR with type='block' AND empty block_key are
     * dropped silently; content_slot rows are kept regardless of
     * block_key (the service will store the SLOT_SENTINEL).
     */
    public function save(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $name = $this->canonicalName($request->param(0));
        if ($name === null) {
            return Response::redirect('/admin/system-layouts')
                ->withFlash('error', 'Invalid system layout name.');
        }

        // Layout metadata is OPTIONAL on the form — we only forward
        // keys that were actually submitted so an admin who blanks
        // out the friendly-name field can clear it (empty string →
        // service coerces to NULL), but a missing input doesn't
        // overwrite a previously-stored value.
        $metaKeys = ['friendly_name', 'module', 'category', 'description'];
        $layoutInput = [
            'rows'         => $request->post('rows'),
            'cols'         => $request->post('cols'),
            'col_widths'   => $request->post('col_widths'),
            'row_heights'  => $request->post('row_heights'),
            'gap_pct'      => $request->post('gap_pct'),
            'max_width_px' => $request->post('max_width_px'),
        ];
        foreach ($metaKeys as $k) {
            $v = $request->post($k);
            if ($v !== null) $layoutInput[$k] = $v;
        }
        $this->svc->saveLayout($name, $layoutInput);

        $rawPlacements = (array) $request->post('placements', []);
        $clean = [];
        foreach ($rawPlacements as $p) {
            if (!empty($p['_delete'])) continue;

            $type = (string) ($p['placement_type'] ?? 'block');
            if (!in_array($type, ['block','content_slot'], true)) $type = 'block';

            // Drop block-type rows with no block_key. Slot rows are
            // always kept — the slot_name (defaulting to 'primary')
            // is meaningful even when the admin leaves the block
            // dropdown blank.
            if ($type === 'block' && empty($p['block_key'])) continue;

            $entry = [
                'row'            => $p['row']        ?? 0,
                'col'            => $p['col']        ?? 0,
                'sort_order'     => $p['sort_order'] ?? 0,
                'placement_type' => $type,
                'block_key'      => $p['block_key']  ?? '',
                'settings'       => $p['settings']   ?? null,
                'visible_to'     => $p['visible_to'] ?? 'any',
            ];
            if ($type === 'content_slot') {
                $entry['slot_name'] = $p['slot_name'] ?? '';
            }
            $clean[] = $entry;
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
     * are limited to letters, digits, underscores, dots, hyphens so
     * they can't host SQL injection or weird filesystem-y characters.
     * Length caps to the 64-char column width.
     *
     * Dots and hyphens are allowed so layout slugs can mirror the URL
     * of the page they chrome — e.g. `account.data` for `/account/data`,
     * `account.email-preferences` for `/account/email-preferences`.
     * The dot replaces the slash, the hyphen passes through verbatim.
     * That gives admins a slug that's pattern-matchable to the URL
     * instead of a cryptic module-internal ident.
     *
     * The pre-Batch-A regex only permitted alphanumerics + underscores;
     * Batch A added dots; Batch C adds hyphens. Expanding the
     * character class is safe because the column has no FULLTEXT
     * index and the value is always passed as a prepared parameter.
     */
    private function canonicalName(mixed $raw): ?string
    {
        $name = trim((string) $raw);
        if ($name === '' || mb_strlen($name) > 64) return null;
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) return null;
        // Don't allow leading/trailing dot or hyphen, or consecutive
        // separators — the canonical form is `prefix.layout-name`.
        if (str_starts_with($name, '.') || str_ends_with($name, '.')) return null;
        if (str_starts_with($name, '-') || str_ends_with($name, '-')) return null;
        if (str_contains($name, '..') || str_contains($name, '--')) return null;
        return $name;
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
    }
}
