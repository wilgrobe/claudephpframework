<?php
// modules/seo/Services/SearchFiles.php
namespace Modules\Seo\Services;

/**
 * The plain files machines read — robots.txt, sitemap.xml, llms.txt — as pure functions of their inputs, so each can
 * be tested without a request or a database. SearchController feeds them.
 */
final class SearchFiles
{
    /** Background calls and proxy paths Google found in page scripts and reported as 404s (StoriesDen, 17 Nov). */
    public const EXTRA_DISALLOW = ['/session/', '/cookie-consent', '/cdn-cgi/'];

    /**
     * Crawlers that fetch a page to answer a question and cite it (ChatGPT search, Claude, Perplexity). Turned away
     * by name only when the owner says no to AI answers (seo.ai.input). Kept here, in one place, to keep current.
     */
    public const ANSWER_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User'];

    /** Crawlers (and control tokens) that gather pages to TRAIN models — turned away by name when ai-train=no. */
    public const TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent', 'cohere-ai'];

    /**
     * Pure: the named groups for the AI crawlers the policy says no to. A crawler obeys only the group that names it,
     * so a named "Disallow: /" turns it away whatever the "*" group allows. '' when nothing is turned away.
     */
    public static function botGroups(string $signal): string
    {
        $no = static fn (string $part) => (bool) preg_match('/\b' . preg_quote($part, '/') . '=no\b/', $signal);
        $bots = array_merge($no('ai-input') ? self::ANSWER_BOTS : [], $no('ai-train') ? self::TRAINING_BOTS : []);
        if (!$bots) { return ''; }
        $why = $no('ai-input') ? 'AI assistants and AI training' : 'AI training';
        return "# $why: not on this site.\n" . implode("\n", array_map(static fn ($b) => 'User-agent: ' . $b, $bots)) . "\nDisallow: /\n";
    }

    // ── robots.txt ──────────────────────────────────────────────────────────

    /**
     * Pure. $robots = the framework's robots.txt for this base. Adds the Content-Signal line (inside "User-agent: *",
     * where the spec reads it) and the extra Disallow lines. A hidden site keeps "Allow: /" — so engines can still
     * read each page's noindex and drop it — but loses its Sitemap line and says no to everything.
     */
    public static function robots(string $robots, string $signal, bool $extras, bool $hidden): string
    {
        if ($hidden) {
            $robots = (string) preg_replace('/^Sitemap:.*\R?/mi', '', $robots);
            $signal = 'search=no, ai-input=no, ai-train=no';
            $extras = true;
        }
        if (!$extras) { return $robots; }
        $line = 'Content-Signal: ' . $signal;
        foreach (self::EXTRA_DISALLOW as $d) {
            if (!preg_match('/^Disallow:\s*' . preg_quote($d, '/') . '\s*$/mi', $robots)) { $line .= "\nDisallow: " . $d; }
        }
        if (str_contains($robots, 'Content-Signal:')) { return $robots; }
        $out = preg_replace('/^(User-agent:\s*\*[ \t]*)$/mi', "$1\n" . $line, $robots, 1, $n);
        if ($n === 0 || !is_string($out)) {
            $out = rtrim($robots) . "\n\nUser-agent: *\n" . $line . "\nAllow: /\n";
        }
        // A hidden site already says no to everyone in the "*" group; naming crawlers adds nothing there.
        $groups = $hidden ? '' : self::botGroups($signal);
        if ($groups === '') { return $out; }
        // Before the Sitemap line (it belongs to no group), else at the end.
        if (preg_match('/^Sitemap:/mi', $out)) {
            return (string) preg_replace('/^(Sitemap:)/mi', $groups . "\n$1", $out, 1);
        }
        return rtrim($out) . "\n\n" . $groups;
    }

    // ── sitemap.xml ─────────────────────────────────────────────────────────

