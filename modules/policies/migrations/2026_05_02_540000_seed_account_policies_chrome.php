<?php
// modules/policies/migrations/2026_05_02_540000_seed_account_policies_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch C — convert /account/policies (the per-user
 * "what have I accepted and when" history) to chrome.
 *
 * Slug: `account.policies` (slash → dot).
 * Default layout: 1×1, slot fills the grid, max-width 760px.
 */
return new class extends Migration {
    private const LAYOUT_NAME = 'account.policies';

    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout(self::LAYOUT_NAME, [
            'friendly_name' => 'Account — Policy acceptances',
            'module'        => 'policies',
            'category'      => 'Account',
            'description'   => 'Wraps /account/policies (per-user history of which policy versions the user accepted, and when).',
            'chromed_url'   => '/account/policies',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 760,
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
