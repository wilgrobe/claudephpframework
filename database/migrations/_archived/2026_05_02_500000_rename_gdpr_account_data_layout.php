<?php
// database/migrations/2026_05_02_500000_rename_gdpr_account_data_layout.php
use Core\Database\Migration;

/**
 * Page-chrome Batch C — slug convention change.
 *
 * Batch B shipped the GDPR /account/data conversion under the slug
 * `gdpr.account_data`. Batch C standardises slugs to mirror the URL
 * (slash → dot, hyphen passes through) so admins editing layouts at
 * /admin/system-layouts/{slug} can pattern-match the slug to the
 * page it chromes — `account.data` for /account/data,
 * `profile.edit` for /profile/edit, etc.
 *
 * Existing installs that already ran the Batch B migration will have
 * a `gdpr.account_data` row in `system_layouts` (and any associated
 * placements an admin added). This migration renames that row to
 * `account.data` and migrates the placements with it. Fresh installs
 * never see this — Batch B's migration was edited in place to seed
 * under the new name, so this rename is a no-op when there's no
 * legacy row to fix up.
 *
 * Order matters because of the FK on system_block_placements
 * (REFERENCES system_layouts(name) ON DELETE CASCADE). We can't
 * UPDATE the parent first (would break the FK on the child rows),
 * and we can't UPDATE the children first (would point at a missing
 * parent). The safe sequence is:
 *
 *   1. INSERT the renamed row, copying every column from the legacy.
 *   2. UPDATE the children to point at the renamed row.
 *   3. DELETE the legacy row.
 *
 * Wrapped in a transaction so a half-applied rename can't leave the
 * DB inconsistent.
 *
 * Idempotent: if the legacy row doesn't exist (fresh install, rename
 * already applied, admin-deleted) the migration no-ops cleanly. If
 * the destination row already exists (admin manually created
 * `account.data` while the legacy still existed), we skip the rename
 * and leave both rows alone — that's a configuration the admin
 * needs to resolve themselves rather than a state we can safely
 * decide for them.
 */
return new class extends Migration {
    private const OLD_NAME = 'gdpr.account_data';
    private const NEW_NAME = 'account.data';

    public function up(): void
    {
        $legacy = $this->db->fetchOne(
            "SELECT * FROM system_layouts WHERE name = ?",
            [self::OLD_NAME]
        );
        if (!$legacy) {
            // Nothing to rename — fresh install or already migrated.
            return;
        }

        $existingNew = $this->db->fetchOne(
            "SELECT 1 FROM system_layouts WHERE name = ?",
            [self::NEW_NAME]
        );
        if ($existingNew) {
            // Admin (or a re-run of Batch B against the new slug)
            // already created `account.data`. Don't merge or
            // overwrite — surface a log line and leave the legacy
            // row alone. The admin can clean up via /admin/system-layouts.
            error_log(sprintf(
                "[%s] page-chrome rename skipped: both `%s` and `%s` exist; admin to resolve.",
                date('c'), self::OLD_NAME, self::NEW_NAME
            ));
            return;
        }

        $this->db->transaction(function () use ($legacy) {
            // 1. Copy the layout row under the new name. We carry
            //    every column the discoverability migration added
            //    (friendly_name, module, category, description) plus
            //    the original grid envelope.
            $this->db->insert('system_layouts', [
                'name'          => self::NEW_NAME,
                'friendly_name' => $legacy['friendly_name'] ?? null,
                'module'        => $legacy['module']        ?? null,
                'category'      => $legacy['category']      ?? null,
                'description'   => $legacy['description']   ?? null,
                'rows'          => (int) $legacy['rows'],
                'cols'          => (int) $legacy['cols'],
                'col_widths'    => $legacy['col_widths'],
                'row_heights'   => $legacy['row_heights'],
                'gap_pct'       => (int) $legacy['gap_pct'],
                'max_width_px'  => (int) $legacy['max_width_px'],
            ]);

            // 2. Re-point every placement at the new parent.
            $this->db->query(
                "UPDATE system_block_placements
                    SET system_name = ?
                  WHERE system_name = ?",
                [self::NEW_NAME, self::OLD_NAME]
            );

            // 3. Drop the legacy parent. CASCADE on the FK is benign
            //    here — the children were just re-pointed, so there's
            //    nothing left to cascade-delete.
            $this->db->query(
                "DELETE FROM system_layouts WHERE name = ?",
                [self::OLD_NAME]
            );
        });
    }

    public function down(): void
    {
        // Reverse the rename so a rollback of this migration restores
        // the legacy slug. Same FK dance, opposite direction. No-op
        // when the renamed row doesn't exist (rename never applied).
        $renamed = $this->db->fetchOne(
            "SELECT * FROM system_layouts WHERE name = ?",
            [self::NEW_NAME]
        );
        if (!$renamed) return;

        $existingOld = $this->db->fetchOne(
            "SELECT 1 FROM system_layouts WHERE name = ?",
            [self::OLD_NAME]
        );
        if ($existingOld) {
            error_log(sprintf(
                "[%s] page-chrome rename rollback skipped: both `%s` and `%s` exist; admin to resolve.",
                date('c'), self::OLD_NAME, self::NEW_NAME
            ));
            return;
        }

        $this->db->transaction(function () use ($renamed) {
            $this->db->insert('system_layouts', [
                'name'          => self::OLD_NAME,
                'friendly_name' => $renamed['friendly_name'] ?? null,
                'module'        => $renamed['module']        ?? null,
                'category'      => $renamed['category']      ?? null,
                'description'   => $renamed['description']   ?? null,
                'rows'          => (int) $renamed['rows'],
                'cols'          => (int) $renamed['cols'],
                'col_widths'    => $renamed['col_widths'],
                'row_heights'   => $renamed['row_heights'],
                'gap_pct'       => (int) $renamed['gap_pct'],
                'max_width_px'  => (int) $renamed['max_width_px'],
            ]);
            $this->db->query(
                "UPDATE system_block_placements
                    SET system_name = ?
                  WHERE system_name = ?",
                [self::OLD_NAME, self::NEW_NAME]
            );
            $this->db->query(
                "DELETE FROM system_layouts WHERE name = ?",
                [self::NEW_NAME]
            );
        });
    }
};