    /**
     * Pure: sitemap XML → entries.
     * @return list<array{loc:string, lastmod:?string, changefreq:?string, priority:?string}>
     */
    public static function parseSitemap(string $xml): array
    {
        $out = [];
        if (!preg_match_all('#<url>(.*?)</url>#s', $xml, $m)) { return $out; }
        foreach ($m[1] as $block) {
            if (!preg_match('#<loc>([^<]+)</loc>#', $block, $l)) { continue; }
            $get = static fn (string $tag) => preg_match('#<' . $tag . '>([^<]+)</' . $tag . '>#', $block, $x) ? trim($x[1]) : null;
            $out[] = [
                'loc'        => html_entity_decode(trim($l[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                'lastmod'    => $get('lastmod'),
                'changefreq' => $get('changefreq'),
                'priority'   => $get('priority'),
            ];
        }
        return $out;
    }

    /**
     * Pure: the sitemap as search engines should see it — every address of THIS site on the main host (the
     * framework builds them from the request host, and in a CLI run from APP_URL, the Builder's apex), account pages
     * left out when they are noindexed (a noindexed page in a sitemap is a Search Console warning), no duplicates.
     * Addresses on other sites are kept as they are. A hidden site gets an empty sitemap.
     *
     * @param list<string> $ownHosts
     */
    public static function sitemap(string $xml, string $base, array $ownHosts, bool $dropAccount, bool $hidden): string
    {
        $entries = $hidden ? [] : self::parseSitemap($xml);
        $seen = [];
        $body = '';
        foreach ($entries as $e) {
            $loc = self::rebase($e['loc'], $base, $ownHosts);
            $path = (string) (parse_url($loc, PHP_URL_PATH) ?: '/');
            if ($dropAccount && SearchHead::noindex($path)) { continue; }
            if (isset($seen[$loc])) { continue; }
            $seen[$loc] = true;
            $body .= "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            foreach (['lastmod', 'changefreq', 'priority'] as $f) {
                if (!empty($e[$f])) { $body .= "    <$f>" . htmlspecialchars((string) $e[$f], ENT_XML1, 'UTF-8') . "</$f>\n"; }
            }
            $body .= "  </url>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $body . '</urlset>';
    }

    /** Pure: an address of this site moved onto the main base (path + query kept). Others unchanged. */
    public static function rebase(string $loc, string $base, array $ownHosts): string
    {
        $u = parse_url($loc);
        if (!is_array($u) || empty($u['host']) || $base === '') { return $loc; }
        if (!in_array(strtolower((string) $u['host']), $ownHosts, true)) { return $loc; }
        return rtrim($base, '/') . ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
    }

    // ── llms.txt ────────────────────────────────────────────────────────────

    /**
     * Pure: the llms.txt file (llmstxt.org) — a short Markdown map of the site for AI assistants: what it is, and one
     * line for each public page worth reading. Built from the pages the site actually publishes, so it can't name a
     * page that doesn't exist.
     *
     * @param list<array{title:string, path:string, description?:string}> $pages
     */
    public static function llms(string $base, string $name, string $summary, string $intro, array $pages, string $email = ''): string
    {
        $base = rtrim($base, '/');
        $one = static fn (string $s) => trim((string) preg_replace('/\s+/', ' ', $s));
        $out = ['# ' . ($one($name) !== '' ? $one($name) : 'This site'), ''];
        if ($one($summary) !== '') { $out[] = '> ' . $one($summary); $out[] = ''; }
        if (trim($intro) !== '') {
            foreach (preg_split('/\R{2,}/', trim($intro)) ?: [] as $para) { $out[] = $one($para); $out[] = ''; }
        }
        $out[] = '## Pages';
        $out[] = '- [Home](' . $base . '/)';
        $seen = ['/' => true];
        foreach ($pages as $p) {
            $path = '/' . ltrim((string) ($p['path'] ?? ''), '/');
            $title = $one((string) ($p['title'] ?? ''));
            if ($title === '' || isset($seen[$path]) || SearchHead::noindex($path)) { continue; }
            $seen[$path] = true;
            $line = '- [' . str_replace(['[', ']'], ['(', ')'], $title) . '](' . $base . $path . ')';
            $d = $one((string) ($p['description'] ?? ''));
            if ($d !== '') { $line .= ': ' . $d; }
            $out[] = $line;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = '';
            $out[] = '## Contact';
            $out[] = '- ' . $email;
        }
        return implode("\n", $out) . "\n";
    }
}
