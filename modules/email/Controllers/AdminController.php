<?php
// modules/email/Controllers/AdminController.php
namespace Modules\Email\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\Email\Services\SuppressionService;

/**
 * Admin endpoints for /admin/email-suppressions.
 *
 *   GET  /admin/email-suppressions                 — list with search
 *   POST /admin/email-suppressions                 — manual add
 *   POST /admin/email-suppressions/{id}/delete     — remove a row
 *   GET  /admin/email-suppressions/blocks          — log of skipped sends
 *   GET  /admin/email-suppressions/bounces         — provider webhook log
 */
class AdminController
{
    private Auth               $auth;
    private SuppressionService $svc;
    private Database           $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new SuppressionService();
        $this->db   = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $q = trim((string) $request->query('q', ''));
        $where = '';
        $args  = [];
        if ($q !== '') {
            $where = ' WHERE email LIKE ?';
            $args[] = '%' . $q . '%';
        }

        $rows = $this->db->fetchAll("
            SELECT s.*, u.username AS user_username
            FROM mail_suppressions s
            LEFT JOIN users u ON u.id = s.user_id
            {$where}
            ORDER BY s.id DESC
            LIMIT 200
        ", $args);

        $stats = $this->db->fetchOne("
            SELECT
              COUNT(*) AS total,
              SUM(reason='user_unsubscribe') AS user_unsub,
              SUM(reason='hard_bounce') AS bounces,
              SUM(reason='complaint') AS complaints,
              SUM(reason='manual_admin') AS manual,
              SUM(category_slug='all') AS wildcard
            FROM mail_suppressions
        ") ?: [];

        $categories = $this->svc->listCategories();

        return Response::view('email::admin.suppressions', [
            'rows'       => $rows,
            'stats'      => $stats,
            'q'          => $q,
            'categories' => $categories,
            'user'       => $this->auth->user(),
        ]);
    }

    public function add(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $email    = trim((string) $request->post('email', ''));
        $category = trim((string) $request->post('category_slug', ''));
        $notes    = trim((string) $request->post('notes', ''));

        if ($email === '' || $category === '') {
            return Response::redirect('/admin/email-suppressions')
                ->withFlash('error', 'Email and category are required.');
        }

        $this->svc->suppress(
            $email,
            $category,
            SuppressionService::REASON_MANUAL_ADMIN,
            null,
            $notes !== '' ? $notes : null
        );

        $this->auth->auditLog('email.suppression.added', null, null, null, [
            'email' => $email, 'category_slug' => $category,
        ]);

        return Response::redirect('/admin/email-suppressions')
            ->withFlash('success', "Suppressed {$email} from {$category}.");
    }

    public function delete(Request $request, int $id): Response
    {
        if (!$this->canManage()) return $this->denied();

        $row = $this->db->fetchOne("SELECT * FROM mail_suppressions WHERE id = ?", [$id]);
        if (!$row) return Response::redirect('/admin/email-suppressions');

        $this->db->query("DELETE FROM mail_suppressions WHERE id = ?", [$id]);
        $this->auth->auditLog('email.suppression.removed', 'mail_suppressions', $id, [
            'email' => $row['email'], 'category_slug' => $row['category_slug'], 'reason' => $row['reason'],
        ]);

        return Response::redirect('/admin/email-suppressions')
            ->withFlash('success', 'Suppression removed. Email will be eligible for sending again.');
    }

    public function blocks(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $rows = $this->db->fetchAll("
            SELECT * FROM mail_suppression_blocks
            ORDER BY id DESC
            LIMIT 200
        ");

        return Response::view('email::admin.blocks', [
            'rows' => $rows,
            'user' => $this->auth->user(),
        ]);
    }

    public function bounces(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $rows = $this->db->fetchAll("
            SELECT id, provider, event_type, email, received_at
            FROM mail_bounce_events
            ORDER BY id DESC
            LIMIT 200
        ");

        return Response::view('email::admin.bounces', [
            'rows' => $rows,
            'user' => $this->auth->user(),
        ]);
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('email.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage email compliance.');
        return Response::redirect('/admin');
    }
}
