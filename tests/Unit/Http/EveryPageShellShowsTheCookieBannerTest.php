<?php
// tests/Unit/Http/EveryPageShellShowsTheCookieBannerTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * Every view that writes its own </body> includes the cookie banner.
 *
 * The banner lived in layout/footer.php and public/page.php, but the auth
 * pages (login, register, password reset, 2FA), signup / claim and the
 * unsubscribe pages are full HTML documents that never include the footer —
 * so a site whose front door is /login showed no banner until after
 * sign-in (found on a taskflow kit, 2026-10-02). Email templates are excepted
 * (a banner has no place in an email), and so is an iframe embed that says so
 * with a `cookie-banner: none — <reason>` comment.
 */
final class EveryPageShellShowsTheCookieBannerTest extends TestCase
{
    private const PARTIAL = "/app/Views/partials/_cookie_banner.php";

    public function test_every_standalone_page_includes_the_banner_partial(): void
    {
        $views = array_merge(
            glob(BASE_PATH . '/app/Views/*/*.php') ?: [],
            glob(BASE_PATH . '/app/Views/*/*/*.php') ?: [],
            glob(BASE_PATH . '/modules/*/Views/*.php') ?: [],
            glob(BASE_PATH . '/modules/*/Views/*/*.php') ?: [],
        );
        $shells = 0;
        $missing = [];
        foreach ($views as $f) {
            $rel = substr(str_replace('\\', '/', $f), strlen(str_replace('\\', '/', BASE_PATH)));
            if (str_starts_with($rel, '/app/Views/emails/')) { continue; }
            $src = (string) file_get_contents($f);
            if (!str_contains($src, '</body>')) { continue; }
            $shells++;
            // A document rendered INSIDE somebody else's page (an iframe embed)
            // must not draw this site's banner over theirs. It opts out on
            // purpose, with its reason: `cookie-banner: none — <why>`.
            if (preg_match('/cookie-banner:\s*none\s*[—-]+\s*\S/u', $src)) { continue; }
            if (!str_contains($src, self::PARTIAL)) { $missing[] = $rel; }
        }
        $this->assertGreaterThan(5, $shells, 'Found almost no page shells — the glob is wrong, not the views.');
        $this->assertSame([], $missing, 'These pages render a whole document without the cookie banner.');
    }

    public function test_the_partial_is_the_gated_banner_include(): void
    {
        $src = (string) file_get_contents(BASE_PATH . self::PARTIAL);
        $this->assertStringContainsString("/modules/cookieconsent/Views/banner.php", $src);
        $this->assertStringContainsString("featureEnabled('cookieconsent', 'banner-ui')", $src);
        $this->assertStringContainsString('file_exists', $src, 'A site without the cookieconsent module must not fatal.');
    }
}
