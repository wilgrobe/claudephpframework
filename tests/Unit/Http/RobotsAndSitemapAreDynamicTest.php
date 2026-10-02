<?php
// tests/Unit/Http/RobotsAndSitemapAreDynamicTest.php
namespace Tests\Unit\Http;

use App\Controllers\SitemapController;
use Tests\TestCase;

/**
 * /robots.txt and /sitemap.xml must come from PHP, never from a file.
 *
 * Found on the first real domain a managed deploy put live (storiesden.com,
 * 2026-10-02): its sitemap listed https://example.com/. The framework's
 * /sitemap.xml route was right all along — it names the host the request
 * arrived on — but the build also wrote a static public/sitemap.xml with a
 * guessed host, and nginx/Apache serve a file before they ever reach PHP. The
 * framework's own static robots.txt pointed at http://claudephpframework/.
 * Nothing looked broken: both URLs returned 200 with valid content.
 *
 * So the guard is on the shape, not the text: no static copy in public/, the
 * routes registered, and the robots body naming whatever host it is given.
 */
final class RobotsAndSitemapAreDynamicTest extends TestCase
{
    public function test_public_dir_ships_no_static_copy_that_would_shadow_the_routes(): void
    {
        foreach (['robots.txt', 'sitemap.xml'] as $f) {
            $this->assertFileDoesNotExist(BASE_PATH . '/public/' . $f,
                "public/$f would be served instead of the /$f route, with whatever host it was written with");
        }
    }

    public function test_both_routes_are_registered(): void
    {
        $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');
        $this->assertStringContainsString("get('/sitemap.xml', 'SitemapController@index')", $routes);
        $this->assertStringContainsString("get('/robots.txt', 'SitemapController@robots')", $routes);
        $this->assertTrue(method_exists(SitemapController::class, 'robots'));
    }

    public function test_robots_names_the_host_it_is_served_on(): void
    {
        $txt = SitemapController::robotsTxt('https://storiesden.com');
        $this->assertStringContainsString("\nSitemap: https://storiesden.com/sitemap.xml\n", $txt);
        $this->assertStringNotContainsString('example.com', $txt);
        $this->assertStringNotContainsString('claudephpframework', $txt);
        $this->assertStringContainsString("Disallow: /admin/\n", $txt);
        // A trailing slash on the base must not double up.
        $this->assertStringContainsString('Sitemap: https://storiesden.com/sitemap.xml',
            SitemapController::robotsTxt('https://storiesden.com/'));
        $this->assertStringNotContainsString('//sitemap.xml', SitemapController::robotsTxt('https://storiesden.com/'));
    }

    public function test_no_base_means_no_sitemap_line_rather_than_a_wrong_one(): void
    {
        $this->assertStringNotContainsString('Sitemap:', SitemapController::robotsTxt(''));
    }
}
