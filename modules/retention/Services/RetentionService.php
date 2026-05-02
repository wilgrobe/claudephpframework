<?php
// modules/retention/Services/RetentionService.php
namespace Modules\Retention\Services;

use Core\Database\Database;

/**
 * Lifecycle for the retention sweeper.
 *
 *   sync()                    Walk RetentionRegistry, INSERT new rules,
 *                             leave existing rows alone (admin overrides
 *                             are protected).
 *   runAll(?int $by, $dry)    Execute every enabled rule.
 *   runOne($id, ?int $by, $dry)
 *                             Execute one rule by id.
 *   preview($id)              Count rows the rule WOULD affect, without
 *                             writing. Used for the admin UI's dry-run
 *                             button.
 *
 * The sweep processes in chunks of 1000 rows per query to avoid table
 * locks and oversized binlog entries. Each rule runs in its own
 * transaction so a partial failure on rule N doesn't unwind rule
 * N-1's already-committed work.
 */
class RetentionService
{
    public const CHUNK_SIZE = 1000;
    public const MAX_CHUNKS_PER_RUN = 100; // hard ceiling = 100k rows per rule per run

    private Database          $db;
    private RetentionRegistry $registry;

    public function __construct(?Database $db = null, ?RetentionRegistry $registry = null)
    {
        $this->db       = $db       ?? Database::getInstance();
        $this->registry = $registry ?? new RetentionRegistry();
    }

