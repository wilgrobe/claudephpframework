<?php
use Core\Database\Migration;

/**
 * Take admin-sidebar entries that go nowhere out of the menu.
 *
 * A nav entry that 404s is worse than an absent one: it reads as a feature you
 * have and cannot find, so the natural conclusion is that something is broken
 * rather than that it was never here.
 *
 * ⚠⚠⚠ THE HARD PART IS "GOES NOWHERE", AND THE FIRST VERSION GOT IT WRONG.
 * It matched `/admin/roles` to a module called `roles` and `/admin/newsletters`
 * to one called `newsletters`. Neither exists. The roles admin belongs to the
 * premium GROUPS module and the newsletter admin to NEWSLETTER, so the check
 * asked about modules nobody ships and concluded both links were dead
 * everywhere. On the apex `/admin/roles` answers 302 — it works — and on a
 * tenant entitled to groups it works too. That version would have deleted a
 * live link from the apex and from every site that had bought the feature.
 *
 * ⚠⚠ AND PRESENCE IS NOT AVAILABILITY. On a standalone site a module either
 * ships or it does not, so the disk answers. On the Builder every premium
 * module is on disk for every tenant and only the ENTITLED ones load, so the
 * disk says yes for sites where the link still 404s. The two questions have
 * different answers in the two places, and each install has to be asked the
 * one that applies to it — which is why this reads the entitlement list out of
 * the database it is running against.
 *
 * Fail-open throughout: anything unreadable or ambiguous leaves the link
 * alone. A dead link is untidy, and deleting a working one is a regression.
 */
return new class extends Migration {
    /** Sidebar URL => the module that actually registers it. */
    private const DEAD = [
        '/admin/roles'       => 'groups',
        '/admin/newsletters' => 'newsletter',
    ];

    public function up(): void
    {
        foreach (self::DEAD as $url => $owner) {
            if (!$this->linkIsDead($owner)) { continue; }

            try {
                $this->db->query('DELETE FROM menu_items WHERE url = ?', [$url]);
            } catch (\Throwable $e) {
                // A fresh install with no menus yet is not a failure.
            }
        }
    }

    /**
     * Is the module that owns this link unreachable on THIS install?
     */
    private function linkIsDead(string $owner): bool
    {
        // Standalone: not on disk, not reachable, nothing more to ask.
        if (function_exists('module_installed') && !module_installed($owner)) {
            return true;
        }

        // Hosted: on disk for everyone, loaded only for those who bought it.
        // The list lives in this tenant's own settings, so this works whichever
        // database the migration is pointed at.
        try {
            $row = $this->db->fetchOne(
                "SELECT value FROM settings WHERE `key` = 'builder.entitled_modules' AND scope_key <=> NULL LIMIT 1"
            );
        } catch (\Throwable $e) {
            return false;                       // cannot tell — keep the link
        }

        // No entitlement list means this is not a gated tenant -- the apex, or
        // a standalone site. There the question is simply whether the module
        // LOADED, and the registry knows: the apex boots a lean premium pool,
        // so `newsletter` is on disk and not running, which is exactly why
        // /admin/newsletters 404s there while /admin/roles does not.
        if (!$row) {
            return function_exists('module_active') ? !module_active($owner) : false;
        }

        $list = json_decode((string) ($row['value'] ?? ''), true);
        if (!is_array($list)) { return false; } // unreadable — keep the link

        // Slugs and folder names disagree about hyphens, so try the spellings.
        foreach ([$owner, str_replace('-', '', $owner), str_replace('-', '_', $owner)] as $name) {
            if (in_array($name, $list, true)) { return false; }
        }

        return true;
    }

    public function down(): void
    {
        // Deliberately not reinstated. Putting a 404 back into somebody's
        // sidebar is not a rollback anyone wants, and the seed will recreate
        // these on an install that should have them.
    }
};
