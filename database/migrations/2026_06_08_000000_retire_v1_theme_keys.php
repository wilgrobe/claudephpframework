<?php
// database/migrations/2026_06_08_000000_retire_v1_theme_keys.php
use Core\Database\Migration;

/**
 * Theming v2 — retire the v1 colour setting-keys in the `settings` table by
 * renaming each to its v2 theme.palette.* equivalent (+ the `.dark` sibling),
 * and dropping a few dead orphan keys nothing reads.
 *
 * Runs against the `settings` table, which exists in BOTH central (apex
 * site-scoped theme) and every tenant DB — so it migrates apex + tenants when
 * `php artisan migrate` (central) and `tenant:migrate` (tenants) are run.
 * The central control-plane `project_settings` table is handled by the
 * sibling central migration of the same date.
 *
 * Chrome (theme.color.chrome.*) is deliberately NOT renamed — it's its own v2
 * role with no v1↔v2 duality (see docs/theming-v2-spec.md §14).
 *
 * Idempotent: UPDATE IGNORE skips a rename that would collide with an already-
 * present v2 row, then DELETE removes the leftover v1 row. On a fresh install
 * the seed migrations already write v2 keys, so this finds nothing and no-ops.
 * One-way (down() is a no-op) — v1 keys are retired, not restored.
 */
return new class extends Migration {
    private const MAP = [
        'color_primary'              => 'theme.palette.primary.bg',
        'color_primary_dark'         => 'theme.palette.primary.grad',
        'color_secondary'            => 'theme.palette.secondary.bg',
        'color_success'              => 'theme.palette.default.success',
        'color_warning'              => 'theme.palette.default.warning',
        'color_danger'               => 'theme.palette.default.danger',
        'color_info'                 => 'theme.palette.default.info',
        'theme.color.bg.page'        => 'theme.palette.default.bg',
        'theme.color.bg.panel'       => 'theme.palette.default.surface',
        'theme.color.text.default'   => 'theme.palette.default.text',
        'theme.color.text.muted'     => 'theme.palette.default.text-muted',
        'theme.color.text.subtle'    => 'theme.palette.default.text-subtle',
        'theme.color.border.default' => 'theme.palette.default.border',
        'theme.color.border.strong'  => 'theme.palette.default.border-strong',
        'theme.color.border.subtle'  => 'theme.palette.default.border-subtle',
        'theme.color.accent.subtle'  => 'theme.palette.default.accent-tint',
        'theme.color.accent.contrast'=> 'theme.palette.default.accent-contrast',
    ];

    /** Dead keys: written by old presets/seeds, read by nothing. Just drop. */
    private const DEAD = [
        'theme.color.text.inverse', 'theme.color.bg.overlay',
        'theme.color.bg.block', 'theme.color.bg.cell',
    ];

    public function up(): void
    {
        $hasTable = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'settings'"
        );
        if ($hasTable === 0) return;

        foreach (self::MAP as $v1 => $v2) {
            foreach ([[$v1, $v2], [$v1 . '.dark', $v2 . '.dark']] as [$from, $to]) {
                $this->db->query("UPDATE IGNORE settings SET `key` = ? WHERE `key` = ?", [$to, $from]);
                $this->db->query("DELETE FROM settings WHERE `key` = ?", [$from]);
            }
        }
        foreach (self::DEAD as $k) {
            $this->db->query("DELETE FROM settings WHERE `key` IN (?, ?)", [$k, $k . '.dark']);
        }
    }

    public function down(): void
    {
        // One-way: the v1 colour keys are retired. No restore.
    }
};
