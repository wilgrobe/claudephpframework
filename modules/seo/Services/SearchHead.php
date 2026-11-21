<?php
// modules/seo/Services/SearchHead.php
namespace Modules\Seo\Services;

/**
 * The per-request part of "Get found", installed from the module's boot():
 *
 *   1. one main address — a GET for a public page on another address of the site (www., the builder subdomain once a
 *      custom domain is verified, http:// when the site has https) gets a 301 to the same path on the main one;
 *   2. headers — X-Robots-Tag on hidden sites and account pages, and the homepage's Link to /llms.txt;
 *   3. an output buffer that finishes every 200 HTML page: a canonical link where the page has none (or names the
 *      site under a wrong host — page.php used to print APP_URL, the Builder's apex, on hosted tenants), noindex on
 *      account pages, noindex everywhere on a hidden site, verification tags and structured data on the homepage.
 *
 * Borrowed from StoriesDen's publish-hub (SeoHead / AgentReadiness, Nov 2026). It only ADDS what a page lacks; a
 * page that prints its own canonical or robots tag keeps it. A page must never fail because of this: every step is
 * wrapped and falls back to the untouched page.
 */
final class SearchHead
{
    /** Sign-in and account pages: worth crawling for links, never worth a search result. */
    public const NOINDEX_PREFIXES = ['/login', '/register', '/password', '/logout', '/auth', '/verify-email', '/email/verify',
        '/two-factor', '/invite', '/session', '/cookie-consent', '/claim', '/account', '/profile', '/dashboard', '/admin'];

    /**
     * The Builder's own site only (phase 5, 21 Nov 2026): the signed-in app surfaces under it. Kept apart from the list
     * above because a generated site may well have a public /projects or /studio page of its own.
     */
    public const APEX_NOINDEX_PREFIXES = ['/signup', '/projects', '/studio', '/notifications', '/groups', '/users', '/downloads',
        '/deploy', '/ops', '/oidc', '/unsubscribe', '/feedback', '/search', '/policies'];

    /** Never redirected to another host: certificate challenges, machine callbacks, signed-in surfaces. */
    private const NO_CROSS_HOST = ['/.well-known', '/webhooks', '/api', '/admin', '/claim', '/oidc', '/auth', '/deploy',
        '/login', '/logout', '/register', '/password', '/session', '/cookie-consent'];

    public const LINK = '</llms.txt>; rel="describedby"; type="text/plain"';

