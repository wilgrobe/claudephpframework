<?php
// modules/audit-log-viewer/migrations/2026_04_23_180000_seed_audit_viewer_permission.php
use Core\Database\Migration;

/**
 * The `audit_log` table itself is created by the core framework
 * (see database/schema.sql). This module is a read-only UI over
 * that table, so no CREATE TABLE — just a permission seed so the
 * surface can be granted to auditors who aren't full admins.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'View audit log',
            'audit.view',
            'audit-log-viewer',
            'Access the audit-log viewer at /admin/audit-log. Read-only.',
        ]);
    }

    public function down(): void {}
};
