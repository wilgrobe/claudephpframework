<?php
// database/migrations/2026_04_28_500000_add_theme_preference_to_users.php
use Core\Database\Migration;

/**
 * Add a per-user theme preference column to support the manual override
 * over the OS-level prefers-color-scheme media query.
 *
 * Three-state enum:
 *   'system' (default) — defer to the visitor's OS preference; the
 *                         media-query block in the theme override CSS
 *                         flips automatically. No body class applied.
 *   'light'            — force light, regardless of OS. body.theme-light
 *                         re-asserts light values to override the
 *                         @media (prefers-color-scheme: dark) block on
 *                         dark-OS devices.
 *   'dark'             — force dark. body.theme-dark applies the dark
 *                         palette unconditionally.
 *
 * For guests (no users row), the same value lives in a `theme_pref`
 * cookie - same enum, same semantics. The cookie also acts as a
 * first-paint hint so the body class can be applied server-side
 * before the page renders, avoiding the flash-of-wrong-theme.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            ALTER TABLE users
              ADD COLUMN theme_preference ENUM('system','light','dark')
                  NOT NULL DEFAULT 'system'
                  AFTER bio
        ");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE users DROP COLUMN theme_preference");
    }
};
