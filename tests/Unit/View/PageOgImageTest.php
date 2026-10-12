<?php
// tests/Unit/View/PageOgImageTest.php
namespace Tests\Unit\View;

use Core\SEO\SeoManager;
use Tests\TestCase;

/**
 * A page's social-share image reaches its <head>.
 *
 * `pages.og_image_url` was written for months — Builder step 7 sells an "OG
 * card" (400 tokens) that stores one, and both seeders carry it into the
 * site — while nothing ever READ it: public/page.php called metaTags()
 * without an 'image' key, so no page on any built site showed a picture when
 * shared. The reading code looked complete; the column looked used. Only a
 * LinkedIn link card with no image showed the loop was open.
 *
 * So this file checks both ends: what pageImage() returns, and that the two
 * templates which render <head> still ask for it.
 */
final class PageOgImageTest extends TestCase
{
    public function test_an_absolute_page_image_is_used_as_is(): void
    {
        $this->assertSame(
            'https://cdn.example.com/og/card.png',
            SeoManager::pageImage(['og_image_url' => 'https://cdn.example.com/og/card.png'])
        );
    }

    public function test_a_relative_page_image_is_made_absolute(): void
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            $this->markTestSkipped('app.url is not set in this environment');
        }

        $this->assertSame($base . '/brand/og-card.png', SeoManager::pageImage(['og_image_url' => '/brand/og-card.png']));
        $this->assertSame($base . '/brand/og-card.png', SeoManager::pageImage(['og_image_url' => 'brand/og-card.png']));
    }

    public function test_metatags_emit_the_image_and_a_large_card(): void
    {
        $html = SeoManager::metaTags(['image' => 'https://example.com/a.png']);

        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/a.png">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function test_metatags_emit_no_image_tag_without_an_image(): void
    {
        $this->assertStringNotContainsString('og:image', SeoManager::metaTags(['title' => 'x', 'image' => null]));
    }

    /** The open loop itself: both <head> templates must ask for the image. */
    public function test_both_head_templates_pass_the_page_image(): void
    {
        $page   = file_get_contents(BASE_PATH . '/app/Views/public/page.php');
        $header = file_get_contents(BASE_PATH . '/app/Views/layout/header.php');

        $this->assertMatchesRegularExpression("/'image'\s*=>\s*\\\\Core\\\\SEO\\\\SeoManager::pageImage\(\\\$page\)/", $page);
        $this->assertMatchesRegularExpression("/'image'\s*=>\s*\\\$seoOgImage\s*\?\?\s*\\\\Core\\\\SEO\\\\SeoManager::pageImage\(\)/", $header);
    }
}
