<?php
// tests/Unit/Views/ScrollBetweenBarsTest.php
namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * With a pinned header and footer, content scrolls only BETWEEN them (2026-10-20, brought over from the Builder,
 * where it was measured live on three sites). The rules live in the footer partial both page shells include.
 * min-height:0 is load-bearing: header.php's .layout{min-height:100vh} otherwise beats the height and leaves the
 * content area running under the footer.
 */
final class ScrollBetweenBarsTest extends TestCase
{
    private static function footer(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Views/partials/site_footer.php');
    }

    public function test_the_shell_content_is_the_one_scroller_between_the_bars_on_desktop(): void
    {
        $f = self::footer();
        $block = substr($f, strpos($f, 'SCROLL BETWEEN THE BARS'), 3500);
        $this->assertStringContainsString('@media screen and (min-width: 641px)', $block, 'desktop only, screen only');
        $this->assertStringContainsString('body:not(.has-flow-footer):not(.shell-doc-scroll) .layout', $block, 'off when the footer flows; opt-out per page');
        $this->assertMatchesRegularExpression('/\.layout \{[^}]*height: calc\(100vh - var\(--site-footer-height\) - var\(--shell-top, 0px\)\);[^}]*min-height: 0;[^}]*overflow: hidden;/', $block);
        $this->assertMatchesRegularExpression('/> \.content \{[^}]*min-height: 0;[^}]*overflow-y: auto;[^}]*margin-top: var\(--shell-under-header, 0px\);/', $block);
    }

    public function test_the_script_names_the_scroller_and_measures_the_shell(): void
    {
        $f = self::footer();
        $this->assertStringContainsString('window.shellScroller = window.shellScroller || function', $f);
        $this->assertStringContainsString("root.style.setProperty('--shell-top'", $f);
        $this->assertStringContainsString("getComputedStyle(T).position === 'fixed'", $f, 'only a FIXED header can cover the content');
        $this->assertStringContainsString("root.style.setProperty('--shell-scroll-h'", $f);
        $this->assertLessThan(strpos($f, 'fit(); measure();'), strpos($f, 'function fit()'), 'measured after fit(): a flowing footer turns the area off');
    }
}
