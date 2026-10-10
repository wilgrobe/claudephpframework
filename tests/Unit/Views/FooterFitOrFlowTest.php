<?php
// tests/Unit/Views/FooterFitOrFlowTest.php
namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * 2026-10-09: the site footer is a pinned one-line bar with a fixed height and overflow:hidden.
 *  - On phones its wrapped links grew to ~200px and covered a quarter of the screen on every page.
 *  - Above 640px nothing wrapped — it was clipped instead: on the Builder at 1280px the copyright line,
 *    "Do Not Sell or Share My Personal Information" and "Report an issue" were invisible.
 * Phones now put the footer in normal flow; wider screens keep the pinned bar only when its content fits, and
 * otherwise a small script switches it into flow. These checks fail if either half is dropped by a merge.
 */
final class FooterFitOrFlowTest extends TestCase
{
    private static function src(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(BASE_PATH . '/app/Views/partials/site_footer.php'));
    }

    public function test_phones_put_the_footer_in_the_normal_flow(): void
    {
        $s = self::src();
        $at = strpos($s, '@media (max-width: 640px)');
        $this->assertNotFalse($at);
        $block = substr($s, $at, (int) strpos($s, "\n}\n", $at) - $at);
        $this->assertMatchesRegularExpression('/\.site-footer\s*\{ position: static;/', $block, 'phones must not pin the footer over the content');
        $this->assertMatchesRegularExpression('/body\s*\{ padding-bottom: 0; \}/', $block, 'no body reservation once the footer is in flow');
    }

    public function test_a_bar_that_does_not_fit_switches_to_the_flow_instead_of_hiding_links(): void
    {
        $s = self::src();
        $this->assertStringContainsString('.site-footer.site-footer--flow { position: static; height: auto; overflow: visible;', $s);
        $this->assertStringContainsString('body.has-flow-footer { padding-bottom: 0; }', $s);
        $this->assertStringContainsString('f.scrollWidth > f.clientWidth + 1 || f.scrollHeight > f.clientHeight + 1', $s);
        $this->assertStringContainsString("f.classList.add('site-footer--flow')", $s);
        $this->assertStringContainsString("window.addEventListener('resize'", $s);
    }
}
