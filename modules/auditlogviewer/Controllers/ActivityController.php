<?php
// modules/audit-log-viewer/Controllers/ActivityController.php
namespace Modules\AuditLogViewer\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;

/**
 * Phase 27 — unified activity timeline.
 *
 *   GET /admin/activity   — merged chronological feed of
 *                           notifications + audit_log entries
 *
 * The two surfaces shipped in Phases 25/26 (notifications for
 * "what needs my attention now" + audit log for "compliance trail
 * with diff") cover the same underlying signal stream from two
 * different angles. This timeline merges them so operators have a
 * single "what happened?" view that doesn't require flipping
 * between two pages.
 *
 * Filters:
 *   kind         — 'all' (default) / 'notification' / 'audit'
 *   actor_user_id
 *   date_from    — YYYY-MM-DD
 *   date_to      — YYYY-MM-DD
 *   q            — free-text across action/type/title/body/notes
 *
 * The merge happens in PHP after parallel SELECTs against each
 * table — at this scale (admin pageview, capped working set) the
 * in-memory sort is faster than a UNION + per-column nullable
 * shape. If the surface grows to handle six-figure rows per page
 * load, replace with a UNION query + native pagination.
 *
 * Gate: same as the audit-log viewer (audit.view permission or
 * admin.access). Read-only.
 */
final class ActivityController
{
    private Auth     $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    private function gate(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        if (!$this->auth->can('audit.view') && !$this->auth->can('admin.access')) {
            return new Response('Forbidden', 403);
        }
        return null;
    }

    public function index(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $kind         = strtolower(trim((string) $request->query('kind', 'all')));
        if (!in_array($kind, ['all', 'notification', 'audit'], true)) $kind = 'all';
        $actorUserId  = (int) $request->query('actor_user_id', 0) ?: null;
        $dateFrom     = trim((string) $request->query('date_from')) ?: null;
        $dateTo       = trim((string) $request->query('date_to'))   ?: null;
        $q            = trim((string) $request->query('q'))         ?: null;
        $page         = max(1, (int) $request->query('page', 1));
        $perPage      = 40;

        // Working-set ceiling: we fetch up to N rows from each table
        // and merge in PHP. This caps the in-memory cost regardless
        // of how many rows the filter window covers.
        $fetchEach = max($perPage * 5, 200);

        $items = [];
        if ($kind === 'all' || $kind === 'notification') {
            foreach ($this->fetchNotifications($actorUserId, $dateFrom, $dateTo, $q, $fetchEach) as $r) {
                $items[] = $this->shapeNotification($r);
            }
        }
        if ($kind === 'all' || $kind === 'audit') {
            foreach ($this->fetchAuditRows($actorUserId, $dateFrom, $dateTo, $q, $fetchEach) as $r) {
                $items[] = $this->shapeAudit($r);
            }
        }

        // Newest first. Tie-break on id descending so two events with
        // the same created_at second land in stable insertion order.
        usort($items, function ($a, $b) {
            $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
            if ($cmp !== 0) return $cmp;
            // Audit rows have integer ids; notifications have UUID strings.
            // Cast both via spaceship to keep the comparison total-ordered.
            return strcmp((string) $b['id'], (string) $a['id']);
        });

        $totalLoaded = count($items);
        $offset      = ($page - 1) * $perPage;
        $items       = array_slice($items, $offset, $perPage);

        return Response::view('audit_log_viewer::admin.activity', [
            'items'        => $items,
            'kind'         => $kind,
            'filters'      => [
                'actor_user_id' => $actorUserId,
                'date_from'     => $dateFrom,
                'date_to'       => $dateTo,
                'q'             => $q,
                'kind'          => $kind,
            ],
            'page'         => $page,
            'per_page'     => $perPage,
            'total_loaded' => $totalLoaded,
            'fetch_each'   => $fetchEach,
        ]);
    }

    // ── Per-source fetchers ────────────────────────────────────────

    private function fetchNotifications(?int $actorUserId, ?string $from, ?string $to, ?string $q, int $limit): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($actorUserId !== null) { $where[] = 'n.user_id = ?'; $params[] = $actorUserId; }
        if ($from !== null) { $where[] = 'n.created_at >= ?'; $params[] = $from; }
        if ($to   !== null) { $where[] = 'n.created_at <= ?'; $params[] = $to; }
        if ($q    !== null) {
            $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
            $where[] = '(n.type LIKE ? OR n.title LIKE ? OR n.body LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $params[] = $limit;
        return $this->db->fetchAll(
            "SELECT n.id, n.user_id, n.type, n.title, n.body, n.data, n.read_at, n.created_at,
                    u.username AS user_username
               FROM notifications n
          LEFT JOIN users u ON u.id = n.user_id
              $whereSql
           ORDER BY n.created_at DESC, n.id DESC
              LIMIT ?",
            $params,
        );
    }

