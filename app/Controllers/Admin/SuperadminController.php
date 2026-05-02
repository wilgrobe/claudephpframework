<?php
// app/Controllers/Admin/SuperadminController.php
namespace App\Controllers\Admin;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Database\Database;
use Core\Services\MessageRetryService;

class SuperadminController
{
    private Auth     $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    /**
     * Superadmin dashboard — system overview.
     */
    public function dashboard(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::redirect('/dashboard')->withFlash('error', 'Enable superadmin mode first.');
        }

        $stats = [
            'users'          => $this->db->fetchColumn("SELECT COUNT(*) FROM users"),
            'groups'         => $this->db->fetchColumn("SELECT COUNT(*) FROM `groups`"),
            'active_sessions'=> $this->db->fetchColumn("SELECT COUNT(*) FROM sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"),
            'audit_today'    => $this->db->fetchColumn("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()"),
            'failed_emails'  => $this->db->fetchColumn("SELECT COUNT(*) FROM message_log WHERE status = 'failed' AND DATE(created_at) = CURDATE()"),
        ];

        $recentAudit = $this->db->fetchAll(
            "SELECT al.*, u.username AS actor_username, eu.username AS emulated_username
             FROM audit_log al
             LEFT JOIN users u  ON u.id  = al.actor_user_id
             LEFT JOIN users eu ON eu.id = al.emulated_user_id
             ORDER BY al.created_at DESC LIMIT 20"
        );

