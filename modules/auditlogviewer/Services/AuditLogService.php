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

    /**
     * Stream filtered audit rows to a row-emitter callback for CSV
     * export. Caller owns the fopen/fwrite; the service just walks
     * the result set in pages so the memory footprint stays bounded
     * even on millions of rows.
     *
     * @param array $filters  same shape as list()
     * @param callable(array): void $emit  invoked once per row
     * @return int  total rows emitted
     */
    public function streamForExport(array $filters, callable $emit, int $hardLimit = 100000): int
    {
        $perPage = 500;
        $page    = 1;
        $emitted = 0;
        while ($emitted < $hardLimit) {
            $res = $this->list($filters, $page, $perPage);
            if (empty($res['items'])) break;
            foreach ($res['items'] as $row) {
                $emit($row);
                $emitted++;
                if ($emitted >= $hardLimit) break 2;
            }
            if (count($res['items']) < $perPage) break;
            $page++;
        }
        return $emitted;
    }

    /**
     * Find the chronologically-adjacent audit row id (newer or older)
     * so the show view can render Prev/Next navigation. NULL when
     * the current row is the first/last in the table.
     */
    public function neighbourId(int $currentId, string $direction): ?int
    {
        $op = $direction === 'next' ? '>' : '<';
        $order = $direction === 'next' ? 'ASC' : 'DESC';
        $id = $this->db->fetchColumn(
            "SELECT id FROM audit_log WHERE id $op ? ORDER BY id $order LIMIT 1",
            [$currentId],
        );
        return $id ? (int) $id : null;
    }

    /**
     * Quick chain-integrity readout for the index view's status pill.
     * Delegates to auditchain when the module is installed; falls back
     * to "unknown" when it isn't. Best-effort — never throws.
     *
     * @return array{available:bool, breaks:int, last_verified_at:?string}
     */
    public function chainStatus(): array
    {
        $out = ['available' => false, 'breaks' => 0, 'last_verified_at' => null];
        if (!class_exists(\Modules\Auditchain\Services\AuditChainService::class)) {
            return $out;
        }
        try {
            $svc   = new \Modules\Auditchain\Services\AuditChainService();
            $stats = $svc->stats();
            return [
                'available'        => true,
                'breaks'           => (int) ($stats['recent_breaks_open'] ?? $stats['breaks'] ?? 0),
                'last_verified_at' => $stats['last_verified_at'] ?? null,
            ];
        } catch (\Throwable) {
            return $out;
        }
    }
}
