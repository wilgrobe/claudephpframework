<?php
// tests/Unit/Modules/Gdpr/AccountDataChromeTest.php
namespace Tests\Unit\Modules\Gdpr;

use Tests\TestCase;

/**
 * Page-chrome Batch B regression test.
 *
 * Asserts that the /account/data conversion stays wired together as
 * Batch B intends:
 *
 *   1. The seed migration exists and references the right layout name
 *      + slot name.
 *   2. AccountDataController::index() chains ->withLayout() to opt
 *      into chrome.
 *   3. The view is a fragment — does NOT include layout/header.php
 *      or layout/footer.php directly. The chrome wrapper provides
 *      those.
 *
 * Static-source regressions only — exercising the full chrome wrap
 * needs MySQL and a bound BlockRegistry. The unit-level chrome path
 * is already covered by tests/Unit/Http/PageChromeBatchATest.php.
 */
final class AccountDataChromeTest extends TestCase
{
    public function test_seed_migration_exists_and_targets_correct_layout_name(): void
    {
        $path = BASE_PATH . '/modules/gdpr/migrations/2026_05_02_400000_seed_account_data_chrome.php';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("'account.data'", $src,
            'Seed migration must use the canonical layout name "account.data" — the '
            . 'controller chains ->withLayout(\'account.data\') and a mismatch silently '
            . 'disables chrome (graceful fallback to unwrapped). Slug mirrors '
            . 'the /account/data URL so admins can find it without a decoder ring.');
        $this->assertStringContainsString('seedLayout', $src,
            'Migration must use SystemLayoutService::seedLayout (Batch A helper) for idempotency.');
        $this->assertStringContainsString('seedSlot', $src,
            'Migration must add a content slot — without it the layout would render '
            . 'empty around a now-missing controller view.');
        $this->assertStringContainsString("'primary'", $src,
            'Default slot name is "primary" — controller does not pass an override.');
    }

    public function test_controller_chains_withLayout_on_index(): void
    {
        $path = BASE_PATH . '/modules/gdpr/Controllers/AccountDataController.php';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("->withLayout('account.data')", $src,
            'AccountDataController::index must opt into chrome by chaining '
            . '->withLayout(\'account.data\'). Without it the converted fragment '
            . 'view renders without a header/footer wrapping document.');
    }

    public function test_view_is_a_fragment_no_header_or_footer_includes(): void
    {
        $path = BASE_PATH . '/modules/gdpr/Views/account/index.php';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        // The fragment must NOT include the global layout partials —
        // those are now provided by ChromeWrapper. If a future edit
        // accidentally re-adds the include, the page would render
        // with two headers and two footers.
        $this->assertFalse(str_contains($src, "include BASE_PATH . '/app/Views/layout/header.php'"),
            'View was converted to a fragment in Batch B — re-adding the header.php '
            . 'include would double-wrap the response when chrome is active.');
        $this->assertFalse(str_contains($src, "include BASE_PATH . '/app/Views/layout/footer.php'"),
            'View was converted to a fragment in Batch B — re-adding the footer.php '
            . 'include would double-close the document.');

        // $pageTitle must still be set so capture-and-emit can surface
        // it in the outer header. Without this assertion a refactor
        // could silently drop the title from the rendered page.
        $this->assertStringContainsString('$pageTitle', $src,
            'Fragment must still set $pageTitle — capture-and-emit (View::renderFragment) '
            . 'snapshots it for the outer header.php.');
    }
}
