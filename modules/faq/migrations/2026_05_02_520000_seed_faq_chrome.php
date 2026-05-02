<?php
// modules/faq/migrations/2026_05_02_520000_seed_faq_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch C — convert /faq (the public FAQ page) to chrome.
 *
 * Default layout: 1×1, slot fills the grid, max-width 800px to match
 * the existing FAQ container. Admins can drop a "Still need help?
 * Contact support" CTA below the questions, or a featured-articles
 * sidebar.
 *
 * The /admin/faqs admin pages stay un-chromed — admin surfaces are
 * dense and predictable per page-chrome plan §"Non-goals".
 */
return new class extends Migration {
    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout('faq', [
            'friendly_name' => 'FAQ — public page',
            'module'        => 'faq',
            'category'      => 'Help',
            'description'   => 'Wraps /faq. Drop a "Still need help?" CTA below or a featured-articles sidebar.',
            'chromed_url'   => '/faq',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 800,
        ]);
        $svc->seedSlot('faq', 'primary', 0, 0);
    }

    public function down(): void
    {
        $count = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM system_block_placements WHERE system_name = ?",
            ['faq']
        )['c'] ?? 0);
        if ($count !== 1) return;

        $slot = $this->db->fetchOne(
            "SELECT placement_type, slot_name, row_index, col_index
               FROM system_block_placements
              WHERE system_name = ?",
            ['faq']
        );
        if (!$slot
            || ($slot['placement_type'] ?? null) !== 'content_slot'
            || (int) $slot['row_index'] !== 0
            || (int) $slot['col_index'] !== 0
            || (($slot['slot_name'] ?? null) ?: 'primary') !== 'primary') {
            return;
        }

        $this->db->query("DELETE FROM system_layouts WHERE name = ?", ['faq']);
    }
};
