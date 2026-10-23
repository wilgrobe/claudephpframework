<?php
// tests/Unit/Views/AuthPageStylesTest.php
namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * 2026-10-23: the sign-in fields had no border on a bare framework install. Two causes, both checked here:
 *  1. The standalone auth pages get no ThemeService output, so --border-strong / --color-gray-300 / --bg-panel were
 *     undefined, `border: 1px solid var(--border-strong, var(--color-gray-300))` was invalid at computed-value time,
 *     and the border reverted to `0 none`. app.css now carries the Builder's zero-specificity neutral FLOOR.
 *  2. login/register/reset_password linked their CSS bare, so a stylesheet fix never reached a browser that had
 *     cached the old copy. They now use asset() (?v=<mtime>), like the 2FA pages always did.
 */
final class AuthPageStylesTest extends TestCase
{
    public function test_app_css_defines_the_neutral_floor_the_input_borders_need(): void
    {
        $css = str_replace("\r\n", "\n", (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css'));
        $at = strpos($css, ':where(:root) {');
        $this->assertNotFalse($at, 'the zero-specificity neutral floor is gone — undefined vars make every input borderless');
        $block = substr($css, $at, (int) strpos($css, "\n}", $at) - $at);
        foreach (['--color-gray-300', '--border-strong', '--bg-panel', '--text-default'] as $var) {
            $this->assertMatchesRegularExpression('/' . preg_quote($var, '/') . ':\s*#[0-9a-f]{6};/i', $block, "$var needs a literal in the floor");
        }
    }

    public function test_no_view_links_a_stylesheet_without_asset(): void
    {
        $bare = [];
        foreach (glob(BASE_PATH . '/app/Views/{,*/}*.php', GLOB_BRACE) ?: [] as $f) {
            if (preg_match_all('~<link[^>]+href="/assets/css/[^"]+"~', (string) file_get_contents($f), $m)) {
                $bare[] = substr(str_replace('\\', '/', $f), strlen(str_replace('\\', '/', BASE_PATH)) + 1) . ': ' . implode(', ', $m[0]);
            }
        }
        $this->assertSame([], $bare, "Use asset() so a CSS change reaches browsers (and Cloudflare) that cached the old copy:\n" . implode("\n", $bare));
    }
}
