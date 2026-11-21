<?php
// tests/Unit/Modules/Seo/GetFoundPagesTest.php
namespace Tests\Unit\Modules\Seo;

use Modules\Seo\Controllers\SearchSettingsController;
use Modules\Seo\Services\SearchFiles;
use Modules\Seo\Services\SearchHead;
use Modules\Seo\Services\SearchPages;
use Modules\Seo\Services\SearchSeed;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;
use Tests\TestCase;

/**
 * Get found phase 3 (22 Nov 2026): a page can be hidden from search and can name its canonical address, and the
 * search settings chosen in the Builder wizard reach a hosted site without undoing what its owner changed there.
 */
final class GetFoundPagesTest extends TestCase
{
    private const PAGE = '<html><head><title>x</title></head><body>hi</body></html>';

    public static function setUpBeforeClass(): void
    {
        foreach (['Services/SearchSettings', 'Services/SiteProfile', 'Services/SearchPages', 'Services/SearchHead', 'Services/StructuredData',
                  'Services/SearchFiles', 'Services/SearchSeed', 'Controllers/SearchSettingsController'] as $f) {
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

    private static function site(): SiteProfile
    {
        return SiteProfile::build(['server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'], 'settings' => [],
            'app_url' => 'https://example.com', 'builder' => false]);
    }

    // ── Which page a path is ───────────────────────────────────────────────

    public function test_a_path_names_a_page_slug(): void
    {
        $this->assertSame('home', SearchPages::slugFor('/', 'home'));
        $this->assertNull(SearchPages::slugFor('/', ''), 'no guest home page set');
        $this->assertSame('pricing', SearchPages::slugFor('/pricing', 'home'));
        $this->assertSame('pricing', SearchPages::slugFor('/pricing/', 'home'));
        $this->assertNull(SearchPages::slugFor('/blog/post-1', 'home'), 'deeper paths are not pages');
        $this->assertNull(SearchPages::slugFor('/a b', 'home'));
    }

    public function test_a_canonical_override_is_a_path_here_or_a_full_address(): void
    {
        $this->assertSame('/original', SearchPages::cleanCanonical(' /original '));
        $this->assertSame('https://medium.com/@me/post', SearchPages::cleanCanonical('https://medium.com/@me/post'));
        $this->assertSame('http://example.org/x?a=1', SearchPages::cleanCanonical('http://example.org/x?a=1'));
        foreach (['javascript:alert(1)', '//evil.com/x', 'original', 'ftp://x.com/a', '/a"b', 'https://x.com/a b', str_repeat('a', 1001)] as $bad) {
            $this->assertSame('', SearchPages::cleanCanonical($bad), $bad);
        }
        $this->assertSame('https://example.com/original', SearchPages::canonicalHref('/original', 'https://example.com/'));
        $this->assertSame('https://medium.com/p', SearchPages::canonicalHref('https://medium.com/p', 'https://example.com'));
        $this->assertSame('', SearchPages::canonicalHref('', 'https://example.com'));
    }

    public function test_hidden_paths_are_the_hidden_pages_only(): void
    {
        SearchPages::using(['about' => ['hidden' => true, 'canonical' => ''], 'faq' => ['hidden' => false, 'canonical' => '/help']]);
        $this->assertSame(['/about'], SearchPages::hiddenPaths(), 'a page with only an override is not hidden');
    }

    // ── What a page sends ──────────────────────────────────────────────────

    public function test_a_hidden_page_is_noindexed_by_header_and_tag(): void
    {
        $hidden = ['hidden' => true, 'canonical' => ''];
        $this->assertContains('X-Robots-Tag: noindex, follow', SearchHead::headersFor(self::site(), 'GET', '/about', $hidden));
        $this->assertNotContains('X-Robots-Tag: noindex, follow', SearchHead::headersFor(self::site(), 'GET', '/about', null));

        $out = SearchHead::apply(self::PAGE, self::site(), '/about', '', $hidden);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $out);
        $withOwn = str_replace('</head>', '<meta name="robots" content="index, follow"></head>', self::PAGE);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', SearchHead::apply($withOwn, self::site(), '/about', '', $hidden),
            'added even beside the page\'s own robots tag');
        $this->assertStringNotContainsString('noindex', SearchHead::apply(self::PAGE, self::site(), '/about'));

