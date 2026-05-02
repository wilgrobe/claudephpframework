<?php
// modules/gdpr/migrations/2026_05_02_400000_seed_account_data_chrome.php
use Core\Database\Migration;
use Core\Services\SystemLayoutService;

/**
 * Page-chrome Batch B — first conversion: /account/data.
 *
 * Seeds the `account.data` system layout + a single `primary`
 * content slot so the controller's view (now a fragment) gets stitched
 * inside it by ChromeWrapper. Default shape: 1 row × 1 col, slot fills
 * the whole grid → renders identically to the pre-chrome page out of
 * the box. Admins can drop a "We respect your privacy" hero strip
 * above the slot from /admin/system-layouts/account.data.
 *
 * Slug naming: the layout's name mirrors the URL it chromes —
 * `/account/data` becomes `account.data` (slash → dot). This is the
 * Batch C convention; module-internal prefixes (`gdpr.account_data`
 * was the pre-rename slug) made admins guess what surface they were
 * editing.
 *
 * Idempotent: SystemLayoutService::seedLayout / seedSlot both use
 * INSERT IGNORE on their natural keys, so re-running this migration
 * after admin edits won't clobber anything.
 *
 * Rollback: down() respects admin customisation. If the layout still
 * has exactly the seeded shape (one placement — the content slot in
 * cell 0,0), we delete it on rollback. If the admin has added any
 * blocks, we leave the row alone — the same protective pattern the
 * 2026-05-02 policy seed migration uses, just expressed as a
 * "shape match" rather than a content hash because layout
 * customisation is detectable without hashing.
 */
return new class extends Migration {
    private const LAYOUT_NAME = 'account.data';

    public function up(): void
    {
        $svc = new SystemLayoutService();

        $svc->seedLayout(self::LAYOUT_NAME, [
            'friendly_name' => 'Account — Your data & privacy',
            'module'        => 'gdpr',
            'category'      => 'Account',
            'description'   => 'Wraps the /account/data self-service page. Drop blocks above or below the page content to add a privacy hero, a contact-DPO CTA, etc.',
            'chromed_url'   => '/account/data',
            'rows'          => 1,
            'cols'          => 1,
            'col_widths'    => [100],
            'row_heights'   => [100],
            'gap_pct'       => 0,
            'max_width_px'  => 800,
        ]);

        $svc->seedSlot(self::LAYOUT_NAME, 'primary', 0, 0);
    }

    public function down(): void
    {
        // Only delete the layout if it still has exactly the seeded
        // shape — one placement (the content slot). If the admin has
        // added blocks via /admin/system-layouts/account.data,
        // leave the layout (and their work) alone.
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

        // CASCADE on system_block_placements drops the slot row too.
        $this->db->query("DELETE FROM system_layouts WHERE name = ?", [self::LAYOUT_NAME]);
    }
};
