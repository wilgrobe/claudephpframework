<?php
// tests/Unit/Modules/Seo/GetFoundMarkdownTest.php
namespace Tests\Unit\Modules\Seo;

use Modules\Seo\Services\MarkdownPage;
use Modules\Seo\Services\SearchFiles;
use Modules\Seo\Services\SearchHead;
use Modules\Seo\Services\SearchPages;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;
use Tests\TestCase;

/**
 * Get found, 22 Nov 2026 — two things StoriesDen built for itself (publish-hub, 13 Nov) moved into the shared module
 * so every Builder site has them: pages as Markdown for AI assistants, and modules adding their own sections to
 * /llms.txt (StoriesDen's comparisons and storefront guides).
 */
final class GetFoundMarkdownTest extends TestCase
{
    private const PAGE = '<html><head><title>Plans</title><meta name="description" content="What it costs."></head>'
        . '<body><nav><a href="/x">Menu</a></nav><main id="main-content"><h1>Plans</h1><p>Free <b>for</b> authors. '
        . '<a href="/pricing">See prices</a></p><ul><li>One</li><li>Two</li></ul><script>var x=1;</script></main></body></html>';

    public static function setUpBeforeClass(): void
    {
        foreach (['Services/SearchSettings', 'Services/SiteProfile', 'Services/SearchPages', 'Services/SearchHead', 'Services/StructuredData',
                  'Services/SearchFiles', 'Services/MarkdownPage'] as $f) {
            $class = 'Modules\\Seo\\' . str_replace('/', '\\', $f);
            if (!class_exists($class, false)) { require_once BASE_PATH . '/modules/seo/' . $f . '.php'; }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        SearchSettings::using([]);
    }

    protected function tearDown(): void
    {
        SearchSettings::using(null);
        SearchPages::using(null);
        parent::tearDown();
    }

    private static function site(array $settings = []): SiteProfile
    {
        return SiteProfile::build(['server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'], 'settings' => $settings,
            'app_url' => 'https://example.com', 'builder' => false]);
    }

    // ── Who gets Markdown ───────────────────────────────────────────────────

    public function test_only_a_request_preferring_markdown_on_a_public_page_gets_it(): void
    {
        $this->assertTrue(MarkdownPage::wantsMarkdown('GET', 'text/markdown', '/pricing'));
        $this->assertTrue(MarkdownPage::wantsMarkdown('HEAD', 'text/markdown, text/html;q=0.5', '/'));
        $this->assertFalse(MarkdownPage::wantsMarkdown('GET', 'text/html,application/xhtml+xml,*/*;q=0.8', '/pricing'), 'a browser');
        $this->assertFalse(MarkdownPage::wantsMarkdown('GET', 'text/html, text/markdown;q=0.5', '/pricing'), 'HTML preferred');
        $this->assertFalse(MarkdownPage::wantsMarkdown('POST', 'text/markdown', '/pricing'));
        $this->assertFalse(MarkdownPage::wantsMarkdown('GET', 'text/markdown', '/admin/users'), 'signed-in surface');
        $this->assertFalse(MarkdownPage::wantsMarkdown('GET', 'text/markdown', '/two-factor'), 'a page kept out of search');
        $this->assertFalse(MarkdownPage::wantsMarkdown('GET', 'text/markdown', '/sitemap.xml'), 'a file');
    }

    public function test_the_switch_and_hiding_decide_too(): void
    {
        $this->assertTrue(SearchHead::markdownWanted(self::site(), 'GET', 'text/markdown', '/pricing'));
        SearchSettings::using(['seo.markdown' => '0']);
        $this->assertFalse(SearchHead::markdownWanted(self::site(), 'GET', 'text/markdown', '/pricing'), 'switched off');
        SearchSettings::using([]);
        $this->assertFalse(SearchHead::markdownWanted(self::site(['hide_site' => '1']), 'GET', 'text/markdown', '/pricing'), 'hidden site');
        $this->assertFalse(SearchHead::markdownWanted(self::site(), 'GET', 'text/markdown', '/pricing', ['hidden' => true, 'canonical' => '']), 'hidden page');
        $this->assertSame('1', SearchSettings::defaults()['seo.markdown'], 'on by default, like every switch');
    }

