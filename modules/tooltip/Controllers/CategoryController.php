<?php
// modules/tooltip/Controllers/CategoryController.php
namespace Modules\Tooltip\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Modules\Tooltip\Services\TooltipService;

/**
 * Tooltip category CRUD.
 *   GET  /admin/tooltips/categories
 *   POST /admin/tooltips/categories
 *   POST /admin/tooltips/categories/{id}
 *   POST /admin/tooltips/categories/{id}/delete
 */
class CategoryController
{
    private Database       $db;
    private TooltipService $svc;

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->svc = new TooltipService();
    }

    public function index(Request $request): Response
    {
        $rows = $this->db->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM tooltips t WHERE t.category_id = c.id) AS tooltip_count
               FROM tooltip_categories c ORDER BY c.sort_order ASC, c.name ASC"
        );
        return Response::view('tooltip::admin.categories', ['categories' => $rows]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '') return Response::redirect('/admin/tooltips/categories')->withFlash('error', 'Category name is required.');
        $this->db->insert('tooltip_categories', [
            'name'        => $name,
            'slug'        => $this->uniqueSlug((string) ($request->post('slug', '') ?: $name)),
            'description' => (string) $request->post('description', '') ?: null,
            'sort_order'  => (int) $request->post('sort_order', 0),
        ]);
        return Response::redirect('/admin/tooltips/categories')->withFlash('success', 'Category created.');
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->param(0);
        if (!$this->db->fetchOne("SELECT id FROM tooltip_categories WHERE id = ?", [$id])) return new Response('Not found', 404);
        $this->db->update('tooltip_categories', [
            'name'        => trim((string) $request->post('name', '')),
            'description' => (string) $request->post('description', '') ?: null,
            'sort_order'  => (int) $request->post('sort_order', 0),
        ], 'id = ?', [$id]);
        return Response::redirect('/admin/tooltips/categories')->withFlash('success', 'Category saved.');
    }

    public function delete(Request $request): Response
    {
        // FK ON DELETE SET NULL detaches tooltips; they aren't deleted.
        $this->db->delete('tooltip_categories', 'id = ?', [(int) $request->param(0)]);
        return Response::redirect('/admin/tooltips/categories')->withFlash('success', 'Category deleted.');
    }

    private function uniqueSlug(string $base): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
        $slug = trim((string) $slug, '-') ?: 'category';
        $slug = substr($slug, 0, 120);
        $try = $slug; $i = 1;
        while ($this->db->fetchOne("SELECT id FROM tooltip_categories WHERE slug = ?", [$try])) { $try = $slug . '-' . (++$i); }
        return $try;
    }
}
