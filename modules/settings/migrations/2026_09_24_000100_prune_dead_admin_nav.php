<?php
use Core\Database\Migration;

/**
 * Take admin-sidebar entries that go nowhere out of the menu.
 *
 * The sidebar is seeded with entries for modules an install may not ship, and
 * two of them routinely 404: `/admin/roles` (no roles admin here) and
 * `/admin/newsletters` (the premium newsletter admin -- an app whose own
 * newsletter is a different feature wearing a similar name still gets the
 * dead link).
 *
 * A nav entry that 404s is worse than an absent one: it reads as a feature you
 * have and cannot find, so the natural conclusion is that something is broken
 * rather than that it was never here.
 *
 * Matched on URL rather than id, because seeded ids differ between installs,
 * and only where the module really is absent -- so an install that has a roles
 * page keeps its link, and one that gains it later is untouched by a migration
 * that has already run. Idempotent: a second run finds nothing to do.
 */
return new class extends Migration {
    /** Sidebar URLs to drop, each with the module directory that would justify keeping it. */
    private const DEAD = [
        '/admin/roles'       => 'roles',
        '/admin/newsletters' => 'newsletters',
    ];

    public function up(): void
    {
        foreach (self::DEAD as $url => $module) {
            // Never prune something this install actually has.
            if (module_installed($module)) { continue; }

            try {
                $this->db->query('DELETE FROM menu_items WHERE url = ?', [$url]);
            } catch (\Throwable $e) {
                // A fresh install with no menus yet is not a failure.
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reinstated. Putting a 404 back into somebody's
        // sidebar is not a rollback anyone wants, and the seed will recreate
        // these on an install that should have them.
    }
};