    /** Called from the module's boot(). Never throws. */
    public static function install(array $server): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) { return; }
        try {
            if (!SearchSettings::enabled()) { return; }
            $p = SiteProfile::current();
            $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
            $uri    = (string) ($server['REQUEST_URI'] ?? '/');
            $path   = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

            $to = self::redirectTarget($p, $method, $uri, SearchSettings::on('seo.main_host_redirect'), SearchSettings::on('seo.https_redirect'));
            if ($to !== null) {
                header('Location: ' . $to, true, 301);
                header('Cache-Control: public, max-age=3600');
                exit;
            }

            foreach (self::headersFor($p, $method, $path) as $h) { header($h, false); }

            if ($method !== 'GET' && $method !== 'HEAD') { return; }
            if (preg_match('/\.[a-z0-9]{2,5}$/i', $path)) { return; }   // files, not pages
            ob_start(self::handler($p, $path));
        } catch (\Throwable) {
            // A page must never fail because of this.
        }
    }

    /** Pure: where a request should be sent instead, or null to serve it here. */
    public static function redirectTarget(SiteProfile $p, string $method, string $uri, bool $hostOn, bool $httpsOn): ?string
    {
        $method = strtoupper($method);
        if (($method !== 'GET' && $method !== 'HEAD') || $p->mainHost === '' || $p->requestHost === '') { return null; }
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        if (str_starts_with($path, '/.well-known')) { return null; }

        $host = $p->requestHost;
        $crossHost = $hostOn && $host !== $p->mainHost && in_array($host, $p->redirectHosts, true);
        if ($crossHost && !str_starts_with($host, 'www.') && !str_starts_with($p->mainHost, 'www.') && self::under($path, self::NO_CROSS_HOST)) {
            // A different site name (the builder subdomain → the custom domain): only public pages move, so a
            // signed-in admin, a claim link or a webhook is never bounced between two cookie jars.
            $crossHost = false;
        }
        $toHttps = $httpsOn && !$p->requestHttps && $p->httpsKnown && SiteProfile::isPublicHost($p->mainHost)
            && ($host === $p->mainHost || $crossHost);
        if (!$crossHost && !$toHttps) { return null; }

        $scheme = ($p->https || $toHttps) ? 'https' : 'http';
        $rest = $uri === '' ? '/' : ($uri[0] === '/' ? $uri : '/' . $uri);
        return $scheme . '://' . $p->mainHost . $rest;
    }

    /** Pure: the headers this request gets. */
    public static function headersFor(SiteProfile $p, string $method, string $path): array
    {
        $out = [];
        if ($p->isHidden()) {
            $out[] = 'X-Robots-Tag: noindex, nofollow';
        } elseif (SearchSettings::on('seo.noindex_account') && self::noindex($path, $p->apex)) {
            $out[] = 'X-Robots-Tag: noindex, follow';
        }
        $m = strtoupper($method);
        if ($path === '/' && ($m === 'GET' || $m === 'HEAD') && !$p->isHidden() && SearchSettings::on('seo.llms')) {
            $out[] = 'Link: ' . self::LINK;
        }
        return $out;
    }

    public static function handler(SiteProfile $p, string $path): \Closure
    {
        $acc = '';
        return static function (string $chunk, int $phase) use (&$acc, $p, $path): string {
            if ($phase & PHP_OUTPUT_HANDLER_CLEAN) { $acc = ''; } else { $acc .= $chunk; }
            if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) { return ''; }
            if ($phase & PHP_OUTPUT_HANDLER_CLEAN) { return ''; }
            $page = $acc; $acc = '';
            try {
                if ((int) http_response_code() !== 200 || !self::sentAsHtml(headers_list())) { return $page; }
                return self::apply($page, $p, $path, self::extras($p, $path));
            } catch (\Throwable) {
                return $page;
            }
        };
    }

    /** What the homepage adds besides the tags: verification metas + structured data. */
    private static function extras(SiteProfile $p, string $path): string
    {
        if ($path !== '/' || $p->isHidden()) { return ''; }
        $out = self::verificationTags();
        if (SearchSettings::on('seo.jsonld')) { $out .= StructuredData::forSite($p->base()); }
        return $out;
    }

    public static function verificationTags(): string
    {
        $out = '';
        foreach (SearchSettings::VERIFY as $key => [$name]) {
            $v = self::verificationValue(SearchSettings::get($key));
            if ($v !== '') {
                $out .= '<meta name="' . $name . '" content="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }
        return $out;
    }

    /** Pure: an owner may paste the whole <meta …> tag or just the code; keep only the code. */
    public static function verificationValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') { return ''; }
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)) { $raw = $m[1]; }
        return preg_match('/^[A-Za-z0-9_\-.:=+\/]{4,200}$/', $raw) ? $raw : '';
    }

    /**
     * Pure: the page with what is missing added before </head>.
     * $extra is raw HTML (already escaped) to add on this page, e.g. the homepage's verification tags + JSON-LD.
     */
    public static function apply(string $html, SiteProfile $p, string $path, string $extra = ''): string
    {
        $end = stripos($html, '</head>');
        $base = $p->base();
        if ($end === false || $base === '') { return $html; }
        $head = substr($html, 0, $end);
        $add = '';

        if (SearchSettings::on('seo.canonical')) {
            if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?canonical\b[^>]*>/i', $head, $m, PREG_OFFSET_CAPTURE)) {
                $fixed = self::fixCanonicalTag($m[0][0], $p);
                if ($fixed !== $m[0][0]) {
                    $head = substr($head, 0, $m[0][1]) . $fixed . substr($head, $m[0][1] + strlen($m[0][0]));
                }
            } else {
                $add .= '<link rel="canonical" href="' . htmlspecialchars(self::canonical($base, $path), ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }

        $hasRobots = (bool) preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?robots\b/i', $head);
        if ($p->isHidden()) {
            // A hidden site wins over a page's own "index": the most restrictive robots tag is the one engines obey.
            $add .= '<meta name="robots" content="noindex, nofollow">' . "\n";
        } elseif (!$hasRobots && SearchSettings::on('seo.noindex_account') && self::noindex($path, $p->apex)) {
            $add .= '<meta name="robots" content="noindex, follow">' . "\n";
        }

        if ($extra !== '' && !($p->isHidden())) {
            // Structured data a page already carries (its own JSON-LD) is left alone: two Organization graphs confuse.
            if (stripos($html, 'application/ld+json') !== false) {
                $extra = (string) preg_replace('#<script type="application/ld\+json">.*?</script>\s*#s', '', $extra);
            }
            $add .= $extra;
        }
        if ($add === '' && $head === substr($html, 0, $end)) { return $html; }
        return $head . $add . substr($html, $end);
    }

    /**
     * Pure: an existing canonical tag that names this site under the wrong host or scheme (the Builder's apex on a
     * hosted tenant, www., http://) is pointed at the main address. A canonical to somebody else's site is kept: a
     * page may deliberately name an original elsewhere.
     */
    public static function fixCanonicalTag(string $tag, SiteProfile $p): string
    {
        if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $m)) { return $tag; }
        $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        $u = parse_url($href);
        if (!is_array($u) || empty($u['host'])) { return $tag; }
        $host = strtolower((string) $u['host']);
        // ownHosts includes APP_URL's host — on a hosted tenant, the Builder's apex that page.php used to print.
        if (!in_array($host, $p->ownHosts, true)) { return $tag; }
        $new = rtrim($p->base(), '/') . ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        if ($new === $href) { return $tag; }
        return str_replace($m[0], 'href=' . $m[1] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $m[1], $tag);
    }

    /** Pure: the address a page is known by — main host, this path, no query string, no trailing slash. */
    public static function canonical(string $base, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') { $path = rtrim($path, '/'); }
        return rtrim($base, '/') . $path;
    }

    public static function noindex(string $path, bool $apex = false): bool
    {
        return self::under($path, self::NOINDEX_PREFIXES) || ($apex && self::under($path, self::APEX_NOINDEX_PREFIXES));
    }

    private static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $pre) {
            if ($path === $pre || str_starts_with($path, rtrim($pre, '/') . '/')) { return true; }
        }
        return false;
    }

    /** @param list<string> $headers */
    public static function sentAsHtml(array $headers): bool
    {
        foreach (array_reverse($headers) as $h) {
            if (stripos($h, 'content-type:') === 0) { return stripos($h, 'text/html') !== false; }
        }
        return true; // PHP's default
    }
}
