<?php
// modules/audit-log-viewer/Services/AuditLogService.php
namespace Modules\AuditLogViewer\Services;

use Core\Database\Database;

/**
 * Read-only wrapper over the core `audit_log` table. Does not
 * modify rows — writes stay where they always were, at
 * `Auth::auditLog`.
 *
 * Filter parameters map to the indexed columns:
 *   actor_user_id  → idx_actor
 *   action         → idx_action (prefix match for 'auth.*' style)
 *   model          → idx_model   (model+model_id)
 *   date_from / date_to → created_at bounded scan
 *
 * Free-text search across `action` / `model` / `notes` is supported
 * via `q` but does a table scan — suitable for small/medium logs;
 * production-scale installs should add a full-text index.
 */
class AuditLogService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where  = ['1=1'];
        $bind   = [];

        if (!empty($filters['actor_user_id'])) {
            $where[] = 'a.actor_user_id = ?';
            $bind[]  = (int) $filters['actor_user_id'];
        }
        if (!empty($filters['action'])) {
            // Prefix match support: "auth.*" ⇒ LIKE 'auth.%'
            $action = (string) $filters['action'];
            if (str_ends_with($action, '*')) {
                $where[] = 'a.action LIKE ?';
                $bind[]  = str_replace('*', '%', $action);
            } else {
                $where[] = 'a.action = ?';
                $bind[]  = $action;
            }
        }
        if (!empty($filters['model'])) {
            $where[] = 'a.model = ?';
            $bind[]  = (string) $filters['model'];
        }
        if (!empty($filters['model_id'])) {
            $where[] = 'a.model_id = ?';
            $bind[]  = (int) $filters['model_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.created_at >= ?';
            $bind[]  = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.created_at <= ?';
            $bind[]  = (string) $filters['date_to'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . str_replace(['%','_'], ['\%','\_'], (string) $filters['q']) . '%';
            $where[] = '(a.action LIKE ? OR a.model LIKE ? OR a.notes LIKE ?)';
            $bind[]  = $like; $bind[] = $like; $bind[] = $like;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $items = $this->db->fetchAll(
            "SELECT a.*, u.username AS actor_username, e.username AS emulated_username
               FROM audit_log a
          LEFT JOIN users u ON u.id = a.actor_user_id
          LEFT JOIN users e ON e.id = a.emulated_user_id
              $whereSql
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT ? OFFSET ?",
            [...$bind, $perPage, $offset]
        );
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_log a $whereSql", $bind
        );
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT a.*, u.username AS actor_username, e.username AS emulated_username
               FROM audit_log a
          LEFT JOIN users u ON u.id = a.actor_user_id
          LEFT JOIN users e ON e.id = a.emulated_user_id
              WHERE a.id = ?",
            [$id]
        );
    }

    /**
     * Distinct action strings + counts for the filter dropdown. Capped
     * to the most common 200 — rare actions can be searched via the
     * action text filter.
     *
     * @return array<int, array{action: string, cnt: int}>
     */
    public function topActions(int $limit = 200): array
    {
        return $this->db->fetchAll(
            "SELECT action, COUNT(*) AS cnt FROM audit_log
          GROUP BY action
          ORDER BY cnt DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Decode old/new values to arrays. Wraps the JSON decode so views
     * don't have to guard against null / invalid JSON.
     *
     * @return array{old: ?array, new: ?array}
     */
    public function decodeValues(array $row): array
    {
        return [
            'old' => !empty($row['old_values']) ? (json_decode((string) $row['old_values'], true) ?: null) : null,
            'new' => !empty($row['new_values']) ? (json_decode((string) $row['new_values'], true) ?: null) : null,
        ];
    }
}