        return Response::view('admin.superadmin.dashboard', [
            'stats'       => $stats,
            'recentAudit' => $recentAudit,
            'user'        => $this->auth->user(),
        ]);
    }

    /**
     * Full audit log with filters.
     */
    public function auditLog(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn() && $this->auth->cannot('audit.view')) {
            return Response::redirect('/dashboard')->withFlash('error', 'Access denied.');
        }

        $filter = [
            'user'   => $request->query('user',   ''),
            'action' => $request->query('action', ''),
            'model'  => $request->query('model',  ''),
            'date'   => $request->query('date',   ''),
        ];

        $sql = "SELECT al.*, u.username AS actor_username, eu.username AS emulated_username
                FROM audit_log al
                LEFT JOIN users u  ON u.id  = al.actor_user_id
                LEFT JOIN users eu ON eu.id = al.emulated_user_id
                WHERE 1=1";
        $bindings = [];

        if ($filter['user']) {
            $sql .= " AND u.username LIKE ?";
            $bindings[] = "%{$filter['user']}%";
        }
        if ($filter['action']) {
            $sql .= " AND al.action LIKE ?";
            $bindings[] = "%{$filter['action']}%";
        }
        if ($filter['model']) {
            $sql .= " AND al.model = ?";
            $bindings[] = $filter['model'];
        }
        if ($filter['date']) {
            $sql .= " AND DATE(al.created_at) = ?";
            $bindings[] = $filter['date'];
        }

        $page = max(1, (int) $request->query('page', 1));
        $data = $this->db->paginate("$sql ORDER BY al.created_at DESC", $bindings, $page, 50);

        return Response::view('admin.superadmin.audit_log', [
            'log'    => $data,
            'filter' => $filter,
            'user'   => $this->auth->user(),
        ]);
    }

    /**
     * List all users for emulation or management.
     */
    public function users(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::redirect('/dashboard')->withFlash('error', 'Enable superadmin mode first.');
        }

        $search = $request->query('q', '');
        $sql    = "SELECT u.*, GROUP_CONCAT(r.name SEPARATOR ', ') AS roles
                   FROM users u
                   LEFT JOIN user_roles ur ON ur.user_id = u.id
                   LEFT JOIN roles r ON r.id = ur.role_id";
        $bindings = [];

        if ($search) {
            $sql .= " WHERE u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ?";
            $bindings = ["%$search%", "%$search%", "%$search%"];
        }
        $sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

        $page  = max(1, (int) $request->query('page', 1));
        $data  = $this->db->paginate($sql, $bindings, $page, 30);

        return Response::view('admin.superadmin.users', [
            'users'  => $data,
            'search' => $search,
            'user'   => $this->auth->user(),
        ]);
    }

    /**
     * Toggle a user's superadmin flag.
     */
    public function toggleUserSuperadmin(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        $targetId = (int) $request->param(0);
        $enable   = (int) $request->post('enable', 0);

        // Cannot remove own superadmin flag
        if ($targetId === $this->auth->id() && !$enable) {
            return Response::json(['error' => 'Cannot remove your own superadmin flag.'], 422);
        }

        // Capture the prior state so we can decide whether to fire the
        // promotion-cleanup hook. Only a 0→1 transition triggers the
        // cleanup; un-promoting (1→0) doesn't bring back deleted blocks.
        $prior = (int) ($this->db->fetchColumn("SELECT is_superadmin FROM users WHERE id = ?", [$targetId]) ?? 0);

        $this->db->query("UPDATE users SET is_superadmin = ? WHERE id = ?", [$enable, $targetId]);
        $this->auth->auditLog(
            $enable ? 'superadmin.granted' : 'superadmin.revoked',
            'users', $targetId,
            null, ['is_superadmin' => $enable]
        );

        // 0→1 transition fires the cleanup + notification. The block
        // module owns the logic; we just delegate. Soft-guarded so the
        // promotion still works if block module is uninstalled.
        if ($prior === 0 && $enable === 1
            && class_exists(\Modules\Block\Services\BlockService::class)) {
            (new \Modules\Block\Services\BlockService())->handlePromotionToSA($targetId);
        }

        return Response::json(['success' => true]);
    }

    /**
     * Message log viewer.
     */
    public function messageLog(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::redirect('/dashboard')->withFlash('error', 'Access denied.');
        }

        $channel = $request->query('channel', '');
        $status  = $request->query('status', '');
        $sql     = "SELECT * FROM message_log WHERE 1=1";
        $b       = [];

        if ($channel) { $sql .= " AND channel = ?"; $b[] = $channel; }
        if ($status)  { $sql .= " AND status = ?";  $b[] = $status; }

        $page = max(1, (int) $request->query('page', 1));
        $data = $this->db->paginate("$sql ORDER BY created_at DESC", $b, $page, 50);

        // Precompute the preview once per row so the view doesn't call
        // strip_tags() inside a foreach — strip_tags loads a DOM parser,
        // which on a page of 50 HTML emails adds up fast.
        foreach ($data['items'] as &$row) {
            $row['preview'] = $row['subject']
                ?: substr(strip_tags((string) ($row['body'] ?? '')), 0, 80);
        }
        unset($row);

        return Response::view('admin.superadmin.message_log', [
            'log'     => $data,
            'channel' => $channel,
            'status'  => $status,
            'user'    => $this->auth->user(),
        ]);
    }

    /**
     * Manually retry a single message log row. Unlike the automatic retry
     * worker, this ignores backoff and max_attempts — useful after fixing
     * an SMTP config issue and wanting to immediately re-send terminal
     * failures without waiting on the schedule.
     */
    public function retryMessage(Request $request): Response
    {
        if (!$this->auth->isSuperadminModeOn()) {
            return Response::redirect('/dashboard')->withFlash('error', 'Access denied.');
        }

        $id = (int)$request->param(0);
        if ($id <= 0) {
            return Response::redirect('/admin/superadmin/message-log')
                ->withFlash('error', 'Invalid message id.');
        }

        $ok = (new MessageRetryService())->retryOne($id);

        return Response::redirect('/admin/superadmin/message-log')
            ->withFlash(
                $ok ? 'success' : 'error',
                $ok ? "Message #$id re-sent." : "Message #$id failed to re-send; check the log for details."
            );
    }
}
