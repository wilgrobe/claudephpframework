<?php
// modules/taxonomy/Controllers/TaxonomyAdminController.php
namespace Modules\Taxonomy\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Modules\Taxonomy\Services\TaxonomyService;

/**
 * Admin surface for taxonomy: list and edit sets (vocabularies), and
 * manage the terms within each set. Gated by Auth + RequireAdmin at
 * route registration; per-method permission check for defense in depth.
 *
 * Term create + delete are supported here; moving a term to a different
 * parent is NOT (see TaxonomyService comment). Admins can delete + recreate
 * as a workaround.
 */
class TaxonomyAdminController
{
    private Auth            $auth;
    private Database        $db;
    private TaxonomyService $tax;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
        $this->tax  = new TaxonomyService();
    }

    // ── Sets list + CRUD ──────────────────────────────────────────────────

    public function setsIndex(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        // Attach a term count per set for the overview table.
        $sets = $this->tax->allSets();
        foreach ($sets as &$s) {
            $s['term_count'] = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM taxonomy_terms WHERE set_id = ?",
                [(int) $s['id']]
            );
        }
        unset($s);

        return Response::view('taxonomy::admin.sets.index', [
            'sets' => $sets,
            'user' => $this->auth->user(),
        ]);
    }

    public function setCreate(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();
        return Response::view('taxonomy::admin.sets.form', [
            'set'  => null,
            'user' => $this->auth->user(),
        ]);
    }

    public function setStore(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        $v = new Validator($request->post());
        $v->validate([
            'name' => 'required|min:2|max:191',
            'slug' => 'required|min:2|max:120',
        ]);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            Session::flash('old', $v->all());
            return Response::redirect('/admin/taxonomy/sets/create');
        }

        $slug = $this->sanitizeSlug((string) $v->get('slug'));
        if ($this->tax->findSetBySlug($slug)) {
            Session::flash('errors', ['slug' => ['That slug is already in use.']]);
            Session::flash('old', $v->all());
            return Response::redirect('/admin/taxonomy/sets/create');
        }

        $id = $this->tax->createSet(
            (string) $v->get('name'),
            $slug,
            !empty($request->post('allow_hierarchy')),
            $request->post('description')
        );
        $this->auth->auditLog('taxonomy.set.create', 'taxonomy_sets', $id);
        return Response::redirect("/admin/taxonomy/sets/$id")
            ->withFlash('success', 'Vocabulary created. Add terms below.');
    }

    public function setEdit(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        $id  = (int) $request->param(0);
        $set = $this->tax->findSet($id);
        if (!$set) return new Response('Vocabulary not found', 404);

        $terms = $this->tax->termsInSet($id);
        $tree  = $this->tax->buildTree($terms);

        return Response::view('taxonomy::admin.sets.terms', [
            'set'   => $set,
            'terms' => $terms,
            'tree'  => $tree,
            'user'  => $this->auth->user(),
        ]);
    }

    public function setUpdate(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        $id  = (int) $request->param(0);
        $set = $this->tax->findSet($id);
        if (!$set) return new Response('Vocabulary not found', 404);

        $v = new Validator($request->post());
        $v->validate([
            'name' => 'required|min:2|max:191',
            'slug' => 'required|min:2|max:120',
        ]);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect("/admin/taxonomy/sets/$id");
        }

        $slug  = $this->sanitizeSlug((string) $v->get('slug'));
        $clash = $this->db->fetchOne("SELECT id FROM taxonomy_sets WHERE slug = ? AND id <> ?", [$slug, $id]);
        if ($clash) {
            Session::flash('errors', ['slug' => ['That slug is already in use.']]);
            return Response::redirect("/admin/taxonomy/sets/$id");
        }

        $this->tax->updateSet(
            $id,
            (string) $v->get('name'),
            $slug,
            !empty($request->post('allow_hierarchy')),
            $request->post('description')
        );
        $this->auth->auditLog('taxonomy.set.update', 'taxonomy_sets', $id);
        return Response::redirect("/admin/taxonomy/sets/$id")
            ->withFlash('success', 'Vocabulary saved.');
    }

    public function setDelete(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();
        $id = (int) $request->param(0);
        if (!$this->tax->findSet($id)) return new Response('Not found', 404);

        $this->tax->deleteSet($id);
        $this->auth->auditLog('taxonomy.set.delete', 'taxonomy_sets', $id);
        return Response::redirect('/admin/taxonomy/sets')
            ->withFlash('success', 'Vocabulary and all its terms deleted.');
    }

    // ── Terms ─────────────────────────────────────────────────────────────

    /** POST /admin/taxonomy/sets/{setId}/terms */
    public function termStore(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        $setId = (int) $request->param(0);
        $set   = $this->tax->findSet($setId);
        if (!$set) return new Response('Vocabulary not found', 404);

        $name = trim((string) $request->post('name', ''));
        $slug = $this->sanitizeSlug((string) ($request->post('slug') ?: $name));
        if ($name === '' || $slug === '') {
            return Response::redirect("/admin/taxonomy/sets/$setId")
                ->withFlash('error', 'Term name and slug are required.');
        }

        // Slug uniqueness within the set.
        if ($this->db->fetchOne("SELECT id FROM taxonomy_terms WHERE set_id = ? AND slug = ?", [$setId, $slug])) {
            return Response::redirect("/admin/taxonomy/sets/$setId")
                ->withFlash('error', 'That slug is already used in this vocabulary.');
        }

        $parentId = null;
        if ($request->post('parent_id') !== null && $request->post('parent_id') !== '') {
            $parentId = (int) $request->post('parent_id');
        }

        try {
            $id = $this->tax->createTerm(
                setId:       $setId,
                name:        $name,
                slug:        $slug,
                parentId:    $parentId,
                description: $request->post('description'),
                sortOrder:   (int) $request->post('sort_order', 0),
            );
        } catch (\InvalidArgumentException $e) {
            return Response::redirect("/admin/taxonomy/sets/$setId")
                ->withFlash('error', $e->getMessage());
        }

        $this->auth->auditLog('taxonomy.term.create', 'taxonomy_terms', $id);
        return Response::redirect("/admin/taxonomy/sets/$setId")
            ->withFlash('success', 'Term added.');
    }

    /** POST /admin/taxonomy/terms/{id}/delete */
    public function termDelete(Request $request): Response
    {
        if ($this->auth->cannot('taxonomy.manage')) return $this->denied();

        $termId = (int) $request->param(0);
        $term   = $this->tax->findTerm($termId);
        if (!$term) return new Response('Term not found', 404);

        $setId = (int) $term['set_id'];
        $this->tax->deleteTerm($termId);
        $this->auth->auditLog('taxonomy.term.delete', 'taxonomy_terms', $termId);
        return Response::redirect("/admin/taxonomy/sets/$setId")
            ->withFlash('success', 'Term deleted (with any children).');
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function denied(): Response
    {
        return Response::redirect('/dashboard')
            ->withFlash('error', 'You do not have permission to manage taxonomy.');
    }

    private function sanitizeSlug(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim((string) $slug, '-');
    }
}
