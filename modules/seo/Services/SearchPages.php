<?php
// modules/seo/Services/SearchPages.php
namespace Modules\Seo\Services;

use Core\Database\Database;

/**
 * Per-page search choices (Get found phase 3, 22 Nov 2026): a page can be hidden from search ("noindex, follow"
 * on the page, out of the sitemap and llms.txt) and can name its canonical address — another page of this site, or
 * an original somewhere else (a post first published elsewhere).
 *
 * Two columns on `pages` (this module's migration adds them): search_hidden TINYINT, canonical_url VARCHAR. The
 * owner sets them on /admin/settings/search (Pages); the Builder wizard sets them in step 7 and carries them into
 * hosted sites and downloads. Everything here tolerates a site whose pages table does not have them yet.
 */
final class SearchPages
{
    public const MAX_CANONICAL = 1000;

    /** @var array<string, array{hidden:bool, canonical:string}>|null slug => choices, for pages that have any */
    private static ?array $rows = null;
    private static ?bool $columns = null;

    /** Tests and CLI loops start again. */
    public static function reset(): void { self::$rows = null; self::$columns = null; }

    /** Tests: the page rows to use instead of the database (slug => [hidden, canonical]). */
    public static function using(?array $rows): void { self::$rows = $rows; self::$columns = $rows === null ? null : true; }

    /** Pure: the slug a public path names — '/' is the guest home page, '/x' is page x. Anything deeper is not a page. */
    public static function slugFor(string $path, string $homeSlug): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') { return $homeSlug !== '' ? $homeSlug : null; }
        $slug = substr($path, 1);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $slug) ? $slug : null;
    }

    /**
     * Pure: a canonical override as typed → what is stored. A full http(s) address, or a path on this site
     * ("/guides/original"). Anything else (javascript:, a bare word, a protocol-relative //host) is dropped.
     */
    public static function cleanCanonical(string $raw): string
    {
        $raw = trim(str_replace(["\r", "\n", "\0"], '', $raw));
        if ($raw === '' || strlen($raw) > self::MAX_CANONICAL) { return ''; }
        if ($raw[0] === '/' && !str_starts_with($raw, '//')) {
            return preg_match('#^/[^\s<>"\']*$#', $raw) ? $raw : '';
        }
        if (!preg_match('#^https?://#i', $raw) || filter_var($raw, FILTER_VALIDATE_URL) === false) { return ''; }
        $host = parse_url($raw, PHP_URL_HOST);
        return is_string($host) && $host !== '' && !preg_match('/[\s<>"\']/', $raw) ? $raw : '';
    }

    /** Pure: the canonical address a page's override stands for — a path is put on this site's main base. */
    public static function canonicalHref(string $override, string $base): string
    {
        if ($override === '') { return ''; }
        return $override[0] === '/' ? rtrim($base, '/') . $override : $override;
    }

    /** This request's page choices, or null when the path is not a page with any. Never throws. */
    public static function forPath(string $path): ?array
    {
        $slug = self::slugFor($path, self::homeSlug());
        if ($slug === null) { return null; }
        return self::all()[$slug] ?? null;
    }

    /**
     * The public paths of hidden pages ('/' when the home page itself is hidden), for the sitemap, llms.txt and
     * IndexNow. Never throws.
     *
     * @return list<string>
     */
    public static function hiddenPaths(): array
    {
        $home = self::homeSlug();
        $out = [];
        foreach (self::all() as $slug => $r) {
            if (!$r['hidden']) { continue; }
            $out[] = '/' . $slug;
            if ($home !== '' && $slug === $home) { $out[] = '/'; }
        }
        return $out;
    }

    /** @return array<string, array{hidden:bool, canonical:string}> */
    public static function all(): array
    {
        if (self::$rows !== null) { return self::$rows; }
        self::$rows = [];
        try {
            if (!self::hasColumns()) { return self::$rows; }
            $rows = Database::getInstance()->fetchAll(
                "SELECT slug, search_hidden, canonical_url FROM pages
                  WHERE search_hidden = 1 OR (canonical_url IS NOT NULL AND canonical_url <> '')"
            );
            foreach ($rows as $r) {
                self::$rows[(string) $r['slug']] = [
                    'hidden'    => (int) ($r['search_hidden'] ?? 0) === 1,
                    'canonical' => self::cleanCanonical((string) ($r['canonical_url'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            self::$rows = [];
        }
        return self::$rows;
    }

    /** Both columns exist on this site's pages table (a site not yet migrated has neither). */
    public static function hasColumns(): bool
    {
        if (self::$columns !== null) { return self::$columns; }
        try {
            $n = (int) Database::getInstance()->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'pages' AND column_name IN ('search_hidden','canonical_url')"
            );
            return self::$columns = $n === 2;
        } catch (\Throwable) {
            return self::$columns = false;
        }
    }

    private static function homeSlug(): string
    {
        try {
            return function_exists('setting') ? (string) setting('guest_home_page_slug', '') : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
