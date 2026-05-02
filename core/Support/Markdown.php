<?php
// core/Support/Markdown.php
namespace Core\Support;

/**
 * Tiny Markdown → HTML renderer for the practical 90% case.
 *
 * Covers: ATX headings (1-6 #s), paragraphs, bold (** or __), italic
 * (single * or _), inline code (backticks), fenced code blocks (triple
 * backticks), unordered lists (- * +), ordered lists (1.), blockquotes
 * (>), horizontal rules (--- or *** or ___), links [text](url), images
 * ![alt](url), and hard line breaks via two-space-then-newline.
 *
 * What it deliberately does NOT do:
 *   - raw HTML passthrough — input is HTML-escaped first; if a caller
 *     wants raw HTML they should use the siteblocks.html block instead
 *   - tables (GFM-flavored — punt to a real lib later if needed)
 *   - footnotes, task lists, definition lists, anchor IDs
 *   - reference-style links
 *   - nested lists (single level only)
 *
 * No external dependencies — wrapping league/commonmark or similar is
 * a future composer change. This is intentionally minimal, predictable,
 * and small enough to read in one sitting.
 *
 * Safety: all input is htmlspecialchars'd up front; URLs in links/images
 * are filtered to http(s)/mailto/relative. No `javascript:` URLs.
 */
class Markdown
{
    public static function render(string $source): string
    {
        return (new self())->convert($source);
    }

    private function convert(string $source): string
    {
        // Normalize line endings + escape all HTML up front. Markdown
        // syntax characters (#, *, _, [, ]) survive escaping fine.
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $source = htmlspecialchars($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Pull fenced code blocks out first so their contents don't get
        // touched by inline processing. Replace each with a placeholder
        // we substitute back at the very end.
        $codeBlocks = [];
        $source = preg_replace_callback(
            '/^```([a-zA-Z0-9_+-]*)\n(.*?)\n```$/ms',
            function ($m) use (&$codeBlocks) {
                $i = count($codeBlocks);
                $lang = $m[1] !== '' ? ' class="language-' . $m[1] . '"' : '';
                $codeBlocks[] = '<pre><code' . $lang . '>' . $m[2] . "\n</code></pre>";
                return "\x01CODEBLOCK{$i}\x01";
            },
            $source
        );

        // Walk lines and group them into block-level chunks. Each chunk
        // is a paragraph, list, blockquote, heading, hr, or placeholder.
        $lines = explode("\n", $source);
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            // Blank — flushes any open block.
            if (trim($line) === '') { $i++; continue; }

            // Code-block placeholder line.
            if (preg_match('/^\x01CODEBLOCK\d+\x01$/', $line)) {
                $blocks[] = $line;
                $i++;
                continue;
            }

            // ATX heading: 1-6 #s, space, text. Optional trailing #s.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $blocks[] = "<h{$level}>" . $this->inline($m[2]) . "</h{$level}>";
                $i++;
                continue;
            }

            // Horizontal rule: --- *** ___ (3+ on a line, optional spaces).
            if (preg_match('/^[ \t]*([-*_])([ \t]*\1){2,}[ \t]*$/', $line)) {
                $blocks[] = '<hr>';
                $i++;
                continue;
            }

            // Blockquote: one or more contiguous "> …" lines.
            if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
                $buf = [$m[1]];
                $i++;
                while ($i < $n && preg_match('/^&gt;\s?(.*)$/', $lines[$i], $m2)) {
                    $buf[] = $m2[1];
                    $i++;
                }
                $blocks[] = '<blockquote><p>' . $this->inline(implode("\n", $buf)) . '</p></blockquote>';
                continue;
            }

            // Unordered list: - * + lines.
            if (preg_match('/^[ \t]*[-*+]\s+(.*)$/', $line, $m)) {
                $items = [$m[1]];
                $i++;
                while ($i < $n && preg_match('/^[ \t]*[-*+]\s+(.*)$/', $lines[$i], $m2)) {
                    $items[] = $m2[1];
                    $i++;
                }
                $rendered = '';
                foreach ($items as $it) $rendered .= '<li>' . $this->inline($it) . '</li>';
                $blocks[] = '<ul>' . $rendered . '</ul>';
                continue;
            }

