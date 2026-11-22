<?php
// modules/seo/Services/SearchSettings.php
namespace Modules\Seo\Services;

/**
 * The "Get found" settings, with their defaults (Will, 17 Nov 2026: the basics are free and ON for every site, and
 * each one can be turned OFF per site).
 *
 * Every switch defaults to '1', so a site that has never opened /admin/settings/search gets all of it. The AI policy
 * defaults to "search and AI answers yes, training no". All keys are site scope (scope_key NULL, written through
 * SettingsService by SearchSettingsController — the only writer).
 *
 * Reads go through setting(); tests swap the reader with using().
 */
final class SearchSettings
{
    /** Master switch: off = this module adds nothing (the framework's own robots/sitemap answer as before). */
    public const ENABLED = 'seo.enabled';

    public const SWITCHES = [
        'seo.main_host_redirect' => 'Send every other address of the site (www., the builder subdomain) to the main one with a permanent redirect',
        'seo.https_redirect'     => 'Send http:// visits to https:// when the site has HTTPS',
        'seo.canonical'          => 'Tell search engines each page\'s one real address (canonical link)',
        'seo.noindex_account'    => 'Keep sign-in, sign-up and password pages out of search results',
        'seo.robots_extras'      => 'robots.txt: say what AI may do with the site and skip background addresses',
        'seo.jsonld'             => 'Describe the business to search engines (structured data on the home page)',
        'seo.llms'               => 'A map of the site for AI assistants (/llms.txt)',
        'seo.markdown'           => 'Give AI assistants a plain Markdown copy of a page when they ask for one',
        'seo.indexnow'           => 'Tell Bing and other IndexNow engines when pages are new or changed',
    ];

    public const AI_KEYS = ['seo.ai.search' => 'yes', 'seo.ai.input' => 'yes', 'seo.ai.train' => 'no'];

    /** Search engine verification tags: setting key => [meta name, label]. */
    public const VERIFY = [
        'seo.verify.google'    => ['google-site-verification', 'Google Search Console'],
        'seo.verify.bing'      => ['msvalidate.01', 'Bing Webmaster Tools'],
        'seo.verify.yandex'    => ['yandex-verification', 'Yandex Webmaster'],
        'seo.verify.pinterest' => ['p:domain_verify', 'Pinterest'],
        'seo.verify.facebook'  => ['facebook-domain-verification', 'Facebook / Meta'],
    ];

    public const TEXT = [
        'seo.main_host'          => '',
        'seo.hide_site'          => '0',
        'seo.llms.intro'         => '',
        'seo.business.type'      => 'Organization',
        'seo.business.name'      => '',
        'seo.business.description' => '',
        'seo.business.email'     => '',
        'seo.business.phone'     => '',
        'seo.business.street'    => '',
        'seo.business.city'      => '',
        'seo.business.region'    => '',
        'seo.business.postal'    => '',
        'seo.business.country'   => '',
        'seo.business.same_as'   => '',
    ];

    /** @var null|callable(string $key, string $default): mixed */
    private static $reader = null;

    /** @var array<string,string> per-request cache */
    private static array $cache = [];

    /** Tests: read settings from a map (or a callable) instead of the database. Null restores the real reader. */
    public static function using(null|array|callable $source): void
    {
        self::$cache = [];
        if (is_array($source)) {
            self::$reader = static fn (string $k, string $d) => array_key_exists($k, $source) ? $source[$k] : $d;
        } else {
            self::$reader = $source;
        }
    }

    public static function defaults(): array
    {
        $d = [self::ENABLED => '1'];
        foreach (array_keys(self::SWITCHES) as $k) { $d[$k] = '1'; }
        foreach (self::AI_KEYS as $k => $v)       { $d[$k] = $v; }
        foreach (array_keys(self::VERIFY) as $k)  { $d[$k] = ''; }
        return $d + self::TEXT;
    }

    public static function get(string $key): string
    {
        if (array_key_exists($key, self::$cache)) { return self::$cache[$key]; }
        $default = (string) (self::defaults()[$key] ?? '');
        try {
            if (self::$reader !== null) {
                $v = (self::$reader)($key, $default);
            } else {
                $v = function_exists('setting') ? setting($key, $default) : $default;
            }
        } catch (\Throwable) {
            $v = $default;
        }
        if ($v === null) { $v = $default; }
        if (is_bool($v)) { $v = $v ? '1' : '0'; }
        return self::$cache[$key] = is_scalar($v) ? trim((string) $v) : $default;
    }

    /** The master switch. */
    public static function enabled(): bool
    {
        return self::get(self::ENABLED) !== '0';
    }

    /** One piece: on unless the site turned it (or the whole module) off. */
    public static function on(string $key): bool
    {
        return self::enabled() && self::get($key) !== '0';
    }

    /** The robots Content-Signal value, from the three AI policy settings. */
    public static function contentSignal(): string
    {
        $parts = [];
        foreach (['search' => 'seo.ai.search', 'ai-input' => 'seo.ai.input', 'ai-train' => 'seo.ai.train'] as $name => $key) {
            $parts[] = $name . '=' . (self::get($key) === 'no' ? 'no' : 'yes');
        }
        return implode(', ', $parts);
    }

    public static function flush(): void { self::$cache = []; }
}
