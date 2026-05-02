<?php
// modules/pages/Controllers/PageController.php
namespace Modules\Pages\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Database\Database;
use Core\SEO\SeoManager;
use Core\Services\SettingsService;

/**
 * Admin CRUD for static pages. Ported from App\Controllers\Admin\PageController.
 * Behavior is byte-for-byte identical; the only changes are:
 *   - namespace moved under Modules\Pages\Controllers
 *   - Response::view() calls use the 'pages::' namespace
 *
 * Everything else (validation rules, SEO redirect registration, home-page
 * reconciliation, audit logging) is unchanged.
 */
class PageController
{
    private Database        $db;
    private Auth            $auth;
    private SeoManager      $seo;
    private SettingsService $settings;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->auth     = Auth::getInstance();
        $this->seo      = new SeoManager();
        $this->settings = new SettingsService();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $pages = $this->db->fetchAll("SELECT * FROM pages ORDER BY sort_order, title");
        return Response::view('pages::admin.index', ['pages' => $pages, 'user' => $this->auth->user()]);
    }

    public function create(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        // Composer is disabled on create until the page exists (no id to
        // attach placements to). The view branches on $page === null.
        return Response::view('pages::admin.form', [
            'page'             => null,
            'layout'           => null,
            'placements'       => [],
            'hasLayout'        => false,
            'blocksByCategory' => [],
            'user'             => $this->auth->user(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $v = new Validator($request->post());
        $v->validate([
            'title'  => 'required|min:2|max:500',
            'slug'   => 'required|min:2|max:500',
            'status' => 'required|in:draft,published',
        ]);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/admin/pages/create');
        }
        $slug          = $v->get('slug');
        $sanitizedBody = Validator::sanitizeHtml($request->post('body', ''));
        $status        = $v->get('status');
        $isPublic      = (int) $request->post('is_public', 1);
        $featured      = (int) $request->post('featured', 0);

        $id = $this->db->insert('pages', [
            'title'           => $v->get('title'),
            'slug'            => $slug,
            'body'            => $sanitizedBody,
            'layout'          => $request->post('layout', 'default'),
            'status'          => $status,
            'is_public'       => $isPublic,
            'featured'        => $featured,
            'seo_title'       => $v->get('seo_title'),
            'seo_description' => $v->get('seo_description'),
            'seo_keywords'    => $v->get('seo_keywords'),
            'sort_order'      => (int) $request->post('sort_order', 0),
            'created_by'      => $this->auth->id(),
            'published_at'    => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        // Register the canonical path so slug changes later can leave a 301
        // breadcrumb in seo_links.
        $this->seo->register($slug, "/$slug", 'page', $id);
        app(\Core\Services\SearchIndexer::class)->sync('pages', $id, [
            'id'        => $id,
            'title'     => $v->get('title'),
            'slug'      => $slug,
            'body'      => $sanitizedBody,
            'status'    => $status,
            'is_public' => $isPublic,
        ]);

        $this->syncHomePageSetting($slug, (bool) $request->post('is_home_page', 0));

        $this->auth->auditLog('page.create', 'pages', $id);
        return Response::redirect('/admin/pages')->withFlash('success', 'Page created.');
    }

    public function edit(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $id   = (int) $request->param(0);
        $page = $this->db->fetchOne("SELECT * FROM pages WHERE id = ?", [$id]);
        if (!$page) return new Response('Not found', 404);

        // Layout + composer data, merged into the same form so admins
        // edit page meta + grid + placements in one round-trip. The
        // legacy /admin/pages/{id}/layout route still 301s here for
        // back-compat (see routes.php).
        $svc      = new \Core\Services\PageLayoutService();
        $composer = $svc->getForPage($id);
        $layout = $composer['layout'] ?? [
            'rows'         => \Core\Services\PageLayoutService::DEFAULT_ROWS,
            'cols'         => \Core\Services\PageLayoutService::DEFAULT_COLS,
            'col_widths'   => \Core\Services\PageLayoutService::DEFAULT_COL_WIDTHS,
            'row_heights'  => \Core\Services\PageLayoutService::DEFAULT_ROW_HEIGHTS,
            'gap_pct'      => \Core\Services\PageLayoutService::DEFAULT_GAP_PCT,
            'max_width_px' => \Core\Services\PageLayoutService::DEFAULT_MAX_WIDTH_PX,
        ];
        $placements = $composer['placements'] ?? [];

        /** @var \Core\Module\BlockRegistry $registry */
        $registry         = \Core\Container\Container::global()->get(\Core\Module\BlockRegistry::class);
        $blocksByCategory = $registry->byCategory();

        return Response::view('pages::admin.form', [
            'page'             => $page,
            'layout'           => $layout,
            'placements'       => $placements,
            'hasLayout'        => $composer !== null,
            'blocksByCategory' => $blocksByCategory,
            'user'             => $this->auth->user(),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $id   = (int) $request->param(0);
        $page = $this->db->fetchOne("SELECT * FROM pages WHERE id = ?", [$id]);
        if (!$page) return new Response('Not found', 404);

        $v = new Validator($request->post());
        $v->validate([
            'title'  => 'required|min:2|max:500',
            'slug'   => 'required|min:2|max:500',
            'status' => 'required|in:draft,published',
        ]);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect("/admin/pages/$id/edit");
        }

        $oldSlug       = $page['slug'];
        $newSlug       = $v->get('slug');
        $sanitizedBody = Validator::sanitizeHtml($request->post('body', ''));
        $newStatus     = $v->get('status');
        $newIsPublic   = (int) $request->post('is_public', 1);
        $newFeatured   = (int) $request->post('featured', 0);

        $this->db->update('pages', [
            'title'           => $v->get('title'),
            'slug'            => $newSlug,
            'body'            => $sanitizedBody,
            'layout'          => $request->post('layout', 'default'),
            'status'          => $newStatus,
            'is_public'       => $newIsPublic,
            'featured'        => $newFeatured,
            'seo_title'       => $v->get('seo_title'),
            'seo_description' => $v->get('seo_description'),
            'seo_keywords'    => $v->get('seo_keywords'),
        ], 'id = ?', [$id]);

        // If slug changed, register the new canonical path and leave a 301
        // from the old slug so bookmarks / external links keep working.
        if ($oldSlug !== $newSlug) {
            $this->seo->redirect($oldSlug, "/$newSlug");
            $this->seo->register($newSlug, "/$newSlug", 'page', $id);

            // If the renamed page was the guest home page, roll the setting
            // forward to the new slug so the homepage doesn't silently break.
            if ($this->settings->get('guest_home_page_slug', '', 'site') === $oldSlug) {
                $this->settings->set('guest_home_page_slug', $newSlug, 'site');
            }
        }

        $this->syncHomePageSetting($newSlug, (bool) $request->post('is_home_page', 0));

        app(\Core\Services\SearchIndexer::class)->sync('pages', $id, [
            'id'        => $id,
            'title'     => $v->get('title'),
            'slug'      => $newSlug,
            'body'      => $sanitizedBody,
            'status'    => $newStatus,
            'is_public' => $newIsPublic,
        ]);

        // Layout + placements (folded in from the old /layout endpoint).
        // Form posts these unconditionally now that edit + composer
        // share a view; saveLayout's idempotent so a no-op submit is
        // fine. The legacy saveLayout method still exists for callers
        // that hit /admin/pages/{id}/layout directly.
        $this->saveLayoutAndPlacements($id, $request);

        $this->auth->auditLog('page.update', 'pages', $id);
        return Response::redirect("/admin/pages/$id/edit")->withFlash('success', 'Page updated.');
    }

    /**
     * Persist the layout grid + placements from a combined edit-form
     * POST. Pulled out of update() so the legacy saveLayout endpoint
     * can reuse the same logic.
     */
    private function saveLayoutAndPlacements(int $id, Request $request): void
    {
        // Only run when the form actually carried layout fields. The
        // create form omits them (no id to attach to yet) so we can't
        // assume they're present.
        if ($request->post('rows') === null) return;

        $svc = new \Core\Services\PageLayoutService();
        $svc->saveLayout($id, [
            'rows'         => $request->post('rows'),
            'cols'         => $request->post('cols'),
            'col_widths'   => $request->post('col_widths'),
            'row_heights'  => $request->post('row_heights'),
            'gap_pct'      => $request->post('gap_pct'),
            'max_width_px' => $request->post('max_width_px'),
            // The composer JS posts these as JSON strings — the service
            // accepts string-or-array via coerceJsonInput. Sanitiser
            // strips anything that doesn't pass the color/url whitelist.
            'row_styles'   => $request->post('row_styles'),
            'cell_styles'  => $request->post('cell_styles'),
        ]);

        $rawPlacements = (array) $request->post('placements', []);
        $clean = [];
        foreach ($rawPlacements as $p) {
            // Drop rows the admin marked for delete, or that have no
            // block selected — the form posts both for layout simplicity.
            if (!empty($p['_delete']))   continue;
            if (empty($p['block_key']))  continue;
            $clean[] = [
                'row'        => $p['row']        ?? 0,
                'col'        => $p['col']        ?? 0,
                'sort_order' => $p['sort_order'] ?? 0,
                'block_key'  => $p['block_key'],
                'settings'   => $p['settings']   ?? null, // string or array
                'style'      => $p['style']      ?? null, // string or array
                'visible_to' => $p['visible_to'] ?? 'any',
            ];
        }
        $svc->savePlacements($id, $clean);
    }

    public function delete(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $id   = (int) $request->param(0);
        $page = $this->db->fetchOne("SELECT slug FROM pages WHERE id = ?", [$id]);

        $this->db->delete('pages', 'id = ?', [$id]);
        app(\Core\Services\SearchIndexer::class)->sync('pages', $id);

        // If we just deleted the guest home page, clear the setting so /
        // falls back to /login instead of serving a 404-like orphan.
        if ($page && $this->settings->get('guest_home_page_slug', '', 'site') === $page['slug']) {
            $this->settings->delete('guest_home_page_slug', 'site');
        }

        $this->auth->auditLog('page.delete', 'pages', $id);
        return Response::redirect('/admin/pages')->withFlash('success', 'Page deleted.');
    }

    // Public rendering is still served by the /{slug} catch-all in
    // routes/web.php (it interacts with SEO redirects).

    /**
     * Reconcile the "is_home_page" form checkbox with the site setting.
     * See original App\Controllers\Admin\PageController for full behavior notes.
     */
    private function syncHomePageSetting(string $slug, bool $wantHome): void
    {
        $current = (string) $this->settings->get('guest_home_page_slug', '', 'site');

        if ($wantHome) {
            if ($current !== $slug) {
                $this->settings->set('guest_home_page_slug', $slug, 'site');
            }
            return;
        }
        if ($current === $slug) {
            $this->settings->delete('guest_home_page_slug', 'site');
        }
    }

    /**
     * GET /admin/pages/{id}/layout
     *
     * Layout + placement editor for the page composer (Batch 2 of the
     * content-blocks rollout). When the page has no layout row yet,
     * preload the form with the service's defaults so saving once gives
     * the admin a working 2x2 grid to drop blocks into.
     */
    public function editLayout(Request $request): Response
    {
        // Composer is folded into /admin/pages/{id}/edit as of the
        // 2026-04-28 layout-editor UX pass. Old bookmarks land on the
        // composer anchor of the unified edit page.
        $id = (int) $request->param(0);
        return Response::redirect("/admin/pages/$id/edit#composer", 301);
    }

    /**
     * POST /admin/pages/{id}/layout
     *
     * Saves the layout grid AND the placements in a single round-trip.
     * Form posts:
     *   rows, cols, col_widths (csv), row_heights (csv), gap_pct, max_width_px
     *   placements[] = [row, col, sort_order, block_key, settings_json, visible_to]
     *     (rows with empty block_key are silently dropped — that's how
     *      the "delete this row" checkbox works without a special action)
     */
    public function saveLayout(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $id   = (int) $request->param(0);
        $page = $this->db->fetchOne("SELECT id FROM pages WHERE id = ?", [$id]);
        if (!$page) return new Response('Not found', 404);

        // Back-compat — the unified update() handler now does this work.
        // We keep this method routed for any external scripts still
        // POSTing here, but the implementation just delegates so behavior
        // stays identical to the new path.
        $this->saveLayoutAndPlacements($id, $request);

        $this->auth->auditLog('page.layout_save', 'pages', $id);
        return Response::redirect("/admin/pages/$id/edit#composer")
            ->withFlash('success', 'Layout saved.');
    }


    /**
     * POST /admin/pages/{id}/layout/delete
     *
     * Wipes the layout + every placement, restoring the page to body-only
     * rendering. Used when an admin decides the composer view isn't right
     * for this page after all.
     */
    public function deleteLayout(Request $request): Response
    {
        if ($this->auth->cannot('pages.manage')) return $this->denied();
        $id = (int) $request->param(0);

        (new \Core\Services\PageLayoutService())->deleteLayout($id);
        $this->auth->auditLog('page.layout_delete', 'pages', $id);

        return Response::redirect("/admin/pages/$id/edit")
            ->withFlash('success', 'Layout removed; page now renders body content.');
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Access denied.');
    }
}
