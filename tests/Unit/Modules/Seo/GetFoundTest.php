<?php
// tests/Unit/Modules/Seo/GetFoundTest.php
namespace Tests\Unit\Modules\Seo;

use Modules\Seo\Controllers\SearchSettingsController;
use Modules\Seo\Services\IndexNow;
use Modules\Seo\Services\SearchFiles;
use Modules\Seo\Services\SearchHead;
use Modules\Seo\Services\SearchSettings;
use Modules\Seo\Services\SiteProfile;
use Modules\Seo\Services\StructuredData;
use Tests\TestCase;

/**
 * "Get found" (modules/seo) — the pure parts: who the site is (main host, hidden or not), where a request is
 * redirected, what a page gains in its <head>, and the files machines read. Every switch has a test that it turns
 * its piece OFF, because "free and on, each piece can be turned off" (Will, 17 Nov 2026) is the contract.
 */
final class GetFoundTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Services/SearchSettings', 'Services/SiteProfile', 'Services/SearchHead', 'Services/StructuredData',
                  'Services/SearchFiles', 'Services/IndexNow', 'Controllers/SearchSettingsController'] as $f) {
            $class = 'Modules\\Seo\\' . str_replace('/', '\\', $f);
            if (!class_exists($class, false)) { require_once BASE_PATH . '/modules/seo/' . $f . '.php'; }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        SearchSettings::using([]);   // every default: all on
    }

    protected function tearDown(): void
    {
        SearchSettings::using(null);
        IndexNow::useSender(null);
        IndexNow::useStore(null);
        parent::tearDown();
    }

    private static function kit(string $host = 'example.com', bool $https = true, array $settings = []): SiteProfile
    {
        return SiteProfile::build([
            'server'   => ['HTTP_HOST' => $host] + ($https ? ['HTTPS' => 'on'] : []),
            'settings' => $settings,
            'app_url'  => 'https://example.com',
            'builder'  => false,
        ]);
    }

    private static function tenant(array $tenant, array $domains, string $host = 'shop.claudephpbuilder.com', bool $https = true): SiteProfile
    {
        return SiteProfile::build([
            'server'     => ['HTTP_HOST' => $host] + ($https ? ['HTTP_X_FORWARDED_PROTO' => 'https'] : []),
            'settings'   => [],
            'app_url'    => 'https://www.claudephpbuilder.com',
            'production' => true,
            'builder'    => true,
            'tenant'     => $tenant + ['tier' => 'production', 'demo_mode' => 0, 'demo_search_enabled' => 0],
            'domains'    => $domains,
        ]);
    }

    // ── Who the site is ─────────────────────────────────────────────────────

    public function test_a_kit_is_known_by_app_url_and_its_www_twin_redirects_there(): void
    {
        $p = self::kit('www.example.com');
        $this->assertSame('https://example.com', $p->base());
        $this->assertSame(['www.example.com'], $p->redirectHosts);
        $this->assertFalse($p->isHidden());
        $this->assertFalse($p->apex);
    }

    public function test_a_tenant_with_a_verified_custom_domain_is_known_by_it(): void
    {
        $sub = ['hostname' => 'shop.claudephpbuilder.com', 'kind' => 'subdomain', 'verified_at' => null];
        $custom = ['hostname' => 'shop.com', 'kind' => 'custom', 'verified_at' => '2026-11-01 00:00:00'];
        $p = self::tenant([], [$sub, $custom]);
        $this->assertSame('https://shop.com', $p->base());
        $this->assertContains('shop.claudephpbuilder.com', $p->redirectHosts);
        $this->assertContains('www.shop.com', $p->redirectHosts);

        // Not verified yet: the subdomain stays the main address.
        $custom['verified_at'] = null;
        $this->assertSame('https://shop.claudephpbuilder.com', self::tenant([], [$sub, $custom])->base());
    }

    public function test_demos_and_previews_are_hidden_unless_a_demo_is_switched_on(): void
    {
        $d = [['hostname' => 'shop.claudephpbuilder.com', 'kind' => 'subdomain', 'verified_at' => null]];
        $this->assertSame('demo', self::tenant(['demo_mode' => 1], $d)->hidden);
        $this->assertSame('', self::tenant(['demo_mode' => 1, 'demo_search_enabled' => 1], $d)->hidden);
        $this->assertSame('preview', self::tenant(['tier' => 'proofing'], $d)->hidden);
        $this->assertSame('', self::tenant(['tier' => 'production'], $d)->hidden);
        $this->assertSame('owner', self::kit('example.com', true, ['hide_site' => '1'])->hidden);
    }

    /** Phase 5 (21 Nov 2026): the Builder's own site gets the same treatment, on APP_URL's address. */
    public function test_the_builder_site_uses_its_app_url_address(): void
    {
        $apex = static fn (string $host) => SiteProfile::build([
            'server' => ['HTTP_HOST' => $host, 'HTTP_CF_VISITOR' => '{"scheme":"https"}'],
            'app_url' => 'https://www.claudephpbuilder.com', 'builder' => true, 'tenant' => null, 'production' => true,
        ]);
        $p = $apex('www.claudephpbuilder.com');
        $this->assertTrue($p->apex);
        $this->assertSame('www.claudephpbuilder.com', $p->mainHost);
        $this->assertSame('https://www.claudephpbuilder.com', $p->base());
        $this->assertSame('', $p->hidden);
        $this->assertSame('https://www.claudephpbuilder.com/pricing', SearchHead::redirectTarget($apex('claudephpbuilder.com'), 'GET', '/pricing', true, true),
            'the bare domain sends to www');
        $this->assertNull(SearchHead::redirectTarget($p, 'GET', '/pricing', true, true));
    }

    public function test_the_builder_sites_app_pages_stay_out_of_search_and_a_kits_do_not(): void
    {
        foreach (['/projects', '/projects/36/step7', '/studio/x', '/signup', '/notifications', '/downloads/9'] as $path) {
            $this->assertTrue(SearchHead::noindex($path, true), "$path on the Builder's site");
            $this->assertFalse(SearchHead::noindex($path), "$path on a generated site may be a public page");
        }
        foreach (['/', '/pricing', '/premium-modules', '/how-it-works', '/demos'] as $path) {
            $this->assertFalse(SearchHead::noindex($path, true), "$path is a public page of the Builder's site");
        }
        $this->assertTrue(SearchHead::noindex('/admin/health', true), 'the shared list still applies');

        // ...and what a Builder page actually sends: the header and the tag.
        $apex = SiteProfile::build(['server' => ['HTTP_HOST' => 'www.claudephpbuilder.com', 'HTTPS' => 'on'],
            'app_url' => 'https://www.claudephpbuilder.com', 'builder' => true, 'tenant' => null]);
        $this->assertContains('X-Robots-Tag: noindex, follow', SearchHead::headersFor($apex, 'GET', '/projects/36/step7'));
        $this->assertNotContains('X-Robots-Tag: noindex, follow', SearchHead::headersFor(self::kit(), 'GET', '/projects/36/step7'));
        $this->assertContains('Link: ' . SearchHead::LINK, SearchHead::headersFor($apex, 'GET', '/'), 'the Builder home links its llms.txt');
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', SearchHead::apply(self::PAGE, $apex, '/projects/36'));
        $this->assertStringNotContainsString('name="robots"', SearchHead::apply(self::PAGE, self::kit(), '/projects/36'));
    }

    /** The four places that used to skip the Builder's site are gone (a revert brings back the old, unfound site). */
    public function test_nothing_skips_the_builder_site_any_more(): void
    {
        $d = BASE_PATH . '/modules/seo/';
        $this->assertStringNotContainsString('Tenant::current() === null', (string) file_get_contents($d . 'routes.php'));
        $this->assertStringNotContainsString('Tenant::current() === null', (string) file_get_contents($d . 'module.php'));
        $this->assertStringNotContainsString('if ($p->apex) { return; }', (string) file_get_contents($d . 'Services/SearchHead.php'));
    }

    // ── Redirects ───────────────────────────────────────────────────────────

    public function test_www_gets_a_301_to_the_main_host_keeping_path_and_query(): void
    {
        $p = self::kit('www.example.com');
        $this->assertSame('https://example.com/pricing?a=1', SearchHead::redirectTarget($p, 'GET', '/pricing?a=1', true, true));
        $this->assertNull(SearchHead::redirectTarget($p, 'POST', '/pricing', true, true), 'a form post is never bounced');
        $this->assertNull(SearchHead::redirectTarget($p, 'GET', '/.well-known/acme-challenge/x', true, true), 'certificate challenges answer where they are asked');
        $this->assertNull(SearchHead::redirectTarget($p, 'GET', '/pricing', false, true), 'switch off = no redirect');
        $this->assertNull(SearchHead::redirectTarget(self::kit('example.com'), 'GET', '/pricing', true, true), 'already on the main host');
    }

    public function test_the_builder_subdomain_moves_public_pages_only(): void
    {
        $p = self::tenant([], [
            ['hostname' => 'shop.claudephpbuilder.com', 'kind' => 'subdomain', 'verified_at' => null],
            ['hostname' => 'shop.com', 'kind' => 'custom', 'verified_at' => '2026-11-01'],
        ]);
        $this->assertSame('https://shop.com/about', SearchHead::redirectTarget($p, 'GET', '/about', true, true));
        $this->assertNull(SearchHead::redirectTarget($p, 'GET', '/admin/settings', true, true), 'a signed-in admin is not bounced between cookie jars');
        $this->assertNull(SearchHead::redirectTarget($p, 'GET', '/claim/abc', true, true));
    }

    public function test_http_goes_to_https_only_when_the_site_is_known_to_have_it(): void
    {
        $this->assertSame('https://example.com/x', SearchHead::redirectTarget(self::kit('example.com', false), 'GET', '/x', true, true));
        $this->assertNull(SearchHead::redirectTarget(self::kit('example.com', false), 'GET', '/x', true, false), 'switch off');
        $plain = SiteProfile::build(['server' => ['HTTP_HOST' => 'example.com'], 'app_url' => 'http://example.com', 'builder' => false]);
        $this->assertNull(SearchHead::redirectTarget($plain, 'GET', '/x', true, true), 'APP_URL says http: no https to send to');
        $local = SiteProfile::build(['server' => ['HTTP_HOST' => 'www.shop.test'], 'app_url' => 'https://shop.test', 'builder' => false]);
        $this->assertNull(SearchHead::redirectTarget($local, 'GET', '/x', true, true), 'dev hosts never redirect');
    }

    // ── The page head ───────────────────────────────────────────────────────

    private const PAGE = '<html><head><title>x</title></head><body>hi</body></html>';

    public function test_a_page_without_a_canonical_gets_one_without_the_query_string(): void
    {
        $out = SearchHead::apply(self::PAGE, self::kit(), '/pricing/');
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/pricing">', $out);
        $this->assertStringNotContainsString('name="robots"', $out);
    }

    public function test_a_canonical_naming_this_site_under_the_wrong_host_is_corrected_and_a_foreign_one_kept(): void
    {
        $p = self::tenant([], [['hostname' => 'shop.claudephpbuilder.com', 'kind' => 'subdomain', 'verified_at' => null]]);
        // page.php printed APP_URL (the Builder's apex) as every hosted page's canonical.
        $apex = '<html><head><link rel="canonical" href="https://www.claudephpbuilder.com/about"></head></html>';
        $this->assertStringContainsString('href="https://shop.claudephpbuilder.com/about"', SearchHead::apply($apex, $p, '/about'));
        $foreign = '<html><head><link rel="canonical" href="https://original.org/story"></head></html>';
        $this->assertSame($foreign, SearchHead::apply($foreign, $p, '/story'));
    }

    public function test_account_pages_get_noindex_follow_and_a_hidden_site_noindex_everywhere(): void
    {
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', SearchHead::apply(self::PAGE, self::kit(), '/login'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', SearchHead::apply(self::PAGE, self::kit(), '/password/forgot'));
        $this->assertStringNotContainsString('name="robots"', SearchHead::apply(self::PAGE, self::kit(), '/loginhelp'));
        $own = '<html><head><meta name="robots" content="index"></head></html>';
        $this->assertSame(1, substr_count(SearchHead::apply($own, self::kit(), '/login'), 'name="robots"'), 'a page\'s own robots tag is kept');

        $hidden = self::kit('example.com', true, ['hide_site' => '1']);
        $this->assertStringContainsString('content="noindex, nofollow"', SearchHead::apply($own, $hidden, '/'));
        $this->assertContains('X-Robots-Tag: noindex, nofollow', SearchHead::headersFor($hidden, 'GET', '/'));
        $this->assertNotContains('Link: ' . SearchHead::LINK, SearchHead::headersFor($hidden, 'GET', '/'));
    }

    public function test_each_switch_turns_its_piece_off(): void
    {
        SearchSettings::using(['seo.canonical' => '0']);
        $this->assertStringNotContainsString('canonical', SearchHead::apply(self::PAGE, self::kit(), '/pricing'));
        SearchSettings::using(['seo.noindex_account' => '0']);
        $this->assertStringNotContainsString('name="robots"', SearchHead::apply(self::PAGE, self::kit(), '/login'));
        $this->assertSame([], array_filter(SearchHead::headersFor(self::kit(), 'GET', '/login'), fn ($h) => str_starts_with($h, 'X-Robots')));
        SearchSettings::using(['seo.llms' => '0']);
        $this->assertSame([], array_values(array_filter(SearchHead::headersFor(self::kit(), 'GET', '/'), fn ($h) => str_starts_with($h, 'Link:'))));
        SearchSettings::using(['seo.enabled' => '0']);
        $this->assertSame(self::PAGE, SearchHead::apply(self::PAGE, self::kit(), '/login'), 'the master switch turns everything off');
        $this->assertFalse(SearchSettings::on('seo.indexnow'));
    }

    public function test_the_homepage_link_header_points_at_llms_txt(): void
    {
        $this->assertContains('Link: </llms.txt>; rel="describedby"; type="text/plain"', SearchHead::headersFor(self::kit(), 'GET', '/'));
        $this->assertSame([], array_values(array_filter(SearchHead::headersFor(self::kit(), 'GET', '/about'), fn ($h) => str_starts_with($h, 'Link:'))),
            'only the homepage (Vary: Accept is the Markdown switch: GetFoundMarkdownTest)');
    }

    public function test_verification_codes_are_kept_from_a_pasted_tag_and_junk_is_dropped(): void
    {
        $this->assertSame('abc_DEF-123', SearchHead::verificationValue('<meta name="google-site-verification" content="abc_DEF-123" />'));
        $this->assertSame('abc_DEF-123', SearchHead::verificationValue('  abc_DEF-123 '));
        $this->assertSame('', SearchHead::verificationValue('"><script>alert(1)</script>'));
    }

    // ── Structured data ─────────────────────────────────────────────────────

    public function test_structured_data_follows_the_business_type_and_states_only_what_was_given(): void
    {
        $g = StructuredData::graph('https://pizza.com', ['type' => 'Restaurant', 'name' => 'Luigi', 'city' => 'Tucson', 'phone' => '+1 520 555 0100']);
        $this->assertSame('Restaurant', $g[0]['@type']);
        $this->assertSame(['@type' => 'PostalAddress', 'addressLocality' => 'Tucson'], $g[0]['address']);
        $this->assertSame('+1 520 555 0100', $g[0]['telephone']);
        $this->assertArrayNotHasKey('email', $g[0]);
        $this->assertSame('WebSite', $g[1]['@type']);

        $app = StructuredData::graph('https://app.io', ['type' => 'SoftwareApplication', 'name' => 'App', 'description' => 'Does things']);
        $this->assertSame(['Organization', 'WebSite', 'SoftwareApplication'], array_column($app, '@type'));
        $this->assertSame('Organization', StructuredData::graph('https://x.io', ['type' => 'Bogus', 'name' => 'X'])[0]['@type']);
        $this->assertSame([], StructuredData::graph('https://x.io', ['name' => '']), 'no name, no claim');

        $script = StructuredData::script(StructuredData::graph('https://x.io', ['name' => 'A </script><b>']));
        $this->assertSame(1, substr_count($script, '</script>'), 'copy cannot close the script tag');
    }

    // ── robots.txt, sitemap.xml, llms.txt ───────────────────────────────────

    private const ROBOTS = "User-agent: *\nDisallow: /admin/\n\nAllow: /\n\nSitemap: https://example.com/sitemap.xml\n";

    public function test_robots_carries_the_ai_policy_inside_the_star_block(): void
    {
        $out = SearchFiles::robots(self::ROBOTS, 'search=yes, ai-input=yes, ai-train=no', true, false);
        $this->assertStringStartsWith("User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nDisallow: /session/", $out);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $out);
        $this->assertSame(self::ROBOTS, SearchFiles::robots(self::ROBOTS, 'search=yes', false, false), 'extras off = the framework file');

        $hidden = SearchFiles::robots(self::ROBOTS, 'search=yes, ai-input=yes, ai-train=no', false, true);
        $this->assertStringContainsString('Content-Signal: search=no, ai-input=no, ai-train=no', $hidden);
        $this->assertStringNotContainsString('Sitemap:', $hidden);
        $this->assertStringContainsString('Allow: /', $hidden, 'engines must still read each page\'s noindex');
    }

    public function test_the_default_ai_policy_is_search_and_answers_yes_training_no(): void
    {
        $this->assertSame('search=yes, ai-input=yes, ai-train=no', SearchSettings::contentSignal());
        SearchSettings::using(['seo.ai.train' => 'yes', 'seo.ai.input' => 'no']);
        $this->assertSame('search=yes, ai-input=no, ai-train=yes', SearchSettings::contentSignal());
    }

    public function test_the_sitemap_is_rebased_on_the_main_host_without_account_pages(): void
    {
        $xml = '<urlset><url><loc>https://www.claudephpbuilder.com/</loc><lastmod>2026-11-01</lastmod></url>'
             . '<url><loc>https://www.claudephpbuilder.com/login</loc></url>'
             . '<url><loc>https://shop.com/about</loc></url><url><loc>https://shop.com/about</loc></url>'
             . '<url><loc>https://other.org/x</loc></url></urlset>';
        $own = ['shop.com', 'www.shop.com', 'www.claudephpbuilder.com'];
        $out = SearchFiles::sitemap($xml, 'https://shop.com', $own, true, false);
        $locs = array_column(SearchFiles::parseSitemap($out), 'loc');
        $this->assertSame(['https://shop.com/', 'https://shop.com/about', 'https://other.org/x'], $locs);
        $this->assertStringContainsString('<lastmod>2026-11-01</lastmod>', $out);

        $this->assertContains('https://shop.com/login', array_column(SearchFiles::parseSitemap(SearchFiles::sitemap($xml, 'https://shop.com', $own, false, false)), 'loc'));
        $this->assertSame([], SearchFiles::parseSitemap(SearchFiles::sitemap($xml, 'https://shop.com', $own, true, true)), 'a hidden site lists nothing');
    }

    public function test_llms_txt_lists_the_real_pages_and_skips_account_ones(): void
    {
        $out = SearchFiles::llms('https://shop.com', 'Shop', 'We sell [things].', "Para one.\n\nPara two.", [
            ['title' => 'About us', 'path' => '/about', 'description' => 'Who we are'],
            ['title' => 'Sign in', 'path' => '/login'],
            ['title' => 'About again', 'path' => '/about'],
        ], 'hi@shop.com');
        $this->assertStringStartsWith("# Shop\n\n> We sell [things].\n\nPara one.\n\nPara two.\n\n## Pages\n- [Home](https://shop.com/)\n- [About us](https://shop.com/about): Who we are\n", $out);
        $this->assertStringNotContainsString('/login', $out);
        $this->assertStringEndsWith("## Contact\n- hi@shop.com\n", $out);
    }

    // ── IndexNow ────────────────────────────────────────────────────────────

    public function test_indexnow_sends_what_is_new_or_changed_and_records_it(): void
    {
        $record = [];
        IndexNow::useStore([
            'load' => function () use (&$record) { return $record['seo.indexnow.sent'] ?? []; },
            'save' => function (array $v, string $key) use (&$record) { $record[$key] = $v; },
        ]);
        $sent = [];
        IndexNow::useSender(function (string $url, string $json) use (&$sent) { $sent[] = json_decode($json, true); return 200; });
        $xml = '<urlset><url><loc>https://shop.com/</loc><lastmod>2026-11-01</lastmod></url>'
             . '<url><loc>https://shop.com/login</loc></url><url><loc>https://other.org/x</loc></url></urlset>';

        $r = (new IndexNow())->run('https://shop.com', $xml);
        $this->assertSame('sent', $r['status']);
        $this->assertSame(['https://shop.com/'], $sent[0]['urlList'], 'own host only, no sign-in page');
        $this->assertSame(IndexNow::key('shop.com'), $sent[0]['key']);
        $this->assertSame('https://shop.com/indexnow-key.txt', $sent[0]['keyLocation']);
        $this->assertStringStartsWith('2026-11-01|', $record['seo.indexnow.sent']['https://shop.com/']);

        $this->assertSame('nothing', (new IndexNow())->run('https://shop.com', $xml)['status'], 'unchanged = not sent again');
        $changed = str_replace('2026-11-01', '2026-11-05', $xml);
        $this->assertSame('sent', (new IndexNow())->run('https://shop.com', $changed)['status']);
        $this->assertNotSame(IndexNow::key('shop.com'), IndexNow::key('other.com'), 'each site its own key');
    }

    public function test_indexnow_never_pings_from_a_dev_host_and_keeps_its_record_on_failure(): void
    {
        $calls = 0;
        IndexNow::useSender(function () use (&$calls) { $calls++; return 500; });
        $record = [];
        IndexNow::useStore(['load' => fn () => [], 'save' => function (array $v, string $k) use (&$record) { $record[$k] = $v; }]);
        $xml = '<urlset><url><loc>http://shop.test/</loc></url></urlset>';
        $this->assertSame('skipped', (new IndexNow())->run('http://shop.test', $xml)['status']);
        $this->assertSame('skipped', (new IndexNow())->run('https://localhost', $xml)['status']);
        $this->assertSame(0, $calls);

        $r = (new IndexNow())->run('https://shop.com', '<urlset><url><loc>https://shop.com/</loc></url></urlset>');
        $this->assertSame('failed', $r['status']);
        $this->assertArrayNotHasKey(IndexNow::SETTING, $record, 'a refused batch is tried again next run');
    }

    public function test_indexnow_due_resends_a_page_without_lastmod_after_30_days(): void
    {
        $cur = ['https://a.com/' => null];
        $this->assertSame([], IndexNow::due($cur, ['https://a.com/' => '|2026-11-01'], '2026-11-20'));
        $this->assertSame(['https://a.com/'], IndexNow::due($cur, ['https://a.com/' => '|2026-10-01'], '2026-11-20'));
    }

    // ── The settings form (the writer of every seo.* key) ───────────────────

    public function test_the_form_writes_every_switch_so_turning_one_off_sticks(): void
    {
        $out = SearchSettingsController::clean(['seo_enabled' => '1', 'seo_canonical' => '1', 'seo_ai_train' => 'yes',
            'seo_main_host' => 'https://Shop.com/path', 'seo_business_type' => 'Hacker', 'seo_business_name' => " <b>Luigi</b>\n ",
            'seo_verify_google' => '<meta name="google-site-verification" content="code123" />']);
        $this->assertSame('1', $out['seo.enabled']);
        $this->assertSame('1', $out['seo.canonical']);
        $this->assertSame('0', $out['seo.llms'], 'an unticked box is written as off');
        $this->assertSame('0', $out['seo.indexnow']);
        $this->assertSame('yes', $out['seo.ai.train']);
        $this->assertSame('yes', $out['seo.ai.search'], 'an absent radio keeps its default');
        $this->assertSame('no', SearchSettingsController::clean([])['seo.ai.train']);
        $this->assertSame('shop.com', $out['seo.main_host']);
        $this->assertSame('', SearchSettingsController::clean(['seo_main_host' => 'localhost'])['seo.main_host']);
        $this->assertSame('Organization', $out['seo.business.type']);
        $this->assertSame('Luigi', $out['seo.business.name']);
        $this->assertSame('code123', $out['seo.verify.google']);
        foreach (array_keys(SearchSettings::defaults()) as $key) {
            $this->assertArrayHasKey($key, $out, "the form has a field for $key");
        }
    }
}