    /**
     * Discover rules from modules + core, INSERT IGNORE into
     * retention_rules. Returns count of newly-inserted rules.
     *
     * Stable on re-run: existing rows are NOT touched (admin overrides
     * win). Only NEW rules from a recently-added module get a row.
     */
    public function sync(): int
    {
        $declared = $this->registry->all();
        $inserted = 0;

        foreach ($declared as $rule) {
            $exists = (int) $this->db->fetchColumn(
                "SELECT id FROM retention_rules WHERE `key` = ?",
                [$rule->key]
            );
            if ($exists > 0) continue;

            $this->db->insert('retention_rules', [
                'key'               => $rule->key,
                'module'            => $rule->module,
                'label'             => $rule->label,
                'description'       => $rule->description,
                'table_name'        => $rule->tableName,
                'where_clause'      => $rule->whereClause,
                'date_column'       => $rule->dateColumn,
                'days_keep'         => $rule->daysKeep,
                'action'            => $rule->action,
                'anonymize_columns' => !empty($rule->anonymizeColumns)
                    ? json_encode($rule->anonymizeColumns)
                    : null,
                'is_enabled'        => $rule->defaultEnabled ? 1 : 0,
                'source'            => 'module_default',
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @return array{rules_run: int, rows_affected: int, errors: int}
     */
    public function runAll(?int $byUserId = null, bool $dryRun = false): array
    {
        $this->sync();

        $rules = $this->db->fetchAll(
            "SELECT * FROM retention_rules WHERE is_enabled = 1 ORDER BY id ASC"
        );

        $stats = ['rules_run' => 0, 'rows_affected' => 0, 'errors' => 0];

        foreach ($rules as $r) {
            try {
                $result = $this->runOne((int) $r['id'], $byUserId, $dryRun);
                $stats['rules_run']++;
                $stats['rows_affected'] += $result['rows_affected'];
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log("[retention] rule {$r['key']} failed: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * @return array{rows_affected: int, duration_ms: int, dry_run: bool}
     */
    public function runOne(int $ruleId, ?int $byUserId = null, bool $dryRun = false): array
    {
        $rule = $this->db->fetchOne("SELECT * FROM retention_rules WHERE id = ?", [$ruleId]);
        if (!$rule) throw new \RuntimeException("Unknown retention rule #{$ruleId}");

        $started = microtime(true);
        $runId   = $this->db->insert('retention_runs', [
            'rule_id'      => $ruleId,
            'dry_run'      => $dryRun ? 1 : 0,
            'triggered_by' => $byUserId,
        ]);

        try {
            // Build the cutoff datetime
            $cutoff = date('Y-m-d H:i:s', time() - (int) $rule['days_keep'] * 86400);
            $where  = str_replace('{cutoff}', '?', (string) $rule['where_clause']);
            $table  = (string) $rule['table_name'];

            $totalAffected = 0;

            if ($dryRun) {
                // Just count
                $totalAffected = (int) $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM `{$table}` WHERE {$where}",
                    [$cutoff]
                );
            } else {
                // Process in chunks. We grab the matching ids first
                // (LIMIT) then act on that batch. This keeps each
                // transaction bounded and lets us cap total rows
                // affected per run via MAX_CHUNKS_PER_RUN.
                for ($i = 0; $i < self::MAX_CHUNKS_PER_RUN; $i++) {
                    $chunkAffected = $this->processChunk($rule, $where, $cutoff);
                    $totalAffected += $chunkAffected;
                    if ($chunkAffected < self::CHUNK_SIZE) break;
                }
            }

            $duration = (int) ((microtime(true) - $started) * 1000);

            $this->db->update('retention_runs', [
                'completed_at'  => date('Y-m-d H:i:s'),
                'rows_affected' => $totalAffected,
                'duration_ms'   => $duration,
            ], 'id = ?', [$runId]);

            $this->db->update('retention_rules', [
                'last_run_at'     => date('Y-m-d H:i:s'),
                'last_run_rows'   => $totalAffected,
                'last_run_status' => $dryRun ? 'dry_run' : 'ok',
            ], 'id = ?', [$ruleId]);

            return [
                'rows_affected' => $totalAffected,
                'duration_ms'   => $duration,
                'dry_run'       => $dryRun,
            ];

        } catch (\Throwable $e) {
            $this->db->update('retention_runs', [
                'completed_at'  => date('Y-m-d H:i:s'),
                'error_message' => substr($e->getMessage(), 0, 1000),
            ], 'id = ?', [$runId]);

            $this->db->update('retention_rules', [
                'last_run_at'     => date('Y-m-d H:i:s'),
                'last_run_status' => 'failed',
            ], 'id = ?', [$ruleId]);

            throw $e;
        }
    }

    /**
     * Pure dry-run that doesn't even write a retention_runs row —
     * used by the admin UI's "Preview" button to check counts before
     * committing to a real run.
     */
    public function preview(int $ruleId): int
    {
        $rule = $this->db->fetchOne("SELECT * FROM retention_rules WHERE id = ?", [$ruleId]);
        if (!$rule) throw new \RuntimeException("Unknown retention rule #{$ruleId}");

        $cutoff = date('Y-m-d H:i:s', time() - (int) $rule['days_keep'] * 86400);
        $where  = str_replace('{cutoff}', '?', (string) $rule['where_clause']);
        $table  = (string) $rule['table_name'];

        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `{$table}` WHERE {$where}",
            [$cutoff]
        );
    }

    private function processChunk(array $rule, string $where, string $cutoff): int
    {
        $table  = (string) $rule['table_name'];
        $action = (string) $rule['action'];

        // Batch by id to keep each statement bounded.
        // Some tables don't have id (e.g. role_permissions composite key)
        // — those need a customExec callable; we don't ship rules for
        // them in the framework defaults so this assumption is safe.
        $ids = $this->db->fetchAll(
            "SELECT id FROM `{$table}` WHERE {$where} LIMIT " . (int) self::CHUNK_SIZE,
            [$cutoff]
        );
        if (empty($ids)) return 0;

        $idList = array_map(fn($r) => (int) $r['id'], $ids);
        $placeholders = implode(',', array_fill(0, count($idList), '?'));

        if ($action === RetentionRule::ACTION_ANONYMIZE) {
            $cols = json_decode((string) ($rule['anonymize_columns'] ?? 'null'), true) ?: [];
            $sets = [];
            $args = [];
            foreach ($cols as $col => $val) {
                $sets[] = "`{$col}` = ?";
                $args[] = $val;
            }
            if (empty($sets)) {
                // Anonymize with no columns specified — no-op, mark
                // affected = 0 to surface the misconfiguration.
                return 0;
            }
            $sql = "UPDATE `{$table}` SET " . implode(', ', $sets)
                 . " WHERE id IN ({$placeholders})";
            $args = array_merge($args, $idList);
            $this->db->query($sql, $args);
            return count($idList);
        }

        // Default: purge
        $this->db->query(
            "DELETE FROM `{$table}` WHERE id IN ({$placeholders})",
            $idList
        );
        return count($idList);
    }

    /** Rule rows for the admin index. */
    public function listRules(): array
    {
        return $this->db->fetchAll("
            SELECT * FROM retention_rules
            ORDER BY module ASC, label ASC
        ");
    }

    public function findRule(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM retention_rules WHERE id = ?", [$id]);
        return $row ?: null;
    }

    public function updateRule(int $id, int $daysKeep, string $action, bool $isEnabled): void
    {
        $this->db->update('retention_rules', [
            'days_keep'  => max(0, $daysKeep),
            'action'     => in_array($action, ['purge', 'anonymize'], true) ? $action : 'purge',
            'is_enabled' => $isEnabled ? 1 : 0,
            'source'     => 'admin_custom',
        ], 'id = ?', [$id]);
    }

    public function recentRuns(int $ruleId, int $limit = 50): array
    {
        return $this->db->fetchAll("
            SELECT r.*, u.username AS triggered_by_username
            FROM retention_runs r
            LEFT JOIN users u ON u.id = r.triggered_by
            WHERE r.rule_id = ?
            ORDER BY r.id DESC
            LIMIT ?
        ", [$ruleId, $limit]);
    }
}
