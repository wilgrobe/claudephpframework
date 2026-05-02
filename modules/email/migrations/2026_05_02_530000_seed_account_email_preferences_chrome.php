<?php
// modules/email/migrations/2026_05_02_530000_seed_account_email_preferences_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch C — convert /account/email-preferences (the
 * unsubscribe / preference center) to chrome.
 *
 * Slug: `account.email-preferences` (slash → dot, hyphen pass-through).
 * Default layout: 1×1, slot fills the grid, max-width 720px to match
 * the existing preference-center container.
 *
 * Admins might add a "Why are we sending you these?" callout above
 * the preferences list, or a contact-DPO link below.
 */
return new class extends Migration {
    private const LAYOUT_NAME = 'account.email-preferences';

    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout(self::LAYOUT_NAME, [
            'friendly_name' => 'Account — Email preferences',
            'module'        => 'email',
            'category'      => 'Account',
            'description'   => 'Wraps /account/email-preferences (the per-user unsubscribe + category preferences page).',
            'chromed_url'   => '/account/email-preferences',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 720,
        ]);
        $svc->seedSlot(self::LAYOUT_NAME, 'primary', 0, 0);
    }

    public function down(): void
    {
        $count = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM system_block_placements WHERE system_name = ?",
            [self::LAYOUT_NAME]
        )['c'] ?? 0);
        if ($count !== 1) return;

        $slot = $this->db->fetchOne(
            "SELECT placement_type, slot_name, row_index, col_index
               FROM system_block_placements
              WHERE system_name = ?",
            [self::LAYOUT_NAME]
        );
        if (!$slot
            || ($slot['placement_type'] ?? null) !== 'content_slot'
            || (int) $slot['row_index'] !== 0
            || (int) $slot['col_index'] !== 0
            || (($slot['slot_name'] ?? null) ?: 'primary') !== 'primary') {
            return;
        }

        $this->db->query("DELETE FROM system_layouts WHERE name = ?", [self::LAYOUT_NAME]);
    }
};
