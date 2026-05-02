<?php
// modules/hierarchies/Controllers/HierarchiesAdminController.php
namespace Modules\Hierarchies\Controllers;

use Core\Request;
use Core\Response;
use Modules\Hierarchies\Services\HierarchyService;

/**
 * Admin surface for hierarchies.
 *
 *   GET  /admin/hierarchies                       — list hierarchies
 *   GET  /admin/hierarchies/create                — new hierarchy form
 *   POST /admin/hierarchies/create                — create
 *   GET  /admin/hierarchies/{slug}                — tree editor
 *   POST /admin/hierarchies/{id}/delete           — delete hierarchy
 *   POST /admin/hierarchies/{id}/nodes            — add a node
 *   POST /admin/hierarchies/nodes/{id}/update     — edit label/url/etc
 *   POST /admin/hierarchies/nodes/{id}/delete     — delete subtree
 *   POST /admin/hierarchies/nodes/{id}/move       — change parent
 *   POST /admin/hierarchies/nodes/reorder         — drag-drop reorder batch
 */
class HierarchiesAdminController
{
    private HierarchyService $svc;

    public function __construct()
    {
        $this->svc = new HierarchyService();
    }

    // ── Hierarchy-level ──────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return Response::view('hierarchies::admin.index', [
            'hierarchies' => $this->svc->allHierarchies(),
        ]);
    }

    public function createForm(Request $request): Response
    {
        return Response::view('hierarchies::admin.form', ['hierarchy' => null]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->post('name'));
        $slug = trim((string) $request->post('slug'));
        if ($name === '' || $slug === '') {
            return Response::redirect('/admin/hierarchies/create')
                ->withFlash('error', 'Name and slug required.');
        }
        $desc = (string) $request->post('description');
        $id = $this->svc->createHierarchy($name, $slug, $desc !== '' ? $desc : null);
        return Response::redirect('/admin/hierarchies/' . HierarchyService::slugify($slug))
            ->withFlash('success', 'Hierarchy created.');
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->param(0);
        $h    = $this->svc->findHierarchyBySlug($slug);
        if (!$h) return new Response('Not found', 404);

        return Response::view('hierarchies::admin.show', [
            'hierarchy' => $h,
            'tree'      => $this->svc->tree((int) $h['id']),
        ]);
    }

    public function deleteHierarchy(Request $request): Response
    {
        $id = (int) $request->param(0);
        $this->svc->deleteHierarchy($id);
        return Response::redirect('/admin/hierarchies')
            ->withFlash('success', 'Hierarchy deleted.');
    }

    // ── Node-level ───────────────────────────────────────────────────────

    public function addNode(Request $request): Response
    {
        $hierarchyId = (int) $request->param(0);
        $hierarchy = $this->svc->findHierarchy($hierarchyId);
        if (!$hierarchy) return new Response('Not found', 404);

        $parentId = (int) $request->post('parent_id', 0) ?: null;
        $id = $this->svc->addNode($hierarchyId, $parentId, [
            'label' => (string) $request->post('label'),
            'slug'  => (string) $request->post('slug'),
            'url'   => (string) $request->post('url') ?: null,
            'icon'  => (string) $request->post('icon') ?: null,
            'color' => (string) $request->post('color') ?: null,
        ]);
        return Response::redirect('/admin/hierarchies/' . $hierarchy['slug'])
            ->withFlash('success', "Node added.");
    }

    public function updateNode(Request $request): Response
    {
        $id   = (int) $request->param(0);
        $node = $this->svc->findNode($id);
        if (!$node) return new Response('Not found', 404);

        $this->svc->updateNode($id, [
            'label' => (string) $request->post('label', $node['label']),
            'slug'  => (string) $request->post('slug',  $node['slug']),
            'url'   => (string) $request->post('url',   $node['url']),
            'icon'  => (string) $request->post('icon',  $node['icon']),
            'color' => (string) $request->post('color', $node['color']),
        ]);

        $h = $this->svc->findHierarchy((int) $node['hierarchy_id']);
        return Response::redirect('/admin/hierarchies/' . ($h['slug'] ?? ''));
    }

    public function deleteNode(Request $request): Response
    {
        $id   = (int) $request->param(0);
        $node = $this->svc->findNode($id);
        if (!$node) return Response::redirect('/admin/hierarchies');
        $h = $this->svc->findHierarchy((int) $node['hierarchy_id']);
        $this->svc->deleteNode($id);
        return Response::redirect('/admin/hierarchies/' . ($h['slug'] ?? ''))
            ->withFlash('success', 'Node (and its subtree) deleted.');
    }

    public function moveNode(Request $request): Response
    {
        $id          = (int) $request->param(0);
        $newParentId = (int) $request->post('new_parent_id', 0) ?: null;

        try {
            $this->svc->moveNode($id, $newParentId);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * Accepts POST body `ids[]` in the desired order. Intended for
     * drag-and-drop drops within a sibling group — the client posts the
     * new sibling order as an array of ids and the server rewrites
     * `sort_order`.
     */
    public function reorder(Request $request): Response
    {
        $ids = $request->post('ids');
        if (!is_array($ids)) return Response::json(['ok' => false, 'error' => 'ids required'], 400);
        $this->svc->reorderSiblings(array_map('intval', $ids));
        return Response::json(['ok' => true]);
    }
}
