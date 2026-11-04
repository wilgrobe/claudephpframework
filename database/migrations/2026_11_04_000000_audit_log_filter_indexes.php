<?php
// database/migrations/2026_11_04_000000_audit_log_filter_indexes.php
use Core\Database\Migration;

/**
 * #466 perf (4 Nov 2026): the audit-log viewer filters by action or actor and sorts newest first. With only the
 * single-column indexes MySQL found the matching rows, then sorted them all: a deep page of "action = auth.failed"
 * on 200k rows took ~400 ms. (action, created_at, id) and (actor_user_id, created_at, id) let it read them already
 * in order — 0.7 ms. Idempotent.
 */
return new class extends Migration {
    private const INDEXES = [
        'idx_audit_action_time' => '(action, created_at, id)',
        'idx_audit_actor_time'  => '(actor_user_id, created_at, id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $cols) {
            if (!$this->has($name)) $this->db->query("ALTER TABLE audit_log ADD INDEX $name $cols");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->has($name)) $this->db->query("ALTER TABLE audit_log DROP INDEX $name");
        }
    }

    private function has(string $index): bool
    {
        return (bool) $this->db->fetchColumn(
            "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'audit_log' AND index_name = ? LIMIT 1",
            [$index]
        );
    }
};
