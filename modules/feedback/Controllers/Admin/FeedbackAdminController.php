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
        $kind   = in_array($request->query('kind'), ['feedback', 'testimonial', 'issue'], true) ? $request->query('kind') : null;
        $status = in_array($request->query('status'), ['new', 'reviewed', 'published', 'archived'], true) ? $request->query('status') : null;

        $where = []; $binds = [];
        if ($kind !== null)   { $where[] = 'kind = ?';   $binds[] = $kind; }
        if ($status !== null) { $where[] = 'status = ?'; $binds[] = $status; }

        // The issue-report columns arrived in a later migration, so select
        // them only when they exist — an admin queue that fatals on a site
        // that hasn't migrated yet would be a bad trade for a nicer list.
        $extra = $this->hasIssueColumns()
            ? ', intent, user_id, page_url, severity, context'
            : '';

        $sql = "SELECT id, kind, prompt, message, rating, is_anonymous, name, email, request_response, consent_display, status, created_at, responded_at{$extra}
                FROM feedback_submissions"
             . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
             // Blocking issues first — they're the ones costing someone their day.
             . ' ORDER BY (status = \'new\') DESC, '
             . ($extra !== '' ? "(severity = 'blocking') DESC, " : '')
             . 'created_at DESC LIMIT 300';

        try { $rows = $this->db->fetchAll($sql, $binds); } catch (\Throwable) { $rows = []; }

        $counts = ['new' => 0, 'reviewed' => 0, 'published' => 0, 'archived' => 0, 'testimonial' => 0, 'issue' => 0];
        try {
            foreach ($this->db->fetchAll("SELECT status, COUNT(*) n FROM feedback_submissions GROUP BY status") as $c) {
                $counts[$c['status']] = (int) $c['n'];
            }
            foreach ($this->db->fetchAll("SELECT kind, COUNT(*) n FROM feedback_submissions GROUP BY kind") as $c) {
                if (isset($counts[$c['kind']])) $counts[$c['kind']] = (int) $c['n'];
            }
        } catch (\Throwable) {}

        return Response::view('feedback::admin.index', [
            'rows'       => $rows,
            'counts'     => $counts,
            'filterKind' => $kind,
            'filterStat' => $status,
            'user'       => Auth::getInstance()->user(),
            'widget'     => [
                'enabled'  => \Modules\Feedback\Services\IssueWidget::enabled(),
                'audience' => \Modules\Feedback\Services\IssueWidget::audience(),
                'launcher' => \Modules\Feedback\Services\IssueWidget::launcher(),
                'notify'   => \Modules\Feedback\Services\IssueWidget::notifyEmail(),
            ],
        ]);
    }

    /**
     * Save the issue-widget settings from the card at the top of the queue.
     *
     * This lives here rather than in the settings module because the queue is
     * where an operator already goes to read reports — the switch that turns
     * reporting on belongs next to the reports it produces.
     */
    public function saveWidget(Request $request): Response
    {
        $audience = $request->post('audience') === 'everyone' ? 'everyone' : 'members';
        $launcher = in_array($request->post('launcher'), ['both', 'bubble', 'footer'], true)
            ? (string) $request->post('launcher') : 'both';
        $notify   = trim((string) ($request->post('notify_email') ?? ''));

        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'That notification email doesn’t look right.');
            return Response::redirect('/admin/site-feedback?kind=issue');
        }

        try {
            $svc = new \Core\Services\SettingsService();
            $svc->set('builder.feedback.widget.enabled',  !empty($request->post('enabled')) ? '1' : '0', 'site');
            $svc->set('builder.feedback.widget.audience', $audience, 'site');
            $svc->set('builder.feedback.widget.launcher', $launcher, 'site');
            $svc->set('builder.feedback.notify_email',    $notify, 'site');
            Session::flash('success', 'Issue-reporting settings saved.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not save: ' . $e->getMessage());
        }

        return Response::redirect('/admin/site-feedback?kind=issue');
    }

    /** Has the issue-report migration run on this site's database? */
    private function hasIssueColumns(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $has = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name = 'feedback_submissions'
                    AND column_name = 'context'"
            ) > 0;
        } catch (\Throwable) {
            $has = false;
        }
        return $has;
    }

    public function setStatus(Request $request): Response
    {
        $id     = (int) $request->param(0);
        $status = (string) $request->post('status');
        if (!in_array($status, ['new', 'reviewed', 'published', 'archived'], true)) {
            Session::flash('error', 'Invalid status.');
            return Response::redirect('/admin/site-feedback');
        }
        // Publishing is only meaningful for a testimonial the submitter consented to display.
        if ($status === 'published') {
            $row = $this->db->fetchOne("SELECT kind, consent_display FROM feedback_submissions WHERE id = ?", [$id]);
            if (!$row || $row['kind'] !== 'testimonial' || (int) $row['consent_display'] !== 1) {
                Session::flash('error', 'Only testimonials with display consent can be published.');
                return Response::redirect('/admin/site-feedback');
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
        return Response::redirect('/admin/site-feedback' . ($request->post('back') ? (string) $request->post('back') : ''));
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
        return Response::redirect('/admin/site-feedback');
    }
}
