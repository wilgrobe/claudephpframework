<?php
// modules/security/migrations/2026_04_30_700030_seed_pii_access_logging_setting.php
use Core\Database\Migration;

/**
 * Default for the PII access logging toggle. On by default — every
 * GET to a PII admin surface (/admin/users/*, /admin/sessions, etc.)
 * writes a pii.viewed audit row. SOC2 evaluators expect this; admins
 * can disable for noise reduction in low-stakes deployments.
 */
return new class extends Migration {
    public function up(): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM settings WHERE scope = 'site' AND scope_key IS NULL AND `key` = ?",
            ['admin_pii_access_logging_enabled']
        );
        if ($existing) return;

        $this->db->insert('settings', [
            'scope'     => 'site',
            'scope_key' => null,
            'key'       => 'admin_pii_access_logging_enabled',
            'value'     => 'true',
            'type'      => 'boolean',
            'is_public' => 0,
        ]);
    }

    public function down(): void {}
};
