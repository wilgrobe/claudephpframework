<?php
// modules/profile/migrations/2026_05_02_510000_seed_profile_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch C — convert the profile module's user-facing
 * pages: /profile (read-only view) and /profile/edit (the form).
 *
 * Two layouts seeded, both 1×1 with a single `primary` content slot
 * filling the grid → renders identically to the pre-chrome pages.
 * Admins can decorate either independently from
 * /admin/system-layouts/profile and /admin/system-layouts/profile.edit
 * — useful patterns: a profile-tips sidebar on the read view, a
 * "fields you can change" callout above the edit form.
 *
 * The view widths differ in the un-chromed pages (760px on /profile,
 * 560px on /profile/edit), so each layout's max_width_px matches its
 * existing visible container — keeps the side-by-side default identical.
 *
 * Idempotent. Rollback only deletes layouts that still match the
 * seeded shape (single content_slot in cell 0,0); admin-added blocks
 * survive `migrate:rollback`.
 */
return new class extends Migration {
    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout('profile', [
            'friendly_name' => 'Profile — read view',
            'module'        => 'profile',
            'category'      => 'Account',
            'description'   => 'Wraps /profile (the read-only view a user sees of their own account). Drop blocks to add a sidebar of profile tips, recent activity, etc.',
            'chromed_url'   => '/profile',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 760,
        ]);
        $svc->seedSlot('profile', 'primary', 0, 0);

        $svc->seedLayout('profile.edit', [
            'friendly_name' => 'Profile — edit form',
            'module'        => 'profile',
            'category'      => 'Account',
            'description'   => 'Wraps /profile/edit. Drop a blurb above the form ("fields you can change", privacy reminder) or a help block beside it.',
            'chromed_url'   => '/profile/edit',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 560,
        ]);
        $svc->seedSlot('profile.edit', 'primary', 0, 0);
    }

    public function down(): void
    {
        foreach (['profile', 'profile.edit'] as $name) {
            $count = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM system_block_placements WHERE system_name = ?",
                [$name]
            )['c'] ?? 0);
            if ($count !== 1) continue;

            $slot = $this->db->fetchOne(
                "SELECT placement_type, slot_name, row_index, col_index
                   FROM system_block_placements
                  WHERE system_name = ?",
                [$name]
            );
            if (!$slot
                || ($slot['placement_type'] ?? null) !== 'content_slot'
                || (int) $slot['row_index'] !== 0
                || (int) $slot['col_index'] !== 0
                || (($slot['slot_name'] ?? null) ?: 'primary') !== 'primary') {
                continue;
            }

            $this->db->query("DELETE FROM system_layouts WHERE name = ?", [$name]);
        }
    }
};
