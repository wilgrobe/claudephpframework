<?php
// modules/tooltip/Controllers/TooltipAdminController.php
namespace Modules\Tooltip\Controllers;

use Core\Auth\Auth;
use Core\Module\SubmoduleRegistry;
use Core\Request;
use Core\Response;
use Modules\Tooltip\Services\TooltipRenderer;
use Modules\Tooltip\Services\TooltipService;

/**
 * Admin CRUD for tooltips.
 *   GET  /admin/tooltips                 — list (search + category filter)
 *   GET  /admin/tooltips/create          — new form
 *   POST /admin/tooltips                 — create
 *   GET  /admin/tooltips/{id}            — edit + live preview
 *   POST /admin/tooltips/{id}            — update
 *   POST /admin/tooltips/{id}/delete
 *   POST /admin/tooltips/{id}/toggle-active
 */
class TooltipAdminController
{
    private Auth           $auth;
    private TooltipService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new TooltipService();
    }

    public function index(Request $request): Response
    {
        $categoryId = (int) $request->query('category', 0) ?: null;
        $search     = (string) $request->query('q', '');
        $page       = max(1, (int) $request->query('page', 1));
        $result = $this->svc->listForAdmin($categoryId, $search, $page);
        return Response::view('tooltip::admin.index', [
            'result'      => $result,
            'categories'  => $this->svc->categories(),
            'categoryId'  => $categoryId,
            'search'      => $search,
            'analyticsOn' => SubmoduleRegistry::featureEnabled('tooltip', 'analytics'),
            'overridesOn' => SubmoduleRegistry::featureEnabled('tooltip', 'per-page-overrides'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function show(Request $request): Response
    {
        $tip = $this->svc->find((int) $request->param(0));
        if (!$tip) return new Response('Tooltip not found', 404);
        return $this->form($tip);
    }

    private function form(?array $tip): Response
    {
        $renderer = new TooltipRenderer();
        $preview = $tip ? $renderer->renderInline($tip, (string) $tip['label'], ['icon' => 'ℹ']) : '';
        return Response::view('tooltip::admin.form', [
            'tip'         => $tip,
            'categories'  => $this->svc->categories(),
            'preview'     => $preview,
            'richOn'      => SubmoduleRegistry::featureEnabled('tooltip', 'rich-content'),
            'overridesOn' => SubmoduleRegistry::featureEnabled('tooltip', 'per-page-overrides'),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->svc->create((int) $this->auth->id(), $this->payload($request));
            $this->audit('tooltip.create', $id);
        } catch (\InvalidArgumentException $e) {
            return Response::redirect('/admin/tooltips/create')->withFlash('error', $e->getMessage());
        }
        return Response::redirect('/admin/tooltips/' . $id)->withFlash('success', 'Tooltip created.');
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param(0);
        if (!$this->svc->find($id)) return new Response('Tooltip not found', 404);
        $this->svc->update($id, (int) $this->auth->id(), $this->payload($request));
        $this->audit('tooltip.update', $id);
        return Response::redirect('/admin/tooltips/' . $id)->withFlash('success', 'Tooltip saved.');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->param(0);
        $this->svc->delete($id);
        $this->audit('tooltip.delete', $id);
        return Response::redirect('/admin/tooltips')->withFlash('success', 'Tooltip deleted.');
    }

    public function toggleActive(Request $request): Response
    {
        $id = (int) $request->param(0);
        if (!$this->svc->find($id)) return new Response('Tooltip not found', 404);
        $this->svc->toggleActive($id);
        $this->audit('tooltip.toggle_active', $id);
        return Response::redirect('/admin/tooltips')->withFlash('success', 'Tooltip status updated.');
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        // rich-content off → force plain (the renderer strips tags anyway, but
        // store the intent so re-enabling rich content doesn't surprise).
        $format = (string) $request->post('content_format', 'markdown');
        if (!SubmoduleRegistry::featureEnabled('tooltip', 'rich-content')) $format = 'plain';
        return [
            'slug'           => (string) $request->post('slug', ''),
            'label'          => (string) $request->post('label', ''),
            'content_html'   => (string) $request->post('content_html', ''),
            'content_format' => $format,
            'category_id'    => $request->post('category_id', ''),
            'placement'      => (string) $request->post('placement', 'auto'),
            'theme'          => (string) $request->post('theme', 'default'),
            'trigger'        => (string) $request->post('trigger', 'hover'),
            'max_width_px'   => (int) $request->post('max_width_px', 280),
            'show_delay_ms'  => (int) $request->post('show_delay_ms', 200),
            'hide_delay_ms'  => (int) $request->post('hide_delay_ms', 100),
            'is_active'      => $request->post('is_active', '1') ? 1 : 0,
        ];
    }

    private function audit(string $action, int $id): void
    {
        try { $this->auth->auditLog($action, 'tooltips', $id); } catch (\Throwable) { /* best-effort */ }
    }
}
