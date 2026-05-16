<?php
// database/migrations/2026_05_02_550000_seed_search_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch C — convert /search (the public site search results
 * page) to chrome.
 *
 * Slug: `search`. The search route is a closure in routes/web.php
 * — not a module — so this seed migration lives in the framework's
 * top-level database/migrations/ directory rather than a module
 * migrations/ folder. The chrome wrap itself is added inline to the
 * routes/web.php closure: ->withLayout('search').
 *
 * Default layout: 1×1, slot fills the grid, max-width 1024px (the
 * search view is wider than the typical account page — it has search
 * box + multiple result columns).
 *
 * Useful admin patterns: a "Can't find it? Contact us" CTA below the
 * results, a featured-articles or popular-searches sidebar.
 */
return new class extends Migration {
    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout('search', [
            'friendly_name' => 'Site search results',
            'module'        => 'core',
            'category'      => 'Public',
            'description'   => 'Wraps /search. Drop a "Can\'t find it? Contact us" CTA below the results, or a featured/popular-searches sidebar.',
            'chromed_url'   => '/search',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 1024,
        ]);
        $svc->seedSlot('search', 'primary', 0, 0);
    }

    public function down(): void
    {
        $count = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM system_block_placements WHERE system_name = ?",
            ['search']
        )['c'] ?? 0);
        if ($count !== 1) return;

        $slot = $this->db->fetchOne(
            "SELECT placement_type, slot_name, row_index, col_index
               FROM system_block_placements
              WHERE system_name = ?",
            ['search']
        );
        if (!$slot
            || ($slot['placement_type'] ?? null) !== 'content_slot'
            || (int) $slot['row_index'] !== 0
            || (int) $slot['col_index'] !== 0
            || (($slot['slot_name'] ?? null) ?: 'primary') !== 'primary') {
            return;
        }

        $this->db->query("DELETE FROM system_layouts WHERE name = ?", ['search']);
    }
};
