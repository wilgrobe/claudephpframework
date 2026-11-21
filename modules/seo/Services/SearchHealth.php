<?php
// modules/seo/Services/SearchHealth.php
namespace Modules\Seo\Services;

/**
 * The Search health report (Get found, 21 Nov 2026): the live site checked from outside, the way a search engine and
 * an AI crawler see it — https and the http redirect, one main address, robots.txt, the sitemap, the home page's tags
 * (canonical, noindex, title, description, language, one main heading, social image, phone viewport) and whether AI
 * assistants can read it. Each failure says how to fix it.
 *
 * run() fetches a handful of addresses on the site's OWN main host and nothing else; evaluate() is pure.
 * A check is ['key', 'label', 'ok' => true|false|null (null = not applicable), 'detail', 'fix'].
 */
final class SearchHealth
{
    public const TITLE_MAX = 60;
    public const DESC_MIN = 50;
    public const DESC_MAX = 160;

    /** Crawlers that must get the page when the site allows AI answers. */
    public const AI_PROBES = ['OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot'];

    /** Tests: fn (string $url, string $userAgent): array{code:int, headers:array<string,string>, body:string} */
    public ?\Closure $fetch = null;

    /** @return array{host:string, hidden:string, checks:list<array>} */
    public function run(SiteProfile $p, bool $aiAnswers): array
    {
        $main = $p->mainHost;
        if ($main === '') return ['host' => '', 'hidden' => $p->hidden, 'checks' => []];
        $get = $this->fetch ?? static fn (string $u, string $ua) => self::get($u, $ua);
        $ua = 'Mozilla/5.0 (compatible; SiteSearchHealth/1.0)';
        $twin = str_starts_with($main, 'www.') ? substr($main, 4) : "www.$main";
        $r = [
            'home'    => $get("https://$main/", $ua),
            'http'    => $get("http://$main/", $ua),
            'twin'    => $get("https://$twin/", $ua),
            'robots'  => $get("https://$main/robots.txt", $ua),
            'sitemap' => $get("https://$main/sitemap.xml", $ua),
        ];
        if ($aiAnswers && !$p->isHidden()) {
            foreach (self::AI_PROBES as $bot) $r['bot:' . $bot] = $get("https://$main/", "Mozilla/5.0 (compatible; $bot/1.0)");
        }
        return ['host' => $main, 'hidden' => $p->hidden, 'checks' => self::evaluate($r, $main, $p->isHidden(), $aiAnswers)];
    }

    /** One GET, no redirects followed, at most 1 MB read. @return array{code:int, headers:array<string,string>, body:string} */
    public static function get(string $url, string $ua): array
    {
        if (!function_exists('curl_init')) return ['code' => 0, 'headers' => [], 'body' => ''];
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => $ua, CURLOPT_MAXFILESIZE => 1048576, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['code' => $code, 'headers' => $headers, 'body' => is_string($body) ? substr($body, 0, 1048576) : ''];
    }

