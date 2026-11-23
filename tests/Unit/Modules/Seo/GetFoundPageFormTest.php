<?php
// tests/Unit/Modules/Seo/GetFoundPageFormTest.php
namespace Tests\Unit\Modules\Seo;

use Tests\TestCase;

/**
 * Get found (23 Nov 2026): a page's own edit form carries the two per-page search choices — "hide this page from
 * search engines and AI assistants" and the canonical address — that until now only the site's Search settings
 * Pages table and the Builder wizard could set. Walks the loop: form field → POST name → controller write → the
 * columns the seo module reads (pages.search_hidden / pages.canonical_url). Shared by the Builder and the framework,
 * whose PageControllers differ, so it reads the source rather than a fixed line.
 */
final class GetFoundPageFormTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(BASE_PATH . '/modules/pages/' . $rel);
    }

    public function test_the_form_posts_both_fields_only_when_the_seo_module_can_store_them(): void
    {
        $v = self::src('Views/admin/form.php');
        $this->assertStringContainsString('<?php if (class_exists(\Modules\Seo\Services\SearchPages::class) && \Modules\Seo\Services\SearchPages::hasColumns()): ?>', $v);
        $this->assertStringContainsString('<input type="checkbox" name="search_hidden" value="1" <?= !empty($page[\'search_hidden\']) ? \'checked\' : \'\' ?>>', $v);
        $this->assertStringContainsString('name="canonical_url" id="canonical_url"', $v);
        $this->assertStringContainsString('value="<?= e($page[\'canonical_url\'] ?? \'\') ?>"', $v, 'the stored value is shown, escaped');
        $this->assertStringContainsString("<?php if (!empty(\$errors['canonical_url'])): ?>", $v);
    }

    public function test_create_and_update_both_save_them_through_the_seo_modules_cleaner(): void
    {
        $c = self::src('Controllers/PageController.php');
        $this->assertSame(1, substr_count($c, '$insert += $this->searchFields($request);'), 'create saves them');
        $this->assertMatchesRegularExpression('/\$(update|extra) \+= \$this->searchFields\(\$request\);/', $c, 'update saves them');
        $this->assertSame(2, substr_count($c, 'if (($bad = $this->badCanonical($request)) !== null) {'), 'both refuse a bad canonical');
        $this->assertStringContainsString("|| !\$this->pagesHasColumn('search_hidden') || !\$this->pagesHasColumn('canonical_url')) {", $c, 'skipped before the seo migration');
        $this->assertStringContainsString("\$canon = \\Modules\\Seo\\Services\\SearchPages::cleanCanonical((string) \$request->post('canonical_url', ''));", $c);
        $this->assertStringContainsString("'search_hidden' => (int) \$request->post('search_hidden', 0) === 1 ? 1 : 0, 'canonical_url' => \$canon !== '' ? \$canon : null", $c,
            'unticked = shown in search again; an emptied canonical = the page\'s own address');
    }

    public function test_the_columns_are_the_ones_the_seo_module_reads(): void
    {
        $p = (string) file_get_contents(BASE_PATH . '/modules/seo/Services/SearchPages.php');
        $this->assertStringContainsString("column_name IN ('search_hidden','canonical_url')", $p);
        $this->assertStringContainsString('search_hidden', $p);
        $this->assertStringContainsString('canonical_url', $p);
    }
}