    public function test_a_page_that_can_answer_in_markdown_says_vary_accept(): void
    {
        $this->assertContains('Vary: Accept', SearchHead::headersFor(self::site(), 'GET', '/pricing'));
        $this->assertNotContains('Vary: Accept', SearchHead::headersFor(self::site(), 'GET', '/login'));
        SearchSettings::using(['seo.markdown' => '0']);
        $this->assertNotContains('Vary: Accept', SearchHead::headersFor(self::site(), 'GET', '/pricing'));
    }

    // ── What it gets ────────────────────────────────────────────────────────

    public function test_the_page_becomes_markdown_with_its_address_on_top(): void
    {
        $html = SearchHead::apply(self::PAGE, self::site(), '/plans');
        $md = MarkdownPage::toMarkdown($html, 'https://example.com');
        $this->assertStringStartsWith("---\ntitle: \"Plans\"\ndescription: \"What it costs.\"\nurl: \"https://example.com/plans\"\n---\n\n", $md,
            'the canonical apply() added is the front matter\'s url');
        $this->assertStringContainsString('# Plans', $md);
        $this->assertStringContainsString('Free **for** authors. [See prices](https://example.com/pricing)', $md);
        $this->assertStringContainsString("- One\n- Two", $md);
        $this->assertStringNotContainsString('Menu', $md, 'navigation is not content');
        $this->assertStringNotContainsString('var x', $md);
        $this->assertGreaterThan(0, MarkdownPage::tokens($md));
    }

    public function test_the_buffer_converts_after_the_head_is_finished(): void
    {
        $src = (string) file_get_contents(BASE_PATH . '/modules/seo/Services/SearchHead.php');
        $apply = strpos($src, '$html = self::apply($html, $p, $path, self::extras($p, $path), $page);');
        $conv  = strpos($src, 'MarkdownPage::toMarkdown($html, $p->base())');
        $this->assertNotFalse($apply);
        $this->assertNotFalse($conv);
        $this->assertLessThan($conv, $apply);
        $this->assertStringContainsString("header('Content-Type: text/markdown; charset=UTF-8');", $src);
        $this->assertStringContainsString("\$md = self::markdownWanted(\$p, \$method, (string) (\$server['HTTP_ACCEPT'] ?? ''), \$path, \$page);", $src);
    }

    // ── llms.txt ────────────────────────────────────────────────────────────

    public function test_modules_add_their_own_sections_to_llms_txt(): void
    {
        $sections = ['Comparisons' => [
            ['title' => 'Us vs Them', 'url' => '/compare/them', 'about' => 'Which is cheaper?  Ours.'],
            ['title' => 'Full address', 'url' => 'https://example.com/compare/two'],
            ['title' => 'Elsewhere', 'url' => 'https://evil.example/x'],
            ['title' => 'Sign in', 'url' => '/login'],
            ['title' => '', 'url' => '/compare/untitled'],
            ['title' => 'Odd', 'url' => '/a b'],
        ], 'Empty' => [['title' => 'Off site', 'url' => '//evil.example/']]];
        $txt = SearchFiles::llms('https://example.com', 'Site', '', '', [], '', $sections, true);
        $this->assertStringContainsString("## Comparisons\n- [Us vs Them](https://example.com/compare/them): Which is cheaper? Ours.\n- [Full address](https://example.com/compare/two)\n", $txt);
        $this->assertStringNotContainsString('evil.example', $txt, 'a module only describes this site');
        $this->assertStringNotContainsString('/login', $txt);
        $this->assertStringNotContainsString('untitled', $txt);
        $this->assertStringNotContainsString('/a b', $txt);
        $this->assertStringNotContainsString('## Empty', $txt, 'a section with nothing left is dropped');
        $this->assertStringContainsString('also available as Markdown', $txt);
        $this->assertStringNotContainsString('also available as Markdown', SearchFiles::llms('https://example.com', 'Site', '', '', [], '', $sections, false));
    }

    public function test_the_controller_asks_every_module_and_passes_the_switch(): void
    {
        $src = (string) file_get_contents(BASE_PATH . '/modules/seo/Controllers/SearchController.php');
        $this->assertStringContainsString('self::moduleSections($p->base()), SearchSettings::on(\'seo.markdown\'));', $src);
        $this->assertStringContainsString("if (!method_exists(\$provider, 'llmsSections')) { continue; }", $src);
        $this->assertStringContainsString('foreach ((array) $provider->llmsSections($base) as $heading => $items) {', $src);
    }
}
