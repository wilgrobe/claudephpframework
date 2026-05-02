<?php
// tests/Unit/Http/PageChromeBatchCTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * Page-chrome Batch C aggregate regression test.
 *
 * One data-driven block per converted surface — confirms three things
 * for each:
 *
 *   1. The seed migration exists and seeds the documented slug.
 *   2. The owning controller (or the route closure for `/search`)
 *      chains ->withLayout('<slug>') so the response actually opts
 *      into chrome.
 *   3. The corresponding fragment view does NOT include the global
 *      layout/header.php or layout/footer.php files. Re-adding either
 *      would double-wrap the response when chrome is active.
 *
 * Also asserts the renamed `account.data` slug + the canonicalName
 * regex update that allows hyphens. Together these tripwires catch
 * every "I refactored this and forgot to update the slug" failure
 * mode the conversion pattern exposes.
 */
final class PageChromeBatchCTest extends TestCase
{
    /**
     * Each converted surface's wiring snapshot.
     *
     * @return array<int, array{
     *   slug: string,
     *   migration: string,
     *   controller_or_route: string,
     *   view: string,
     *   needle: string,   // string the controller/route source must contain
     * }>
     */
    private static function surfaces(): array
    {
        return [
            // /account/data — renamed from gdpr.account_data in Batch C
            [
                'slug'                => 'account.data',
                'migration'           => 'modules/gdpr/migrations/2026_05_02_400000_seed_account_data_chrome.php',
                'controller_or_route' => 'modules/gdpr/Controllers/AccountDataController.php',
                'view'                => 'modules/gdpr/Views/account/index.php',
                'needle'              => "->withLayout('account.data')",
            ],
            // /profile
            [
                'slug'                => 'profile',
                'migration'           => 'modules/profile/migrations/2026_05_02_510000_seed_profile_chrome.php',
                'controller_or_route' => 'modules/profile/Controllers/ProfileController.php',
                'view'                => 'modules/profile/Views/show.php',
                'needle'              => "->withLayout('profile')",
            ],
            // /profile/edit — same controller, separate layout
            [
                'slug'                => 'profile.edit',
                'migration'           => 'modules/profile/migrations/2026_05_02_510000_seed_profile_chrome.php',
                'controller_or_route' => 'modules/profile/Controllers/ProfileController.php',
                'view'                => 'modules/profile/Views/edit.php',
                'needle'              => "->withLayout('profile.edit')",
            ],
            // /faq
            [
                'slug'                => 'faq',
                'migration'           => 'modules/faq/migrations/2026_05_02_520000_seed_faq_chrome.php',
                'controller_or_route' => 'modules/faq/Controllers/FaqController.php',
                'view'                => 'modules/faq/Views/public.php',
                'needle'              => "->withLayout('faq')",
            ],
            // /account/email-preferences
            [
                'slug'                => 'account.email-preferences',
                'migration'           => 'modules/email/migrations/2026_05_02_530000_seed_account_email_preferences_chrome.php',
                'controller_or_route' => 'modules/email/Controllers/UnsubscribeController.php',
                'view'                => 'modules/email/Views/account/preferences.php',
                'needle'              => "->withLayout('account.email-preferences')",
            ],
            // /account/policies
            [
                'slug'                => 'account.policies',
                'migration'           => 'modules/policies/migrations/2026_05_02_540000_seed_account_policies_chrome.php',
                'controller_or_route' => 'modules/policies/Controllers/PolicyController.php',
                'view'                => 'modules/policies/Views/account/index.php',
                'needle'              => "->withLayout('account.policies')",
            ],
            // /search — closure-based route in routes/web.php
            [
                'slug'                => 'search',
                'migration'           => 'database/migrations/2026_05_02_550000_seed_search_chrome.php',
                'controller_or_route' => 'routes/web.php',
                'view'                => 'app/Views/public/search.php',
                'needle'              => "->withLayout('search')",
            ],
        ];
    }

