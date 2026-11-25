<?php
// modules/seo/Services/PageText.php
namespace Modules\Seo\Services;

/**
 * The title and description search engines show for any public address of the site (24 Nov 2026).
 *
 * A page made in the page editor has its own SEO fields. A page a MODULE makes (/faq, /blog, /contact, a comparison,
 * a guide) had none: its title was fixed in code and its description was the site's tagline, the same on every one.
 * The owner sets these on /admin/settings/search ("Titles and descriptions"); they are kept in one setting, a JSON map
 * of path => {title, description}. SearchHead puts them on the finished page, so they apply whatever printed it.
 *
 * A blank part is left as the page printed it. The title is used exactly as typed (no site name is added).
 */
final class PageText
{
    public const KEY = 'seo.page_text';

    public const MAX_ROWS = 300;
    public const MAX_TITLE = 120;
    public const MAX_DESCRIPTION = 320;

    /** @var array<string, array{title:string, description:string}>|null */
    private static ?array $rows = null;

    public static function reset(): void { self::$rows = null; }

    /** @return array<string, array{title:string, description:string}> path => text, for this site. Never throws. */
    public static function all(): array
    {
        return self::$rows ??= self::parse(SearchSettings::get(self::KEY));
    }

    /** @return array{title:string, description:string}|null */
    public static function forPath(string $path): ?array
    {
        $p = self::cleanPath($path);
        return $p === '' ? null : (self::all()[$p] ?? null);
    }

    /**
     * Pure: the stored JSON → rows; anything unusable is dropped.
     *
     * @return array<string, array{title:string, description:string}>
     */
    public static function parse(string $json): array
    {
        $d = $json === '' ? null : json_decode($json, true);
        if (!is_array($d)) { return []; }
        $out = [];
        foreach ($d as $path => $t) {
            if (!is_array($t) || count($out) >= self::MAX_ROWS) { continue; }
            $p = self::cleanPath((string) $path);
            $row = ['title'       => self::cleanText(is_string($t['title'] ?? null) ? $t['title'] : '', self::MAX_TITLE),
                    'description' => self::cleanText(is_string($t['description'] ?? null) ? $t['description'] : '', self::MAX_DESCRIPTION)];
            if ($p !== '' && ($row['title'] !== '' || $row['description'] !== '')) { $out[$p] = $row; }
        }
        return $out;
    }

    /**
     * Pure: the posted form (text_path[], text_title[], text_description[]) → the JSON to store ('' = none). A row
     * without an address, or with both parts blank, is removed.
     */
    public static function fromPost(array $post): string
    {
        $titles = (array) ($post['text_title'] ?? []);
        $descs  = (array) ($post['text_description'] ?? []);
        $rows = [];
        foreach ((array) ($post['text_path'] ?? []) as $i => $path) {
            if (!is_scalar($path) || trim((string) $path) === '') { continue; }
            $rows[(string) $path] = ['title'       => is_scalar($titles[$i] ?? null) ? (string) $titles[$i] : '',
                                     'description' => is_scalar($descs[$i] ?? null) ? (string) $descs[$i] : ''];
        }
        return self::encode($rows);
    }

    /** Pure: rows → the JSON to store, cleaned ('' = none). */
    public static function encode(array $rows): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $clean = self::parse((string) json_encode($rows, $flags | JSON_INVALID_UTF8_SUBSTITUTE));
        return $clean ? (string) json_encode($clean, $flags) : '';
    }

    /** Pure: what an owner may type for an address ("/faq", "faq", "https://site/faq?x") → "/faq"; '' = unusable. */
    public static function cleanPath(string $raw): string
    {
        $raw = trim(str_replace(["\r", "\n", "\0"], '', $raw));
        if ($raw === '') { return ''; }
        if (preg_match('#^https?://[^/]+(.*)$#i', $raw, $m)) { $raw = $m[1] === '' ? '/' : $m[1]; }
        $raw = (string) preg_replace('/[?#].*$/', '', $raw);
        if (preg_match('/[\s<>"\'\\\\:]/', $raw) || str_contains($raw, '//')) { return ''; }
        $path = '/' . trim($raw, '/');
        return strlen($path) <= 300 ? $path : '';
    }

    private static function cleanText(string $s, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $max);
    }

    /**
     * Pure: the page with its title and description (and the copies social sites read) replaced. Callbacks, not
     * replacement strings: "$49 a month" must not be read as a back-reference.
     *
     * @param array{title:string, description:string}|null $text
     */
    public static function apply(string $html, ?array $text): string
    {
        $end = stripos($html, '</head>');
        if ($text === null || $end === false) { return $html; }
        $head  = substr($html, 0, $end);
        $title = (string) ($text['title'] ?? '');
        $desc  = (string) ($text['description'] ?? '');
        if ($title !== '') {
            $tag = '<title>' . self::esc($title) . '</title>';
            $n = 0;
            $head = (string) preg_replace_callback('#<title\b[^>]*>.*?</title>#is', static fn () => $tag, $head, 1, $n);
            if ($n === 0) { $head .= $tag . "\n"; }
            [$head] = self::setMeta($head, 'og:title', $title);
            [$head] = self::setMeta($head, 'twitter:title', $title);
        }
        if ($desc !== '') {
            [$head, $n] = self::setMeta($head, 'description', $desc);
            if ($n === 0) { $head .= '<meta name="description" content="' . self::esc($desc) . '">' . "\n"; }
            [$head] = self::setMeta($head, 'og:description', $desc);
            [$head] = self::setMeta($head, 'twitter:description', $desc);
        }
        return $head . substr($html, $end);
    }

    /**
     * Every <meta name|property="$name"> already on the page, with the new content.
     *
     * @return array{0:string, 1:int} the head, and how many there were
     */
    private static function setMeta(string $head, string $name, string $value): array
    {
        $n = 0;
        $out = preg_replace_callback(
            '#<meta\b(?=(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\s(name|property)\s*=\s*["\']?' . preg_quote($name, '#') . '["\'\s>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
            static fn (array $m) => '<meta ' . strtolower($m[1]) . '="' . $name . '" content="' . self::esc($value) . '">',
            $head, -1, $n);
        return $out === null ? [$head, 0] : [$out, $n];
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