        SearchSettings::using(['seo.noindex_account' => '0']);
        $this->assertContains('X-Robots-Tag: noindex, follow', SearchHead::headersFor(self::site(), 'GET', '/about', $hidden),
            'the owner\'s own choice does not depend on the account-pages switch');
    }

    public function test_a_canonical_override_replaces_the_printed_one(): void
    {
        $printed = str_replace('</head>', '<link rel="canonical" href="https://example.com/copy"></head>', self::PAGE);
        $out = SearchHead::apply($printed, self::site(), '/copy', '', ['hidden' => false, 'canonical' => 'https://medium.com/@me/post']);
        $this->assertStringContainsString('<link rel="canonical" href="https://medium.com/@me/post">', $out);
        $this->assertSame(1, substr_count($out, 'rel="canonical"'));

        $out = SearchHead::apply(self::PAGE, self::site(), '/copy', '', ['hidden' => false, 'canonical' => '/original']);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/original">', $out, 'a path goes on the main address');

        $out = SearchHead::apply(self::PAGE, self::site(), '/copy', '', ['hidden' => false, 'canonical' => 'javascript:alert(1)']);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/copy">', $out, 'a bad override is ignored');

        SearchSettings::using(['seo.canonical' => '0']);
        $this->assertStringNotContainsString('canonical', SearchHead::apply(self::PAGE, self::site(), '/copy', '', ['hidden' => false, 'canonical' => '/original']),
            'canonical switched off: nothing added');
    }

    // ── What machines read ─────────────────────────────────────────────────

    public function test_hidden_pages_leave_the_sitemap(): void
    {
        $xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url><url><loc>https://example.com/about</loc></url>'
             . '<url><loc>https://example.com/faq/</loc></url></urlset>';
        $out = SearchFiles::sitemap($xml, 'https://example.com', ['example.com'], true, false, ['/about', '/faq']);
        $this->assertStringContainsString('<loc>https://example.com/</loc>', $out);
        $this->assertStringNotContainsString('/about', $out);
        $this->assertStringNotContainsString('/faq', $out, 'a trailing slash is the same page');
        $this->assertStringNotContainsString('<loc>https://example.com/</loc>', SearchFiles::sitemap($xml, 'https://example.com', ['example.com'], true, false, ['/']));
    }

    // ── The owner's page table ─────────────────────────────────────────────

    public function test_the_pages_form_writes_only_the_listed_pages(): void
    {
        $out = SearchSettingsController::cleanPages([
            'page_ids'  => ['3', '5', 'x', '0'],
            'hidden'    => ['3' => '1', '9' => '1'],
            'canonical' => ['3' => '/original', '5' => 'javascript:x', '9' => '/z'],
        ]);
        $this->assertSame([3 => [1, '/original'], 5 => [0, '']], $out);
    }

    public function test_the_columns_come_from_the_modules_own_migration(): void
    {
        $src = (string) file_get_contents(BASE_PATH . '/modules/seo/migrations/2026_11_22_000000_pages_search_fields.php');
        $this->assertStringContainsString('ADD COLUMN search_hidden TINYINT(1) NOT NULL DEFAULT 0', $src);
        $this->assertStringContainsString('ADD COLUMN canonical_url VARCHAR(1000) NULL', $src);
        $this->assertStringContainsString("SHOW TABLES LIKE 'pages'", $src, 'a site without pages is left alone');
    }

    // ── Wizard settings meeting the owner's ────────────────────────────────

    public function test_the_wizard_sets_what_the_owner_has_not_changed(): void
    {
        // First build: the site has nothing yet.
        $r = SearchSeed::merge(['seo.ai.train' => 'yes', 'seo.business.type' => 'Restaurant'], [], []);
        $this->assertSame(['seo.ai.train' => 'yes', 'seo.business.type' => 'Restaurant'], $r['write']);
        $this->assertSame($r['write'], $r['seeded']);

        // Republish with a new wizard choice; the owner never touched it.
        $r = SearchSeed::merge(['seo.ai.train' => 'no'], ['seo.ai.train' => 'yes'], ['seo.ai.train' => 'yes']);
        $this->assertSame(['seo.ai.train' => 'no'], $r['write']);

        // The owner changed it on the site: theirs from now on, even when the wizard changes again.
        $r = SearchSeed::merge(['seo.ai.train' => 'no'], ['seo.ai.train' => 'yes'], ['seo.ai.train' => 'no']);
        $this->assertSame([], $r['write']);
        $this->assertSame(['seo.ai.train'], $r['kept']);
        $this->assertSame('no', $r['seeded']['seo.ai.train'], 'the remembered wizard value stays what the wizard last wrote');

        // A site that had the module before the wizard set this key: a default value is not an owner's choice…
        $r = SearchSeed::merge(['seo.business.type' => 'Store'], ['seo.business.type' => 'Organization'], []);
        $this->assertSame(['seo.business.type' => 'Store'], $r['write']);
        // …but a changed one is.
        $r = SearchSeed::merge(['seo.business.type' => 'Store'], ['seo.business.type' => 'Restaurant'], []);
        $this->assertSame([], $r['write']);
        // And an owner who set it BACK to the default after the wizard chose something else chose the default.
        $r = SearchSeed::merge(['seo.business.type' => 'Store'], ['seo.business.type' => 'Organization'], ['seo.business.type' => 'Restaurant']);
        $this->assertSame([], $r['write']);

        // Unchanged value: nothing to write, still remembered.
        $r = SearchSeed::merge(['seo.hide_site' => '0'], ['seo.hide_site' => '0'], ['seo.hide_site' => '0']);
        $this->assertSame([], $r['write']);
        $this->assertSame(['seo.hide_site' => '0'], $r['seeded']);

        // Only the wizard's own keys: the owner's switches are never touched from the wizard.
        $this->assertSame([], SearchSeed::merge(['seo.enabled' => '0', 'seo.indexnow' => '0'], [], [])['write']);
    }

    public function test_the_wizards_page_choices_follow_the_same_rule(): void
    {
        $shown = ['hidden' => 0, 'canonical' => ''];
        // A page the wizard hid, still at the default on the site (just inserted): hidden.
        $r = SearchSeed::mergePages(['about' => ['hidden' => 1, 'canonical' => '']], ['about' => $shown], []);
        $this->assertSame(['about' => ['hidden' => 1, 'canonical' => '']], $r['write']);
        $this->assertSame('1', $r['seeded']['page.about.hidden']);
        $this->assertSame('', $r['seeded']['page.about.canonical']);

        // The owner then un-hid it on the site; a republish with the wizard still saying hidden leaves it shown.
        $r = SearchSeed::mergePages(['about' => ['hidden' => 1, 'canonical' => '']], ['about' => $shown], ['page.about.hidden' => '1', 'page.about.canonical' => '']);
        $this->assertSame([], $r['write']);

        // The owner hid a page the wizard shows: stays hidden.
        $r = SearchSeed::mergePages(['faq' => $shown], ['faq' => ['hidden' => 1, 'canonical' => '']], ['page.faq.hidden' => '0', 'page.faq.canonical' => '']);
        $this->assertSame([], $r['write']);

        // A new canonical from the wizard, the owner never touched it: written — and the page the OWNER hid
        // (the wizard last wrote "shown") stays hidden beside it.
        $r = SearchSeed::mergePages(['copy' => ['hidden' => 0, 'canonical' => 'https://medium.com/p']], ['copy' => ['hidden' => 1, 'canonical' => '']],
            ['page.copy.hidden' => '0', 'page.copy.canonical' => '']);
        $this->assertSame(['copy' => ['hidden' => 1, 'canonical' => 'https://medium.com/p']], $r['write'], 'the owner\'s hidden flag survives beside it');

        // The wizard un-hides a page it hid before, and the owner never touched it: shown again.
        $r = SearchSeed::mergePages(['copy' => $shown], ['copy' => ['hidden' => 1, 'canonical' => '']], ['page.copy.hidden' => '1', 'page.copy.canonical' => '']);
        $this->assertSame(['copy' => $shown], $r['write']);

        // A page the site does not have: nothing to write.
        $this->assertSame([], SearchSeed::mergePages(['gone' => ['hidden' => 1, 'canonical' => '']], ['gone' => null], [])['write']);
        // A bad canonical never travels.
        $this->assertSame([], SearchSeed::mergePages(['x' => ['hidden' => 0, 'canonical' => 'javascript:1']], ['x' => $shown], [])['write']);
    }

    public function test_the_step8_card_is_cleaned_like_the_sites_own_page(): void
    {
        $out = SearchSeed::cleanCard([
            'seo_ai_train' => 'yes', 'seo_ai_input' => 'maybe', 'seo_business_type' => 'NoSuchType',
            'seo_main_host' => 'https://Shop.Example.com/path', 'seo_verify_google' => '<meta name="google-site-verification" content="abc123XYZ">',
            'seo_business_description' => "  We bake bread.\r\n ", 'seo_hide_site' => '1', 'seo_enabled' => '0',
        ]);
        $this->assertSame(SearchSeed::WIZARD_KEYS, array_keys($out));
        $this->assertSame('yes', $out['seo.ai.train']);
        $this->assertSame('yes', $out['seo.ai.input'], 'anything but no is yes');
        $this->assertSame('yes', $out['seo.ai.search'], 'absent = the default');
        $this->assertSame('Organization', $out['seo.business.type']);
        $this->assertSame('shop.example.com', $out['seo.main_host']);
        $this->assertSame('abc123XYZ', $out['seo.verify.google']);
        $this->assertSame('We bake bread.', $out['seo.business.description']);
        $this->assertSame('1', $out['seo.hide_site']);
        $this->assertArrayNotHasKey('seo.enabled', $out, 'the master switch is the owner\'s alone');
        $this->assertSame('', SearchSeed::cleanCard(['seo_main_host' => 'localhost'])['seo.main_host']);
    }

    public function test_the_remembered_wizard_values_decode_safely(): void
    {
        $this->assertSame(['seo.ai.train' => 'no'], SearchSeed::decode('{"seo.ai.train":"no","x":[1]}'));
        $this->assertSame([], SearchSeed::decode('nope'));
        $this->assertSame([], SearchSeed::decode(null));
    }
}
