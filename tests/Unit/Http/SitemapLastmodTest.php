<?php
// tests/Unit/Http/SitemapLastmodTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * Get found phase 4 (22 Nov 2026): every sitemap entry carries a "last changed" date, so search engines and IndexNow
 * can tell a changed page from an unchanged one. /faq was undated on every site; it now takes the newest public answer.
 */
final class SitemapLastmodTest extends TestCase
{
    public function test_faq_is_dated_by_its_newest_public_answer(): void
    {
        $src = (string) file_get_contents(BASE_PATH . '/app/Controllers/SitemapController.php');
        $this->assertStringContainsString('"SELECT MAX(updated_at) FROM faqs WHERE is_public = 1 AND is_active = 1"', $src);
        $this->assertStringContainsString("['/faq',      \$faqLastmod],", $src);
        $this->assertMatchesRegularExpression('/try \{\s*\$latest = .*?\} catch \(\\\\Throwable\)/s', $src,
            'a site without the faqs table still gets its sitemap');
    }

    public function test_the_builder_catalog_page_is_dated_by_its_catalog(): void
    {
        $path = BASE_PATH . '/modules/builder/module.php';
        if (!is_file($path)) { $this->markTestSkipped('Builder-only module'); }
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("['premium_modules.php', 'module_catalog.php', 'module_groups.php', 'module_prices.php']", $src);
        $this->assertStringContainsString("if (\$mtimes) { \$entry['lastmod'] = gmdate('Y-m-d', max(\$mtimes)); }", $src);
    }
}