    private function fetchAuditRows(?int $actorUserId, ?string $from, ?string $to, ?string $q, int $limit): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($actorUserId !== null) { $where[] = 'a.actor_user_id = ?'; $params[] = $actorUserId; }
        if ($from !== null) { $where[] = 'a.created_at >= ?'; $params[] = $from; }
        if ($to   !== null) { $where[] = 'a.created_at <= ?'; $params[] = $to; }
        if ($q    !== null) {
            $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
            $where[] = '(a.action LIKE ? OR a.model LIKE ? OR a.notes LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $params[] = $limit;
        return $this->db->fetchAll(
            "SELECT a.id, a.actor_user_id, a.emulated_user_id, a.superadmin_mode,
                    a.action, a.model, a.model_id, a.ip_address, a.created_at,
                    u.username AS actor_username, e.username AS emulated_username
               FROM audit_log a
          LEFT JOIN users u ON u.id = a.actor_user_id
          LEFT JOIN users e ON e.id = a.emulated_user_id
              $whereSql
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT ?",
            $params,
        );
    }

    // ── Per-source view shapers ────────────────────────────────────

    private function shapeNotification(array $r): array
    {
        $data = !empty($r['data']) ? (json_decode((string) $r['data'], true) ?: []) : [];
        return [
            'kind'        => 'notification',
            'id'          => (string) $r['id'],
            'created_at'  => $r['created_at'],
            'icon'        => $this->iconFor((string) $r['type']),
            'badge_label' => 'notification',
            'badge_color' => '#4f46e5',
            'title'       => (string) $r['title'],
            'body'        => (string) ($r['body'] ?? ''),
            'subject'     => $r['user_username'] ? '@' . $r['user_username'] : '—',
            'action_type' => (string) $r['type'],
            'detail_link' => $data['link'] ?? '/notifications',
            'read'        => !empty($r['read_at']),
        ];
    }

    private function shapeAudit(array $r): array
    {
        $actorLabel = $r['actor_username']
            ? '@' . $r['actor_username']
            : ($r['actor_user_id'] ? 'user #' . $r['actor_user_id'] : 'system');
        $title = (string) $r['action'];
        if ($r['model']) {
            $title .= ' on ' . $r['model'] . ($r['model_id'] ? ' #' . $r['model_id'] : '');
        }
        $body = $r['emulated_username']
            ? 'Emulated by @' . $r['emulated_username']
            : ($r['ip_address'] ? "from {$r['ip_address']}" : '');
        return [
            'kind'        => 'audit',
            'id'          => (string) $r['id'],
            'created_at'  => $r['created_at'],
            'icon'        => $this->iconFor((string) $r['action']),
            'badge_label' => 'audit',
            'badge_color' => '#0891b2',
            'title'       => $title,
            'body'        => $body,
            'subject'     => $actorLabel,
            'action_type' => (string) $r['action'],
            'detail_link' => '/admin/audit-log/' . (int) $r['id'],
            'superadmin'  => (int) $r['superadmin_mode'] === 1,
        ];
    }

    /**
     * Pick an emoji icon for an action / notification type. Falls back
     * to a default dot when the prefix isn't recognised. Cheap +
     * deterministic + keeps the timeline scannable.
     */
    private function iconFor(string $type): string
    {
        $type = strtolower($type);
        $map = [
            'auth.'          => '🔐',
            'project.'       => '📦',
            'broadcast.'     => '📧',
            'deploy.'        => '🚀',
            'tenant.'        => '🏢',
            'comment.'       => '💬',
            'social.'        => '👋',
            'messages.'      => '✉',
            'group.'         => '👥',
            'module.'        => '🧩',
            'superadmin.'    => '🛡',
            'order.'         => '🛒',
            'subscription.'  => '💳',
            'payment.'       => '💳',
            'mail.'          => '📫',
            'unsubscribe.'   => '🔕',
            'cookieconsent.' => '🍪',
        ];
        foreach ($map as $prefix => $emoji) {
            if (str_starts_with($type, $prefix)) return $emoji;
        }
        return '•';
    }
}
