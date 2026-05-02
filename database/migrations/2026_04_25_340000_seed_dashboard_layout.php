<?php
// database/migrations/2026_04_25_340000_seed_dashboard_layout.php
use Core\Database\Migration;

/**
 * Seeds the default `dashboard_stats` and `dashboard_main` system layouts
 * + their default placements so /dashboard renders identically out of the
 * box once Batch 3 lands.
 *
 *   dashboard_stats — 1 row × 4 cols, each ~24%, 1% gap. Cells are filled
 *                     in viewer order:
 *                       (0,0) groups.my_groups_count
 *                       (0,1) notifications.unread_count
 *                       (0,2) groups.total_users_count   (admin-only render)
 *                       (0,3) groups.total_count         (admin-only render)
 *                     The two admin-only blocks return '' for non-admins,
 *                     leaving those cells empty — same visual as before
 *                     the composer existed.
 *
 *   dashboard_main  — 1 row × 2 cols, ~70/27 split with 3% gap. Cell (0,0)
 *                     hosts the content feed; cell (0,1) stacks two
 *                     placements (My Groups list, then Recent Notifications)
 *                     by sort_order.
 *
 * Idempotent — `INSERT IGNORE` for layouts and a "wipe-then-seed" pattern
 * for placements so re-running the migration restores the defaults
 * (admins who customised will regenerate after running the migration).
 *
 * NOTE: this is a destructive seed in the sense that it wipes existing
 * placements for these two named layouts. That's deliberate — the
 * migrator only re-runs successful migrations on rollback+remigrate, so
 * normal `artisan migrate` won't repeatedly clobber an admin's tweaks.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── dashboard_stats: 1 × 4 grid of stat cards ─────────────────
        $this->db->query(
            "INSERT IGNORE INTO system_layouts
                (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['dashboard_stats', 1, 4, json_encode([24,24,24,24]), json_encode([100]), 1, 1280]
        );

        $this->db->query("DELETE FROM system_block_placements WHERE system_name = 'dashboard_stats'");
        $statsPlacements = [
            ['groups.my_groups_count',     0, 0],
            ['notifications.unread_count', 0, 1],
            ['groups.total_users_count',   0, 2],
            ['groups.total_count',         0, 3],
        ];
        foreach ($statsPlacements as [$key, $row, $col]) {
            $this->db->insert('system_block_placements', [
                'system_name' => 'dashboard_stats',
                'row_index'   => $row,
                'col_index'   => $col,
                'sort_order'  => 0,
                'block_key'   => $key,
                'settings'    => null,
                'visible_to'  => 'auth',
            ]);
        }

        // ── dashboard_main: 1 × 2 grid (content + sidebar stack) ─────
        $this->db->query(
            "INSERT IGNORE INTO system_layouts
                (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['dashboard_main', 1, 2, json_encode([70, 27]), json_encode([100]), 3, 1280]
        );

        $this->db->query("DELETE FROM system_block_placements WHERE system_name = 'dashboard_main'");
        $mainPlacements = [
            // Left column — the big content feed
            ['content.dashboard_feed',     0, 0, 0],
            // Right column — sidebar items stack via sort_order
            ['groups.my_groups_list',      0, 1, 0],
            ['notifications.recent_list',  0, 1, 1],
        ];
        foreach ($mainPlacements as [$key, $row, $col, $order]) {
            $this->db->insert('system_block_placements', [
                'system_name' => 'dashboard_main',
                'row_index'   => $row,
                'col_index'   => $col,
                'sort_order'  => $order,
                'block_key'   => $key,
                'settings'    => null,
                'visible_to'  => 'auth',
            ]);
        }
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM system_block_placements WHERE system_name IN ('dashboard_stats','dashboard_main')");
        $this->db->query("DELETE FROM system_layouts          WHERE name        IN ('dashboard_stats','dashboard_main')");
    }
};