    public function test_each_surface_seeds_its_slug_chains_withLayout_and_view_is_a_fragment(): void
    {
        foreach (self::surfaces() as $s) {
            $migPath = BASE_PATH . '/' . $s['migration'];
            $ctlPath = BASE_PATH . '/' . $s['controller_or_route'];
            $viewPath = BASE_PATH . '/' . $s['view'];

            $this->assertFileExists($migPath, "Migration missing for slug `{$s['slug']}`");
            $this->assertFileExists($ctlPath, "Controller/route missing for slug `{$s['slug']}`");
            $this->assertFileExists($viewPath, "View missing for slug `{$s['slug']}`");

            $migSrc = (string) file_get_contents($migPath);
            $ctlSrc = (string) file_get_contents($ctlPath);
            $viewSrc = (string) file_get_contents($viewPath);

            // (1) Migration uses the documented slug.
            $this->assertStringContainsString(
                "'{$s['slug']}'", $migSrc,
                "Migration `{$s['migration']}` must seed slug `{$s['slug']}` — "
                . 'mismatch silently disables chrome on the controller side (graceful fallback to unwrapped page).'
            );

            // (2) Controller/route chains the right withLayout call.
            $this->assertStringContainsString(
                $s['needle'], $ctlSrc,
                "Controller/route `{$s['controller_or_route']}` must chain `{$s['needle']}` "
                . 'or the converted fragment view will render without a header/footer wrapping document.'
            );

            // (3) View is a fragment — does NOT pull in header/footer.
            $this->assertFalse(
                str_contains($viewSrc, "/app/Views/layout/header.php"),
                "View `{$s['view']}` was converted to a fragment — re-adding the header.php "
                . 'include would double-wrap the response when chrome is active.'
            );
            $this->assertFalse(
                str_contains($viewSrc, "/app/Views/layout/footer.php"),
                "View `{$s['view']}` was converted to a fragment — re-adding the footer.php "
                . 'include would double-close the document.'
            );
        }
    }

    public function test_canonicalName_regex_allows_hyphens(): void
    {
        // Indirect test — the regex lives in a private method on
        // SystemLayoutAdminController. Assert via source inspection
        // that the character class includes the hyphen, which is the
        // change that lets `account.email-preferences` and similar
        // slugs route through /admin/system-layouts/{name}.
        $path = BASE_PATH . '/app/Controllers/SystemLayoutAdminController.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "/^[a-zA-Z0-9_.-]+$/", $src,
            'canonicalName regex must include hyphen so URL-mirroring slugs '
            . 'like `account.email-preferences` and `cookie-consent` route through '
            . '/admin/system-layouts/{name}. Reverting to [a-zA-Z0-9_.] would 500 '
            . 'every Batch C admin layout edit page that uses a hyphenated slug.'
        );
    }

    public function test_rename_migration_handles_existing_gdpr_account_data_row(): void
    {
        // The Batch B migration was renamed in place to seed under
        // `account.data`, but existing installs already have the row
        // at `gdpr.account_data`. The rename migration cleans that up.
        $path = BASE_PATH . '/database/migrations/2026_05_02_500000_rename_gdpr_account_data_layout.php';
        $this->assertFileExists($path,
            'Rename migration must exist so installs that ran Batch B before the slug '
            . 'change get their layout migrated to the new name.');

        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("'gdpr.account_data'", $src,
            'Rename migration must reference the legacy slug.');
        $this->assertStringContainsString("'account.data'", $src,
            'Rename migration must reference the new slug.');
        $this->assertStringContainsString('UPDATE system_block_placements', $src,
            'Rename must re-point child placements at the renamed parent — direct UPDATE '
            . 'of system_layouts.name would violate the FK because the FK is ON DELETE CASCADE only, '
            . 'not ON UPDATE CASCADE.');
        $this->assertStringContainsString('transaction', $src,
            'Multi-step rename must be wrapped in a transaction so a half-applied migration '
            . 'can\'t leave the DB inconsistent.');
    }
}
