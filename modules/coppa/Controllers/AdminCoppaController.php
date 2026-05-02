<?php
// modules/coppa/Controllers/AdminCoppaController.php
namespace Modules\Coppa\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * /admin/coppa — recent COPPA registration rejections.
 *
 * Reads from audit_log directly since `coppa.registration_blocked`
 * rows are written there on every rejection. Useful for spotting
 * patterns (a flood of attempts may indicate the site is mis-positioned
 * or being abused).
 *
 * Settings live on /admin/settings/access — there's no separate config
 * surface here, just a read-only review.
 */
class AdminCoppaController
{
    private Auth     $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $rows = $this->db->fetchAll("
            SELECT id, ip_address, user_agent, new_values, created_at
            FROM audit_log
            WHERE action = 'coppa.registration_blocked'
            ORDER BY id DESC
            LIMIT 200
        ");

        $stats = $this->db->fetchOne("
            SELECT COUNT(*) AS total_30d
            FROM audit_log
            WHERE action = 'coppa.registration_blocked'
              AND created_at > NOW() - INTERVAL 30 DAY
        ") ?: ['total_30d' => 0];

        return Response::view('coppa::admin.index', [
            'rows'  => $rows,
            'stats' => $stats,
            'user'  => $this->auth->user(),
        ]);
    }

    private function canManage(): bool
    {
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('coppa.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to view COPPA rejections.');
        return Response::redirect('/admin');
    }
}
