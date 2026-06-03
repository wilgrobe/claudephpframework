<?php
// database/migrations/2026_05_25_500000_create_health_notes.php
//
// Phase 43.124 — per-tenant pinned operator note for tenant-side
// /admin/health (Phase 43.110). Tenant-side analog of the central
// health_notes table from Phase 43.120.
//
// Same single-row design: at most one pinned note per tenant.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if ($this->tableExists('health_notes')) return;
        $this->db->query("
            CREATE TABLE health_notes (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                body            TEXT         NOT NULL,
                pinned_by_user_id INT UNSIGNED NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS health_notes");
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchColumn("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
        ", [$table]);
    }
};
