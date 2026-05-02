<?php
// app/Controllers/SessionAdminController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;

/**
 * Admin surface for active sessions.
 *
 *   GET  /admin/sessions                      — list all sessions joined to users
 *   POST /admin/sessions/{id}/terminate       — delete a specific session row
 *   POST /admin/sessions/user/{userId}/terminate-all — delete every session for a user
 *
 * Gated by AuthMiddleware + RequireAdmin at the route layer. Deletes
 * are audit-logged so incident response has a trail.
 *
 * "Terminate" on this page means the target's next request lands on
 * /login — the row is gone, so the handler's read() returns an empty
 * string, so $_SESSION starts empty, so Auth::loadUser finds no
 * user_id and treats them as a guest.
 */
class SessionAdminController
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
        $userFilter = (int) $request->query('user_id', 0) ?: null;

        $where = ''; $bind = [];
        if ($userFilter) {
            $where = 'WHERE s.user_id = ?';
            $bind[] = $userFilter;
        }

        $rows = $this->db->fetchAll(
            "SELECT s.*, u.username, u.email, u.first_name, u.last_name
               FROM sessions s
          LEFT JOIN users u ON u.id = s.user_id
              $where
           ORDER BY s.last_activity DESC
              LIMIT 500",
            $bind
        );

        // Quick stat: count per user for the "sessions per user" sidebar.
        $topUsers = $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, COUNT(*) AS session_count
               FROM sessions s
          LEFT JOIN users u ON u.id = s.user_id
              WHERE s.user_id IS NOT NULL
           GROUP BY u.id, u.username, u.email
           ORDER BY session_count DESC
              LIMIT 10"
        );

        return Response::view('admin.sessions_index', [
            'sessions'   => $rows,
            'topUsers'   => $topUsers,
            'userFilter' => $userFilter,
        ]);
    }

    public function terminate(Request $request): Response
    {
        $id = (string) $request->param(0);
        $row = $this->db->fetchOne("SELECT user_id FROM sessions WHERE id = ?", [$id]);
        if (!$row) {
            return Response::redirect('/admin/sessions')->withFlash('error', 'Session not found.');
        }

        $this->db->delete('sessions', 'id = ?', [$id]);
        $this->auth->auditLog(
            'session.terminate',
            'sessions',
            null,
            null,
            [
                'target_session_id' => substr($id, 0, 16) . '…',
                'target_user_id'    => $row['user_id'],
            ]
        );

        return Response::redirect('/admin/sessions')->withFlash('success', 'Session terminated.');
    }

    public function terminateAllForUser(Request $request): Response
    {
        $userId = (int) $request->param(0);
        if ($userId <= 0) {
            return Response::redirect('/admin/sessions')->withFlash('error', 'Bad user id.');
        }

        $n = $this->db->delete('sessions', 'user_id = ?', [$userId]);
        $this->auth->auditLog(
            'session.terminate_all',
            'users',
            $userId,
            null,
            ['terminated_count' => (int) $n]
        );

        return Response::redirect("/admin/sessions?user_id=$userId")
            ->withFlash('success', "Terminated $n session" . ((int) $n === 1 ? '' : 's') . '.');
    }
}