            // Ordered list: 1. 2. 3.  (we don't preserve the start index;
            // browsers default to 1, which is fine for the common case.)
            if (preg_match('/^[ \t]*\d+\.\s+(.*)$/', $line, $m)) {
                $items = [$m[1]];
                $i++;
                while ($i < $n && preg_match('/^[ \t]*\d+\.\s+(.*)$/', $lines[$i], $m2)) {
                    $items[] = $m2[1];
                    $i++;
                }
                $rendered = '';
                foreach ($items as $it) $rendered .= '<li>' . $this->inline($it) . '</li>';
                $blocks[] = '<ol>' . $rendered . '</ol>';
                continue;
            }

            // Default: paragraph. Gather contiguous non-blank, non-block-
            // start lines and render as <p>.
            $buf = [$line];
            $i++;
            while ($i < $n && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i])) {
                $buf[] = $lines[$i];
                $i++;
            }
            // Hard line break: two trailing spaces before \n become <br>.
            $joined = implode("\n", $buf);
            $joined = preg_replace('/  \n/', "<br>\n", $joined);
            $blocks[] = '<p>' . $this->inline($joined) . '</p>';
        }

        $html = implode("\n", $blocks);

        // Substitute fenced-code placeholders back.
        $html = preg_replace_callback('/\x01CODEBLOCK(\d+)\x01/', function ($m) use ($codeBlocks) {
            return $codeBlocks[(int) $m[1]] ?? '';
        }, $html);

        return $html;
    }

    /**
     * Returns true if a line would start a new block-level construct,
     * which means a paragraph-collector loop should stop on it.
     */
    private function startsBlock(string $line): bool
    {
        if (preg_match('/^#{1,6}\s+/', $line))           return true; // heading
        if (preg_match('/^[ \t]*[-*+]\s+/', $line))      return true; // ul
        if (preg_match('/^[ \t]*\d+\.\s+/', $line))      return true; // ol
        if (preg_match('/^&gt;\s?/', $line))             return true; // blockquote
        if (preg_match('/^[ \t]*([-*_])([ \t]*\1){2,}[ \t]*$/', $line)) return true; // hr
        if (preg_match('/^\x01CODEBLOCK\d+\x01$/', $line)) return true;
        return false;
    }

    /**
     * Inline-level transforms: code, images, links, bold, italic.
     * Inline code is processed first so its contents aren't touched by
     * the emphasis or link passes. The order between bold and italic
     * matters: ** must be matched before * so that bold isn't eaten as
     * two adjacent italics.
     */
    private function inline(string $s): string
    {
        // Inline code — backticks. Stash the contents in a placeholder
        // so emphasis processing won't see backtick-wrapped *s.
        $codeSpans = [];
        $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeSpans) {
            $i = count($codeSpans);
            $codeSpans[] = '<code>' . $m[1] . '</code>';
            return "\x02CODE{$i}\x02";
        }, $s);

        // Images: ![alt](url) — must come before links so the leading !
        // doesn't get eaten by the link rule.
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', function ($m) {
            $url = $this->safeUrl($m[2]);
            $alt = $m[1];
            $title = isset($m[3]) ? ' title="' . $m[3] . '"' : '';
            return '<img src="' . $url . '" alt="' . $alt . '"' . $title . '>';
        }, $s);

        // Links: [text](url) — url may carry an optional "title".
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', function ($m) {
            $url = $this->safeUrl($m[2]);
            $title = isset($m[3]) ? ' title="' . $m[3] . '"' : '';
            return '<a href="' . $url . '"' . $title . '>' . $m[1] . '</a>';
        }, $s);

        // Bold: **text** or __text__. Match first so single * isn't taken
        // as italic + italic.
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/__([^_]+)__/',     '<strong>$1</strong>', $s);

        // Italic: *text* or _text_.
        $s = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $s);
        $s = preg_replace('/_([^_]+)_/',   '<em>$1</em>', $s);

        // Restore inline-code spans.
        $s = preg_replace_callback('/\x02CODE(\d+)\x02/', function ($m) use ($codeSpans) {
            return $codeSpans[(int) $m[1]] ?? '';
        }, $s);

        return $s;
    }

    /**
     * Filter URLs to a safe subset. Allows http(s)://, mailto:, fragment
     * (#…), and relative URLs (/foo, foo/bar). Anything else — most
     * notably javascript: — gets replaced with about:blank so a malicious
     * Markdown source can't ship XSS through a link href.
     */
    private function safeUrl(string $url): string
    {
        $u = trim($url);
        if ($u === '') return 'about:blank';
        if (preg_match('#^(https?://|mailto:|/|\#|[a-z0-9._-]+(/|$))#i', $u)) {
            return $u;
        }
        return 'about:blank';
    }
}
