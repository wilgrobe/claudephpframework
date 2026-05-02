<?php
// modules/faq/Controllers/FaqController.php
namespace Modules\Faq\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Database\Database;
use Core\Validation\Validator as V;

/**
 * Admin CRUD + public renderer for FAQ. Ported from
 * App\Controllers\Admin\FaqController. Logic is unchanged; view names are
 * rewritten to the 'faq::' namespace so they resolve from modules/faq/Views/.
 */
class FaqController
{
    private Database $db;
    private Auth     $auth;

    public function __construct()
    {
        $this->db   = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    // ── Admin: Categories ─────────────────────────────────────────────────────

    public function categories(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $cats = $this->db->fetchAll("SELECT fc.*, COUNT(f.id) AS faq_count FROM faq_categories fc LEFT JOIN faqs f ON f.category_id = fc.id GROUP BY fc.id ORDER BY fc.sort_order, fc.name");
        return Response::view('faq::admin.categories', ['categories' => $cats, 'user' => $this->auth->user()]);
    }

    public function storeCategory(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $v = new Validator($request->post());
        $v->validate(['name' => 'required|min:2|max:200', 'slug' => 'required|min:2|max:200']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/admin/faqs/categories');
        }
        $this->db->insert('faq_categories', [
            'name'       => $v->get('name'),
            'slug'       => $v->get('slug'),
            'description'=> $v->get('description'),
            'sort_order' => (int) $request->post('sort_order', 0),
            'is_public'  => (int) $request->post('is_public', 1),
        ]);
        return Response::redirect('/admin/faqs/categories')->withFlash('success', 'Category created.');
    }

    public function deleteCategory(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $id = (int) $request->param(0);
        // Null out category_id for orphaned FAQs
        $this->db->query("UPDATE faqs SET category_id = NULL WHERE category_id = ?", [$id]);
        $this->db->delete('faq_categories', 'id = ?', [$id]);
        return Response::redirect('/admin/faqs/categories')->withFlash('success', 'Category deleted.');
    }

    // ── Admin: FAQs ───────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $faqs = $this->db->fetchAll(
            "SELECT f.*, fc.name AS category_name FROM faqs f
             LEFT JOIN faq_categories fc ON fc.id = f.category_id
             ORDER BY fc.sort_order, f.sort_order, f.question"
        );
        $cats = $this->db->fetchAll("SELECT * FROM faq_categories ORDER BY sort_order, name");
        return Response::view('faq::admin.index', [
            'faqs'       => $faqs,
            'categories' => $cats,
            'user'       => $this->auth->user(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $v = new Validator($request->post());
        $v->validate(['question' => 'required|min:5', 'answer' => 'required|min:5']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/admin/faqs');
        }
        $sanitizedAnswer = V::sanitizeHtml($request->post('answer', ''));
        $isPublic        = (int) $request->post('is_public', 1);

        $id = $this->db->insert('faqs', [
            'category_id' => ($request->post('category_id') ?: null),
            'question'    => $v->get('question'),
            'answer'      => $sanitizedAnswer,
            'sort_order'  => (int) $request->post('sort_order', 0),
            'is_public'   => $isPublic,
            'is_active'   => 1,
            'created_by'  => $this->auth->id(),
        ]);
        app(\Core\Services\SearchIndexer::class)->sync('faqs', $id, [
            'id'        => $id,
            'question'  => $v->get('question'),
            'answer'    => $sanitizedAnswer,
            'is_public' => $isPublic,
            'is_active' => 1,
        ]);
        $this->auth->auditLog('faq.create');
        return Response::redirect('/admin/faqs')->withFlash('success', 'FAQ entry added.');
    }

    public function edit(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $id  = (int) $request->param(0);
        $faq = $this->db->fetchOne("SELECT * FROM faqs WHERE id = ?", [$id]);
        if (!$faq) return new Response('Not found', 404);
        $cats = $this->db->fetchAll("SELECT * FROM faq_categories ORDER BY sort_order, name");
        return Response::view('faq::admin.edit', ['faq' => $faq, 'categories' => $cats, 'user' => $this->auth->user()]);
    }

    public function update(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $id = (int) $request->param(0);
        $v  = new Validator($request->post());
        $v->validate(['question' => 'required|min:5', 'answer' => 'required|min:5']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect("/admin/faqs/$id/edit");
        }
        $sanitizedAnswer = V::sanitizeHtml($request->post('answer', ''));
        $isPublic        = (int) $request->post('is_public', 1);
        $isActive        = (int) $request->post('is_active', 1);

        $this->db->update('faqs', [
            'category_id' => ($request->post('category_id') ?: null),
            'question'    => $v->get('question'),
            'answer'      => $sanitizedAnswer,
            'sort_order'  => (int) $request->post('sort_order', 0),
            'is_public'   => $isPublic,
            'is_active'   => $isActive,
        ], 'id = ?', [$id]);
        app(\Core\Services\SearchIndexer::class)->sync('faqs', $id, [
            'id'        => $id,
            'question'  => $v->get('question'),
            'answer'    => $sanitizedAnswer,
            'is_public' => $isPublic,
            'is_active' => $isActive,
        ]);
        $this->auth->auditLog('faq.update', 'faqs', $id);
        return Response::redirect('/admin/faqs')->withFlash('success', 'FAQ updated.');
    }

    public function delete(Request $request): Response
    {
        if ($this->auth->cannot('faq.manage')) return $this->denied();
        $id = (int) $request->param(0);
        $this->db->delete('faqs', 'id = ?', [$id]);
        app(\Core\Services\SearchIndexer::class)->sync('faqs', $id);
        $this->auth->auditLog('faq.delete', 'faqs', $id);
        return Response::redirect('/admin/faqs')->withFlash('success', 'FAQ deleted.');
    }

    // ── Public FAQ page ───────────────────────────────────────────────────────

    public function publicIndex(Request $request): Response
    {
        $cats = $this->db->fetchAll(
            "SELECT fc.*, JSON_ARRAYAGG(JSON_OBJECT(
                'id', f.id, 'question', f.question, 'answer', f.answer
             )) AS faqs
             FROM faq_categories fc
             LEFT JOIN faqs f ON f.category_id = fc.id AND f.is_public = 1 AND f.is_active = 1
             WHERE fc.is_public = 1
             GROUP BY fc.id ORDER BY fc.sort_order"
        );

        // Fallback for MySQL versions without JSON_ARRAYAGG
        if (empty($cats)) {
            $cats    = $this->db->fetchAll("SELECT * FROM faq_categories WHERE is_public = 1 ORDER BY sort_order");
            $allFaqs = $this->db->fetchAll("SELECT * FROM faqs WHERE is_public = 1 AND is_active = 1 ORDER BY sort_order");
            foreach ($cats as &$cat) {
                $cat['faqs_list'] = array_filter($allFaqs, fn($f) => $f['category_id'] == $cat['id']);
            }
        }

        $uncategorized = $this->db->fetchAll(
            "SELECT * FROM faqs WHERE category_id IS NULL AND is_public = 1 AND is_active = 1 ORDER BY sort_order"
        );

        // Page-chrome Batch C: fragment + chrome wrap. Slug `faq`
        // mirrors the URL. Admin layout at /admin/system-layouts/faq.
        return Response::view('faq::public', [
            'categories'    => $cats,
            'uncategorized' => $uncategorized,
            'user'          => $this->auth->user(),
        ])->withLayout('faq');
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Access denied.');
    }
}
