<?php
// database/migrations/2026_04_25_300000_create_module_status_table.php
use Core\Database\Migration;

/**
 * `module_status` — tracks runtime state for every discovered module.
 *
 * The dependency checker (core/Module/DependencyChecker) computes
 * "active" vs "skipped" on every boot; this table is the persistence
 * layer the SA notification logic uses to detect TRANSITIONS so we
 * don't re-notify on every request.
 *
 * Columns:
 *   module_name      — primary key, matches ModuleProvider::name()
 *   state            — current runtime state
 *   missing_deps     — JSON array of module names that were missing,
 *                      populated when state is 'disabled_dependency'
 *   notice           — free-form admin-facing message (rarely set in v1;
 *                      reserved for future "disabled by SA" reasons)
 *   updated_at       — last time the state changed
 *
 * Idempotent: ON DUPLICATE KEY UPDATE in the registry's writer means
 * a re-run on an already-tracked module updates the row in place
 * rather than failing on the PK collision.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS module_status (
                module_name   VARCHAR(64) NOT NULL,
                state         ENUM('active','disabled_dependency','disabled_admin') NOT NULL DEFAULT 'active',
                missing_deps  JSON NULL,
                notice        TEXT NULL,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (module_name),
                INDEX idx_module_state (state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS module_status");
    }
};
