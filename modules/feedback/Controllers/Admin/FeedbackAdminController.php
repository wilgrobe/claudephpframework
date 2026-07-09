<?php
// modules/feedback/Controllers/Admin/FeedbackAdminController.php
namespace Modules\Feedback\Controllers\Admin;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Database\Database;

/**
 * Admin queue for end-user feedback + testimonials. Route-gated by
 * RequireAdmin. Admins review feedback, reply to those who asked for a
 * response, and publish testimonials so they appear on the site.
 */
class FeedbackAdminController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        $kind   = in_array($request->query('kind'), ['feedback', 'testimonial'], true) ? $request->query('kind') : null;
        $status = in_array($request->query('status'), ['new', 'reviewed', 'published', 'archived'], true) ? $request->query('status') : null;

        $where = []; $binds = [];
        if ($kind !== null)   { $where[] = 'kind = ?';   $binds[] = $kind; }
        if ($status !== null) { $where[] = 'status = ?'; $binds[] = $status; }
        $sql = "SELECT id, kind, prompt, message, rating, is_anonymous, name, email, request_response, consent_display, status, created_at, responded_at
                FROM feedback_submissions"
             . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY (status = \'new\') DESC, created_at DESC LIMIT 300';

        try { $rows = $this->db->fetchAll($sql, $binds); } catch (\Throwable) { $rows = []; }

        $counts = ['new' => 0, 'reviewed' => 0, 'published' => 0, 'archived' => 0, 'testimonial' => 0];
        try {
            foreach ($this->db->fetchAll("SELECT status, COUNT(*) n FROM feedback_submissions GROUP BY status") as $c) {
                $counts[$c['status']] = (int) $c['n'];
            }
            $counts['testimonial'] = (int) ($this->db->fetchOne("SELECT COUNT(*) n FROM feedback_submissions WHERE kind='testimonial'")['n'] ?? 0);
        } catch (\Throwable) {}

        return Response::view('feedback::admin.index', [
            'rows'       => $rows,
            'counts'     => $counts,
            'filterKind' => $kind,
            'filterStat' => $status,
            'user'       => Auth::getInstance()->user(),
        ]);
    }

    public function setStatus(Request $request): Response
    {
        $id     = (int) $request->param(0);
        $status = (string) $request->post('status');
        if (!in_array($status, ['new', 'reviewed', 'published', 'archived'], true)) {
            Session::flash('error', 'Invalid status.');
            return Response::redirect('/admin/feedback');
        }
        // Publishing is only meaningful for a testimonial the submitter consented to display.
        if ($status === 'published') {
            $row = $this->db->fetchOne("SELECT kind, consent_display FROM feedback_submissions WHERE id = ?", [$id]);
            if (!$row || $row['kind'] !== 'testimonial' || (int) $row['consent_display'] !== 1) {
                Session::flash('error', 'Only testimonials with display consent can be published.');
                return Response::redirect('/admin/feedback');
            }
        }
        try {
            $this->db->query(
                "UPDATE feedback_submissions SET status = ?, responded_at = CASE WHEN ? IN ('published','reviewed') THEN NOW() ELSE responded_at END WHERE id = ?",
                [$status, $status, $id]
            );
            Session::flash('success', 'Updated.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not update: ' . $e->getMessage());
        }
        return Response::redirect('/admin/feedback' . ($request->post('back') ? (string) $request->post('back') : ''));
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->param(0);
        try {
            $this->db->query("DELETE FROM feedback_submissions WHERE id = ?", [$id]);
            Session::flash('success', 'Deleted.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not delete.');
        }
        return Response::redirect('/admin/feedback');
    }
}
