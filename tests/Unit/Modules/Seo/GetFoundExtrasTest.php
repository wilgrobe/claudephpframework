<?php
// tests/Unit/Modules/Seo/GetFoundExtrasTest.php
namespace Tests\Unit\Modules\Seo;

use Modules\Seo\Services\SearchFiles;
use Modules\Seo\Services\SearchHealth;
use Modules\Seo\Services\SiteProfile;
use Tests\TestCase;

/**
 * "Get found" additions (Will, 21 Nov 2026: "do everything we can for Builder sites to succeed in search engines and
 * AI answers"): a staging copy hides itself, robots.txt turns AI crawlers away BY NAME when the owner says no, and the
 * Search health report checks the live site the way a search engine and an AI crawler see it.
 */
final class GetFoundExtrasTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Services/SearchSettings', 'Services/SiteProfile', 'Services/SearchHead', 'Services/SearchFiles', 'Services/SearchHealth'] as $f) {
            $class = 'Modules\\Seo\\' . str_replace('/', '\\', $f);
            if (!class_exists($class, false)) { require_once BASE_PATH . '/modules/seo/' . $f . '.php'; }
        }
    }

    private static function profile(array $over = []): SiteProfile
    {
        return SiteProfile::build($over + [
            'server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'], 'settings' => [], 'app_url' => 'https://example.com', 'builder' => false,
        ]);
    }

    public function test_a_staging_copy_hides_itself(): void
    {
        $this->assertSame('staging', self::profile(['env_hidden' => true])->hidden);
        $this->assertTrue(self::profile(['env_hidden' => true])->isHidden());
        $this->assertSame('', self::profile()->hidden, 'no flag: a normal site');
        $this->assertSame('staging', self::profile(['env_hidden' => true, 'settings' => ['hide_site' => '1']])->hidden, 'staging wins over the owner\'s own switch');
    }

    /** The hosted Builder writes every managed staging server's .env; a standalone framework install has no such code. */
    public function test_every_managed_staging_server_is_written_hidden(): void
    {
        $file = BASE_PATH . '/app/Deploy/Managed/ManagedProvisioner.php';
        if (!is_file($file)) { $this->markTestSkipped('Builder only: managed staging servers'); }
        // Read from this checkout's source: in a full run another test may already have loaded the class from elsewhere.
        $src = (string) file_get_contents($file);
        $this->assertSame(1, preg_match('/function stagingEnv\(.*?\$set = \[(.*?)\];/s', $src, $m));
        $this->assertStringContainsString("'SEARCH_HIDDEN' => '1'", $m[1], 'every managed staging server is written hidden');
    }

    /**
     * A generated kit ships a stand-in App\Tenancy\Tenant (one site, always current) but no central database. Seen
     * as "the Builder", a kit had no domains, so its main host became whatever host the visitor typed and www. was
     * never sent to the real address (found rebuilding MarketOtter, 21 Nov 2026).
     */
    public function test_a_generated_kit_is_not_mistaken_for_the_builder(): void
    {
        $src = (string) file_get_contents(BASE_PATH . '/modules/seo/Services/SiteProfile.php');
        $this->assertSame(1, preg_match('/function current\(\): self\s*\{(.*?)\n    \}/s', $src, $m));
        $this->assertMatchesRegularExpression('/\$builder = class_exists\(\\\\App\\\\Tenancy\\\\Tenant::class\) && class_exists\(\\\\App\\\\Database\\\\CentralDatabase::class\);/', $m[1],
            'the Builder needs its central database too — the Tenant class alone is also in every kit');
        $this->assertStringNotContainsString('$t->row)', $m[1], 'a stand-in tenant has no row');

        // What a kit then gets: APP_URL's host is the main one, and www. redirects to it.
        $kit = self::profile(['server' => ['HTTP_HOST' => 'www.example.com', 'HTTPS' => 'on']]);
        $this->assertSame('example.com', $kit->mainHost);
        $asBuilder = self::profile(['server' => ['HTTP_HOST' => 'www.example.com', 'HTTPS' => 'on'], 'builder' => true, 'tenant' => ['tier' => 'production']]);
        $this->assertSame('www.example.com', $asBuilder->mainHost, 'the old mistake: no domains, so the typed host won');
    }

    public function test_robots_turns_ai_crawlers_away_by_name_only_when_the_owner_says_no(): void
    {
        $this->assertSame('', SearchFiles::botGroups('search=yes, ai-input=yes, ai-train=yes'), 'all yes: nobody named');
        $train = SearchFiles::botGroups('search=yes, ai-input=yes, ai-train=no');
        $this->assertStringContainsString("User-agent: GPTBot\n", $train);
        $this->assertStringContainsString("User-agent: ClaudeBot\n", $train);
        $this->assertStringContainsString("User-agent: Google-Extended\n", $train);
        $this->assertStringNotContainsString('OAI-SearchBot', $train, 'training no, answers yes: ChatGPT search may still read the site');
        $this->assertStringEndsWith("Disallow: /\n", $train);
        $both = SearchFiles::botGroups('search=yes, ai-input=no, ai-train=no');
        $this->assertStringContainsString('User-agent: OAI-SearchBot', $both);
        $this->assertStringContainsString('User-agent: PerplexityBot', $both);
        $this->assertStringContainsString('User-agent: GPTBot', $both);
        $this->assertStringNotContainsString('Googlebot', $both, 'search engines are never named and turned away');
    }

    public function test_the_named_groups_sit_before_the_sitemap_and_not_on_a_hidden_site(): void
    {
        $fw = "User-agent: *\nDisallow: /admin/\nAllow: /\n\nSitemap: https://example.com/sitemap.xml\n";
        $out = SearchFiles::robots($fw, 'search=yes, ai-input=yes, ai-train=no', true, false);
        $this->assertLessThan(strpos($out, 'Sitemap:'), strpos($out, 'User-agent: GPTBot'));
        $this->assertStringContainsString("Content-Signal: search=yes, ai-input=yes, ai-train=no\n", $out);
        $this->assertSame(1, substr_count($out, 'Sitemap:'));
        $this->assertStringNotContainsString('GPTBot', SearchFiles::robots($fw, 'search=yes, ai-input=yes, ai-train=no', true, true), 'a hidden site already says no to everyone');
        $this->assertStringNotContainsString('GPTBot', SearchFiles::robots($fw, 'search=yes, ai-input=yes, ai-train=no', false, false), 'robots extras switched off: the framework file as it was');
        $this->assertStringContainsString('User-agent: GPTBot', SearchFiles::robots("User-agent: *\nAllow: /\n", 'search=yes, ai-input=yes, ai-train=no', true, false), 'no Sitemap line: at the end');
    }

    public function test_blocks_all_reads_only_the_star_group(): void
    {
        $this->assertTrue(SearchHealth::blocksAll("User-agent: *\nDisallow: /\n"));
        $this->assertFalse(SearchHealth::blocksAll("User-agent: *\nDisallow: /admin/\nAllow: /\n\nUser-agent: GPTBot\nDisallow: /\n"), 'a named crawler shut out is not the whole site');
        $this->assertTrue(SearchHealth::blocksAll("User-agent: GPTBot\nUser-agent: *\nDisallow: / # all\n"));
        $this->assertFalse(SearchHealth::blocksAll(''));
    }

    private static function page(string $body, int $code = 200, array $headers = []): array
    {
        return ['code' => $code, 'headers' => $headers, 'body' => $body];
    }

    private const GOOD = '<!DOCTYPE html><html lang="en"><head><title>Ruiz Plumbing — repairs in Benson</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="description" content="Family plumbers in Benson since 1998: leaks, water heaters and drains, same day, with upfront prices.">'
        . '<link rel="canonical" href="https://example.com/"><meta property="og:image" content="https://example.com/c.png">'
        . '<script type="application/ld+json">{"@type":"Plumber"}</script></head>'
        . '<body><h1>Ruiz Plumbing</h1><h2>Services</h2></body></html>';

    private static function fetches(array $over = []): array
    {
        return $over + [
            'home' => self::page(self::GOOD),
            'http' => self::page('', 301, ['location' => 'https://example.com/']),
            'twin' => self::page('', 301, ['location' => 'https://example.com/']),
            'robots' => self::page("User-agent: *\nAllow: /\n\nSitemap: https://example.com/sitemap.xml\n"),
            'sitemap' => self::page('<urlset><url><loc>https://example.com/</loc><lastmod>2026-11-20</lastmod></url><url><loc>https://example.com/about</loc><lastmod>2026-11-01</lastmod></url></urlset>'),
            'llms' => self::page("# Ruiz Plumbing

> Family plumbers in Benson.
"),
            'bot:OAI-SearchBot' => self::page(self::GOOD), 'bot:PerplexityBot' => self::page(self::GOOD), 'bot:Claude-SearchBot' => self::page(self::GOOD),
        ];
    }

    /** @return array<string, ?bool> key => ok */
    private static function oks(array $checks): array
    {
        return array_column($checks, 'ok', 'key');
    }

    public function test_a_healthy_site_passes_every_check(): void
    {
        $c = SearchHealth::evaluate(self::fetches(), 'example.com', false, true);
        $this->assertSame(['https', 'http', 'main', 'robots', 'sitemap', 'lastmod', 'canonical', 'indexable', 'title', 'description', 'lang', 'h1', 'image', 'phone', 'jsonld', 'llms', 'ai'], array_keys(self::oks($c)));
        $this->assertSame([], array_keys(array_filter(self::oks($c), fn ($ok) => $ok !== true)), 'all pass');
        foreach ($c as $x) $this->assertSame('', $x['fix'], 'a passing check carries no fix');
    }

    public function test_each_failure_is_caught_and_says_how_to_fix_it(): void
    {
        $bad = [
            'http'        => ['http' => self::page('hello')],
            'main'        => ['twin' => self::page(self::GOOD)],
            'robots'      => ['robots' => self::page("User-agent: *\nDisallow: /\n")],
            'sitemap'     => ['sitemap' => self::page('<urlset></urlset>')],
            'canonical'   => ['home' => self::page(str_replace('https://example.com/"', 'https://other.com/"', self::GOOD))],
            'indexable'   => ['home' => self::page(self::GOOD, 200, ['x-robots-tag' => 'noindex'])],
            'title'       => ['home' => self::page(str_replace('Ruiz Plumbing — repairs in Benson', str_repeat('Long ', 20), self::GOOD))],
            'description' => ['home' => self::page(str_replace('Family plumbers in Benson since 1998: leaks, water heaters and drains, same day, with upfront prices.', 'Short.', self::GOOD))],
            'lang'        => ['home' => self::page(str_replace(' lang="en"', '', self::GOOD))],
            'h1'          => ['home' => self::page(str_replace('<h2>', '<h1>', self::GOOD))],
            'image'       => ['home' => self::page(str_replace('og:image', 'og:other', self::GOOD))],
            'phone'       => ['home' => self::page(str_replace('name="viewport"', 'name="vp"', self::GOOD))],
            'ai'          => ['bot:OAI-SearchBot' => self::page('blocked', 403)],
            'lastmod'     => ['sitemap' => self::page('<urlset><url><loc>https://example.com/</loc><lastmod>2026-11-20</lastmod></url><url><loc>https://example.com/faq</loc></url></urlset>')],
            'jsonld'      => ['home' => self::page(str_replace('application/ld+json', 'text/plain', self::GOOD))],
            'llms'        => ['llms' => self::page('<html>Not found</html>')],
        ];
        foreach ($bad as $key => $over) {
            $c = array_column(SearchHealth::evaluate(self::fetches($over), 'example.com', false, true), null, 'key');
            $this->assertFalse($c[$key]['ok'], $key);
            $this->assertNotSame('', $c[$key]['fix'], "$key says how to fix it");
        }
        $this->assertStringContainsString('OAI-SearchBot (403)', array_column(SearchHealth::evaluate(self::fetches($bad['ai']), 'example.com', false, true), 'detail', 'key')['ai']);
    }

    public function test_what_does_not_apply_is_not_judged(): void
    {
        $c = self::oks(SearchHealth::evaluate(self::fetches(['twin' => self::page('', 0)]), 'example.com', false, true));
        $this->assertNull($c['main'], 'no www twin answering: nothing duplicated');
        $c = self::oks(SearchHealth::evaluate(self::fetches(), 'example.com', false, false));
        $this->assertArrayNotHasKey('ai', $c, 'the owner said no to AI answers: not a failure');
        $h = SearchHealth::evaluate(self::fetches(['robots' => self::page("User-agent: *\nDisallow: /\n")]), 'example.com', true, true);
        $c = self::oks($h);
        $this->assertTrue($c['hidden']);
        $this->assertNull($c['robots'], 'a hidden copy shuts crawlers out on purpose');
        $this->assertNull($c['sitemap']);
        $this->assertArrayNotHasKey('canonical', $c, 'page checks wait until it is the live site');
        $this->assertArrayNotHasKey('ai', $c);
        $this->assertArrayNotHasKey('lastmod', $c);
        $this->assertArrayNotHasKey('llms', $c);
        $old = self::fetches(); unset($old['llms']);
        $this->assertArrayNotHasKey('llms', self::oks(SearchHealth::evaluate($old, 'example.com', false, true)), 'not fetched: not judged');
        $this->assertSame(1, SearchHealth::datedEntries('<url><loc>a</loc><lastmod>2026-01-01</lastmod></url><url><loc>b</loc><lastmod> </lastmod></url>'));
        $down = self::oks(SearchHealth::evaluate(self::fetches(['home' => self::page('', 0)]), 'example.com', false, true));
        $this->assertFalse($down['https']);
        $this->assertArrayNotHasKey('title', $down, 'no page to read');
    }

    public function test_the_report_only_fetches_the_sites_own_address(): void
    {
        $seen = [];
        $h = new SearchHealth();
        $h->fetch = function (string $url, string $ua) use (&$seen) { $seen[] = parse_url($url, PHP_URL_HOST); return ['code' => 200, 'headers' => [], 'body' => '']; };
        $out = $h->run(self::profile(), true);
        $this->assertSame('example.com', $out['host']);
        $this->assertSame([], array_values(array_diff(array_unique($seen), ['example.com', 'www.example.com'])), 'nothing else is ever fetched');
        $this->assertCount(9, $seen, 'home, http, the twin, robots, sitemap, llms.txt and three AI crawlers');
        $seen = [];
        $h->run(self::profile(['env_hidden' => true]), true);
        $this->assertCount(5, $seen, 'a hidden copy is not probed as AI crawlers');
    }

    public function test_the_report_is_reached_from_the_settings_page(): void
    {
        $r = file_get_contents(BASE_PATH . '/modules/seo/routes.php');
        $this->assertStringContainsString('\'/admin/settings/search/check\', "$A@check", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]', $r);
        $v = file_get_contents(BASE_PATH . '/modules/seo/Views/admin/settings.php');
        $this->assertStringContainsString('action="/admin/settings/search/check"', $v);
        $this->assertStringContainsString("'staging' =>", $v, 'the page says why a staging copy is hidden');
    }

    // ── Phase 4: the same report for any public site (MarketOtter's site check) ──

    public function test_any_public_site_can_be_checked_by_host_name(): void
    {
        $seen = [];
        $h = new SearchHealth();
        $h->fetch = function (string $url, string $ua) use (&$seen) { $seen[] = parse_url($url, PHP_URL_HOST); return self::fetches()['home']; };
        $out = $h->runFor('Example.com');
        $this->assertSame('example.com', $out['host']);
        $this->assertSame([], array_values(array_diff(array_unique($seen), ['example.com', 'www.example.com'])), 'only that site');
        $this->assertCount(9, $seen);
        foreach (['localhost', '127.0.0.1', '10.0.0.5', 'http://example.com', 'example.com/path', 'exa mple.com', '', 'a.b'] as $bad) {
            $this->assertNull($h->runFor($bad), "refused: $bad");
        }
    }

    public function test_a_customer_typed_site_is_never_fetched_from_a_private_address(): void
    {
        foreach (['10.1.2.3', '192.168.0.1', '172.16.5.5', '127.0.0.1', '169.254.169.254', '0.0.0.0', '::1', 'fc00::1', 'fe80::1'] as $ip) {
            $this->assertFalse(SearchHealth::publicIp($ip), $ip);
        }
        $this->assertTrue(SearchHealth::publicIp('104.236.96.16'));
        $this->assertTrue(SearchHealth::publicIp('2606:4700::1'));
        $src = (string) file_get_contents(BASE_PATH . '/modules/seo/Services/SearchHealth.php');
        $this->assertStringContainsString('static fn (string $u, string $ua) => self::get($u, $ua, true);', $src, 'runFor fetches public-only by default');
        $this->assertStringContainsString('if ($pin !== null) curl_setopt($ch, CURLOPT_RESOLVE, $pin);', $src, 'connects to the address it checked');
    }
}
