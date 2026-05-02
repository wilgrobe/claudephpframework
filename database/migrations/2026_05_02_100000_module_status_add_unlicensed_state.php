<?php
// database/migrations/2026_05_02_100000_module_status_add_unlicensed_state.php
use Core\Database\Migration;

/**
 * Extend module_status.state to include 'disabled_unlicensed'.
 *
 * Premium modules go through an EntitlementCheck gate at boot
 * (see core/Module/EntitlementCheck and ModuleRegistry::resolveDependencies).
 * A `false` return from the gate skips the module like an admin-disable
 * would, but we want admins to be able to tell entitlement-denied
 * modules apart from disabled_dependency / disabled_admin in the UI —
 * one is a billing concern, the other is a deploy concern.
 *
 * MODIFY COLUMN ... ENUM in MySQL is non-blocking for ENUM widening
 * (existing rows keep their value, the table doesn't need a rewrite).
 * Safe to run on a populated table.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE module_status
            MODIFY COLUMN state ENUM(
                'active',
                'disabled_dependency',
                'disabled_admin',
                'disabled_unlicensed'
            ) NOT NULL DEFAULT 'active'
        ");
    }

    public function down(): void
    {
        // Roll back: any row currently in the new state has to be
        // reset before MySQL will let us drop it from the ENUM.
        $this->db->query("
            UPDATE module_status
               SET state = 'disabled_admin'
             WHERE state = 'disabled_unlicensed'
        ");
        $this->db->query("
            ALTER TABLE module_status
            MODIFY COLUMN state ENUM(
                'active',
                'disabled_dependency',
                'disabled_admin'
            ) NOT NULL DEFAULT 'active'
        ");
    }
};