    private static function check(string $key, string $label, ?bool $ok, string $detail, string $fix = ''): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok === false ? $fix : ''];
    }

    private static function redirectsTo(array $res, string $prefix): bool
    {
        return in_array((int) ($res['code'] ?? 0), [301, 308], true) && str_starts_with(strtolower((string) ($res['headers']['location'] ?? '')), strtolower($prefix));
    }

    /** Does the "User-agent: *" group shut every crawler out of the whole site ("Disallow: /")? Pure. */
    public static function blocksAll(string $robots): bool
    {
        $agents = []; $inRules = false;
        foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) { $agents = []; $inRules = false; }
                $agents[] = $v;
                continue;
            }
            $inRules = true;
            if ($k === 'disallow' && $v === '/' && in_array('*', $agents, true)) return true;
        }
        return false;
    }

    /** Pure. @param array<string, array{code:int, headers:array<string,string>, body:string}> $r */
    public static function evaluate(array $r, string $main, bool $hidden, bool $aiAnswers): array
    {
        $out = [];
        $none = ['code' => 0, 'headers' => [], 'body' => ''];
        $home = ($r['home'] ?? []) + $none;
        if ($hidden) {
            $out[] = self::check('hidden', 'Hidden from search', true, 'This copy is kept out of search on purpose (see the note at the top). The page checks below apply once it is the live site.');
        }
        $up = $home['code'] >= 200 && $home['code'] < 300;
        $out[] = self::check('https', 'The site answers on https', $up, $up ? "https://$main/ answers." : "https://$main/ answered " . ($home['code'] ?: 'nothing') . '.',
            'Point the domain at the site and give it a certificate (hosting: the domain settings; behind Cloudflare: SSL/TLS set to Full).');
        $http = ($r['http'] ?? []) + $none;
        $toHttps = self::redirectsTo($http, "https://$main");
        $out[] = self::check('http', 'Plain http:// sends to https://', $toHttps, $toHttps ? 'It does, permanently.' : "http://$main/ answered " . ($http['code'] ?: 'nothing') . ' instead of a permanent redirect to https.',
            'Turn on the https redirect here; behind Cloudflare also turn on SSL/TLS → Edge Certificates → Always Use HTTPS.');
        $twin = ($r['twin'] ?? []) + $none;
        $twinOk = $twin['code'] === 0 ? null : self::redirectsTo($twin, "https://$main");
        $out[] = self::check('main', 'One main address', $twinOk,
            $twinOk === null ? 'The www / bare spelling of the address does not answer, so nothing is duplicated.' : ($twinOk ? 'The other spelling sends to the main address.' : 'The other spelling serves a copy instead of sending to the main address.'),
            'Turn on "Send every other address to the main one" here, and point the other spelling at the site.');

        $robots = ($r['robots'] ?? []) + $none;
        $rb = (string) $robots['body'];
        $blocked = self::blocksAll($rb);
        $out[] = self::check('robots', 'robots.txt lets search engines in and names the sitemap',
            $hidden ? null : ($robots['code'] === 200 && !$blocked && stripos($rb, 'Sitemap:') !== false),
            $robots['code'] !== 200 ? 'robots.txt answered ' . ($robots['code'] ?: 'nothing') . '.' : ($blocked ? 'It shuts every crawler out of the whole site.' : (stripos($rb, 'Sitemap:') === false ? 'It has no Sitemap line.' : 'It does.')),
            'Something else is serving robots.txt (a static file in public/, or the web server) — remove it so the site\'s own answers.');
        $sm = ($r['sitemap'] ?? []) + $none;
        $n = substr_count((string) $sm['body'], '<url>');
        $out[] = self::check('sitemap', 'The sitemap lists the pages', $hidden ? null : ($sm['code'] === 200 && $n > 0),
            $sm['code'] !== 200 ? 'sitemap.xml answered ' . ($sm['code'] ?: 'nothing') . '.' : "It lists $n " . ($n === 1 ? 'page' : 'pages') . '.',
            'Publish at least one public page.');

        if ($up && !$hidden) {
            $b = (string) $home['body'];
            preg_match('/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)/i', $b, $c);
            $canon = (string) ($c[1] ?? '');
            $out[] = self::check('canonical', 'The home page names its main address', $canon !== '' && str_starts_with(strtolower($canon), "https://$main"),
                $canon === '' ? 'No canonical link on the home page.' : "Canonical: $canon", 'Turn on "Tell search engines each page\'s one real address" here.');
            $noindex = (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $b) || stripos((string) ($home['headers']['x-robots-tag'] ?? ''), 'noindex') !== false;
            $out[] = self::check('indexable', 'The home page may be indexed', !$noindex, $noindex ? 'The home page says "noindex".' : 'It may.',
                'Something marks the home page "noindex": check "Hide the whole site" here, the page\'s own settings, and any server header.');
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $b, $t);
            $title = trim(html_entity_decode(strip_tags((string) ($t[1] ?? '')), ENT_QUOTES));
            $tl = mb_strlen($title);
            $out[] = self::check('title', 'A clear page title', $tl > 0 && $tl <= self::TITLE_MAX,
                $tl === 0 ? 'The home page has no title.' : "\"$title\" ($tl characters" . ($tl > self::TITLE_MAX ? '; search results cut it at about ' . self::TITLE_MAX : '') . ').',
                'Set the home page\'s search title in the page editor, under ' . self::TITLE_MAX . ' characters.');
            preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/i', $b, $d);
            $desc = trim(html_entity_decode((string) ($d[1] ?? ''), ENT_QUOTES));
            $dl = mb_strlen($desc);
            $out[] = self::check('description', 'A description for search results', $dl >= self::DESC_MIN && $dl <= self::DESC_MAX,
                $dl === 0 ? 'The home page has no description.' : "$dl characters.",
                'Write one or two sentences (' . self::DESC_MIN . '–' . self::DESC_MAX . ' characters) in the home page\'s search description.');
            $lang = preg_match('/<html[^>]+lang=["\']([a-z]{2}[^"\']*)/i', $b, $l);
            $out[] = self::check('lang', 'The page says its language', (bool) $lang, $lang ? 'Language: ' . $l[1] : 'No lang attribute.', 'The theme\'s <html> tag needs a lang attribute.');
            $h1 = preg_match_all('/<h1[\s>]/i', $b);
            $out[] = self::check('h1', 'One main heading', $h1 === 1, "$h1 main " . ($h1 === 1 ? 'heading' : 'headings') . '.',
                $h1 === 0 ? 'Give the home page a main heading (the largest heading size).' : 'Keep one largest heading per page; make the others smaller.');
            $og = (bool) preg_match('/<meta[^>]+property=["\']og:image["\']/i', $b);
            $out[] = self::check('image', 'A picture for shared links', $og, $og ? 'Set.' : 'No social image: shared links show no picture.', 'Add a social image to the home page in the page editor.');
            $vp = (bool) preg_match('/<meta[^>]+name=["\']viewport["\']/i', $b);
            $out[] = self::check('phone', 'Fits a phone screen', $vp, $vp ? 'The page declares a mobile viewport.' : 'No viewport tag.', 'The theme needs <meta name="viewport" content="width=device-width, initial-scale=1">.');
        }
        if ($aiAnswers && !$hidden) {
            $bad = [];
            foreach (self::AI_PROBES as $bot) {
                $res = $r['bot:' . $bot] ?? null;
                if ($res !== null && ($res['code'] < 200 || $res['code'] >= 400)) $bad[] = $bot . ' (' . ($res['code'] ?: 'no answer') . ')';
            }
            $out[] = self::check('ai', 'AI assistants can read the site', $bad === [], $bad
                    ? 'Turned away when asked as: ' . implode(', ', $bad) . '. (This check asks from your server, not the crawler\'s own addresses; a firewall that checks those may treat the real crawler differently — confirm in its crawler settings.)'
                    : 'ChatGPT, Perplexity and Claude\'s search crawlers get the page.',
                'Behind Cloudflare: Security → Bots → AI Crawl Control — allow the search and answer crawlers (blocking training only is fine).');
        }
        return $out;
    }
}
