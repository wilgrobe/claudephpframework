<?php
// modules/seo/Services/MarkdownPage.php
namespace Modules\Seo\Services;

/**
 * Pages as Markdown for AI assistants (Get found, 22 Nov 2026 — moved here from StoriesDen's publish-hub
 * AgentReadiness, where it had run since 13 Nov). A request whose Accept header prefers text/markdown over text/html
 * gets the page's main content as Markdown, with its title, description and address on top, instead of the whole web
 * page. Cloudflare's "Markdown for Agents" does the same on its paid plans. Browsers never ask for Markdown, so they
 * always get HTML.
 *
 * SearchHead decides when (switch seo.markdown, never on a hidden site or page) and converts the finished page in its
 * own output buffer, after the canonical link is in place, so the front matter carries the page's real address.
 */
final class MarkdownPage
{
    /** Signed-in and machine surfaces are never turned into Markdown (sign-in pages too: SearchHead::noindex). */
    private const SKIP_PREFIXES = ['/admin', '/app', '/api', '/auth', '/account', '/password', '/profile', '/dashboard',
        '/notifications', '/login', '/register', '/logout', '/webhooks', '/analytics', '/messages', '/groups', '/content'];

    private const BLOCK = ['address', 'article', 'aside', 'blockquote', 'details', 'dd', 'div', 'dl', 'dt', 'fieldset',
        'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'ol', 'p',
        'pre', 'section', 'summary', 'table', 'ul', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'caption', 'button'];

    private const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'form', 'input', 'select',
        'textarea', 'nav', 'dialog', 'canvas', 'video', 'audio', 'object', 'head'];

    private const BR = "\u{E000}";

    /** Pure: does this request ask for Markdown more than for HTML, on a page that has a public Markdown form? */
    public static function wantsMarkdown(string $method, string $accept, string $path): bool
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') { return false; }
        foreach (self::SKIP_PREFIXES as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) { return false; }
        }
        if (SearchHead::noindex($path)) { return false; }
        if (preg_match('/\.[a-z0-9]{2,5}$/i', $path)) { return false; } // files (.txt, .xml, .js …) aren't pages
        $md = null; $html = null;
        foreach (explode(',', strtolower($accept)) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $type = $bits[0] ?? '';
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/', $param, $m)) { $q = (float) $m[1]; }
            }
            if ($type === 'text/markdown' || $type === 'text/x-markdown') { $md = max($md ?? 0.0, $q); }
            if ($type === 'text/html') { $html = max($html ?? 0.0, $q); }
        }
        return $md !== null && $md > 0 && ($html === null || $md > $html);
    }

    /** A rough token count (about four characters a token), which is what the header is for. */
    public static function tokens(string $md): int
    {
        return (int) ceil(mb_strlen($md) / 4);
    }

    // ── HTML → Markdown ─────────────────────────────────────────────────────

    /** Pure: the page's main content as Markdown, with its title, description and address on top. */
    public static function toMarkdown(string $html, string $base = ''): string
    {
        $base = rtrim($base, '/');
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);

        $title = trim((string) ($xp->query('//title')->item(0)?->textContent ?? ''));
        $descNode = $xp->query('//meta[@name="description"]/@content')->item(0);
        $canonNode = $xp->query('//link[@rel="canonical"]/@href')->item(0);
        $root = $doc->getElementById('main-content')
            ?? $xp->query('//main')->item(0)
            ?? $xp->query('//body')->item(0);
        if ($root === null) { return ''; }

        $body = implode("\n\n", array_filter(self::blocks($root, $base), static fn ($b) => trim($b) !== ''));
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $body));

        $front = ['---'];
        if ($title !== '') { $front[] = 'title: ' . self::yaml($title); }
        if ($descNode && trim($descNode->nodeValue) !== '') { $front[] = 'description: ' . self::yaml(trim($descNode->nodeValue)); }
        if ($canonNode && trim($canonNode->nodeValue) !== '') { $front[] = 'url: ' . self::yaml(self::abs(trim($canonNode->nodeValue), $base)); }
        $front[] = '---';
        return ($body === '' ? '' : implode("\n", $front) . "\n\n" . $body . "\n");
    }

    private static function yaml(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private static function skipped(\DOMElement $el): bool
    {
        if (in_array(strtolower($el->tagName), self::SKIP, true)) { return true; }
        // Decorative only. Collapsed content (an FAQ answer with display:none until clicked) IS content, so it stays.
        return $el->hasAttribute('hidden') || strtolower($el->getAttribute('aria-hidden')) === 'true';
    }

    /**
     * Two elements laid side by side by CSS ("<b>8</b><span>stages</span>") carry no space between them in the
     * HTML; add one, unless either side already has it or the next starts with punctuation.
     */
    private static function join(string $left, string $right, bool $prevWasElement): string
    {
        if (!$prevWasElement || $left === '' || $right === '') { return $left . $right; }
        if (preg_match('/[\s\x{00A0}' . self::BR . ']$/u', $left) || preg_match('/^[\s\x{00A0}' . self::BR . '.,;:!?)\]]/u', $right)) {
            return $left . $right;
        }
        return $left . ' ' . $right;
    }

    /** @return list<string> */
    private static function blocks(\DOMNode $node, string $base): array
    {
        $out = []; $inline = ''; $prevEl = false;
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) { $inline .= $c->nodeValue; $prevEl = false; continue; }
            if (!$c instanceof \DOMElement || self::skipped($c)) { continue; }
            if (!in_array(strtolower($c->tagName), self::BLOCK, true)) {
                $inline = self::join($inline, self::inline($c, $base), $prevEl); $prevEl = true; continue;
            }
            $prevEl = false;
            self::flush($inline, $out);
            foreach (self::block($c, $base) as $b) { $out[] = $b; }
        }
        self::flush($inline, $out);
        return $out;
    }

    private static function flush(string &$inline, array &$out): void
    {
        $t = self::clean($inline);
        if ($t !== '') { $out[] = $t; }
        $inline = '';
    }

    /** Collapse whitespace; line breaks from <br> survive. */
    private static function clean(string $s): string
    {
        $s = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
        $lines = array_map('trim', explode(self::BR, $s));
        return trim(implode("  \n", array_filter($lines, static fn ($l) => $l !== '')));
    }

    /** @return list<string> */
    private static function block(\DOMElement $el, string $base): array
    {
        $tag = strtolower($el->tagName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $t = self::clean(self::inlineChildren($el, $base));
                return $t === '' ? [] : [str_repeat('#', (int) $tag[1]) . ' ' . str_replace("  \n", ' ', $t)];
            case 'hr':
                return ['---'];
            case 'pre':
                $code = rtrim((string) $el->textContent);
                return $code === '' ? [] : ["```\n" . $code . "\n```"];
            case 'blockquote':
                $inner = self::blocks($el, $base);
                if (!$inner) { return []; }
                $lines = explode("\n", implode("\n\n", $inner));
                return [implode("\n", array_map(static fn ($l) => rtrim('> ' . $l), $lines))];
            case 'ul': case 'ol':
                $list = self::listBlock($el, $base, $tag === 'ol');
                return $list === '' ? [] : [$list];
            case 'table':
                $t = self::table($el, $base);
                return $t === '' ? [] : [$t];
            case 'dt': case 'summary':
                $t = self::clean(self::inlineChildren($el, $base));
                return $t === '' ? [] : ['**' . $t . '**'];
            case 'button':
                // An accordion's question lives in its toggle button; its "+" / "▾" marker is dropped.
                $t = trim((string) preg_replace('/\s+[^\p{L}\p{N}\s]{1,2}$/u', '', self::clean(self::inlineChildren($el, $base))));
                return ($t === '' || !preg_match('/\p{L}{2}/u', $t)) ? [] : ['**' . str_replace("  \n", ' ', $t) . '**'];
            default:
                return self::blocks($el, $base);
        }
    }

    private static function listBlock(\DOMElement $list, string $base, bool $ordered): string
    {
        $items = []; $i = 1;
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li' || self::skipped($li)) { continue; }
            $parts = self::blocks($li, $base);
            if (!$parts) { continue; }
            $marker = $ordered ? ($i++) . '. ' : '- ';
            $pad = str_repeat(' ', strlen($marker));
            $lines = explode("\n", implode("\n", $parts));
            $first = array_shift($lines);
            $items[] = $marker . $first . ($lines ? "\n" . implode("\n", array_map(static fn ($l) => $l === '' ? '' : $pad . $l, $lines)) : '');
        }
        return implode("\n", $items);
    }

    private static function table(\DOMElement $table, string $base): string
    {
        $rows = [];
        $xp = new \DOMXPath($table->ownerDocument);
        foreach ($xp->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace(['|', "  \n"], ['\\|', ' '], self::clean(self::inlineChildren($cell, $base)));
                }
            }
            if ($cells) { $rows[] = $cells; }
        }
        if (!$rows) { return ''; }
        $cols = max(array_map('count', $rows));
        $line = static fn (array $r) => '| ' . implode(' | ', array_pad($r, $cols, '')) . ' |';
        $out = [$line($rows[0]), '|' . str_repeat(' --- |', $cols)];
        foreach (array_slice($rows, 1) as $r) { $out[] = $line($r); }
        return implode("\n", $out);
    }

    private static function inlineChildren(\DOMNode $node, string $base): string
    {
        $s = ''; $prevEl = false;
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) { $s .= $c->nodeValue; $prevEl = false; }
            elseif ($c instanceof \DOMElement && !self::skipped($c)) { $s = self::join($s, self::inline($c, $base), $prevEl); $prevEl = true; }
        }
        return $s;
    }

    private static function inline(\DOMElement $el, string $base): string
    {
        $tag = strtolower($el->tagName);
        switch ($tag) {
            case 'br':
                return self::BR;
            case 'iframe':
                // An embed (a comparison table in a frame, a video) can't be inlined; point at it instead.
                $src = trim($el->getAttribute('src'));
                $title = trim($el->getAttribute('title'));
                return (!preg_match('#^https?://#i', $src) || $title === '') ? '' : self::BR . '[' . $title . '](' . $src . ')' . self::BR;
            case 'img':
                $alt = trim($el->getAttribute('alt'));
                $src = trim($el->getAttribute('src'));
                return ($alt === '' || $src === '' || str_starts_with($src, 'data:')) ? '' : '![' . $alt . '](' . self::abs($src, $base) . ')';
            case 'a':
                $text = self::inlineChildren($el, $base);
                $href = trim($el->getAttribute('href'));
                if (trim(str_replace(self::BR, '', $text)) === '') { return ''; }
                if ($href === '' || $href[0] === '#' || stripos($href, 'javascript:') === 0) { return $text; }
                return self::wrapLink($text, self::abs($href, $base));
            case 'strong': case 'b':
                return self::wrap(self::inlineChildren($el, $base), '**');
            case 'em': case 'i':
                return self::wrap(self::inlineChildren($el, $base), '*');
            case 'code': case 'kbd':
                $t = trim((string) $el->textContent);
                return $t === '' ? '' : '`' . $t . '`';
            case 'sup':
                $t = trim((string) $el->textContent);
                return $t === '' ? '' : '[' . trim($t, '[]') . ']';
            default:
                // A block element inside an inline one (a card link holding a <div>) is read as text.
                $t = self::inlineChildren($el, $base);
                return in_array($tag, self::BLOCK, true) ? ' ' . $t . ' ' : $t;
        }
    }

    /** "**x**" keeping the spaces outside the markers, so "a <b> b </b>c" reads "a **b** c". */
    private static function wrap(string $t, string $mark): string
    {
        if (trim(str_replace(self::BR, '', $t)) === '') { return $t === '' ? '' : ' '; }
        preg_match('/^([\s\x{00A0}]*)(.*?)([\s\x{00A0}]*)$/us', $t, $m);
        return ($m[1] !== '' ? ' ' : '') . $mark . $m[2] . $mark . ($m[3] !== '' ? ' ' : '');
    }

    private static function wrapLink(string $text, string $href): string
    {
        preg_match('/^([\s\x{00A0}]*)(.*?)([\s\x{00A0}]*)$/us', $text, $m);
        $label = trim(str_replace(self::BR, ' ', (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $m[2])));
        return ($m[1] !== '' ? ' ' : '') . '[' . $label . '](' . $href . ')' . ($m[3] !== '' ? ' ' : '');
    }

    private static function abs(string $url, string $base): string
    {
        if ($base !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//')) { return $base . $url; }
        return $url;
    }
}
