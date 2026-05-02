<?php
// modules/accessibility/Services/A11yLintService.php
namespace Modules\Accessibility\Services;

/**
 * Static analyser for HTML / PHP-template accessibility issues.
 *
 * Walks app/Views/ + every modules/{name}/Views/ tree and flags the
 * common WCAG 2.1 AA violations that template-level review can catch:
 *
 *   img-without-alt           <img> with no alt attribute (WCAG 1.1.1)
 *                             — alt="" is fine for decorative images;
 *                             missing attribute is the violation.
 *   input-without-label       <input>/<select>/<textarea> with NO
 *                             label of any kind: not wrapped, no
 *                             `for=` match, no aria-label, no
 *                             aria-labelledby, no aria-describedby,
 *                             no nearby <label> sibling. Usually
 *                             a real bug.
 *   loose-label-association   (warning) Input has a sibling <label>
 *                             without a `for=` attribute. The visual
 *                             association is fine for sighted users
 *                             but the programmatic association isn't
 *                             guaranteed for screen readers. Pragmatic
 *                             — common in real codebases. Add
 *                             `for=` + `id=` for strict compliance.
 *   empty-anchor              <a> with no text content + no aria-label
 *                             (WCAG 2.4.4).
 *   button-without-text       <button> with no text + no aria-label.
 *   multiple-h1               (warning) More than one <h1> per template.
 *   removed-focus-outline     (warning) `outline:none` / `outline:0`
 *                             without a matching `:focus-visible` OR
 *                             `:focus` rule (WCAG 2.4.7).
 *
 * The scanner is deliberately PRAGMATIC:
 *   - PHP `<?= ... ?>` blocks are stripped before regex matching with
 *     a marker that preserves both NEWLINE COUNT and BYTE LENGTH so
 *     line numbers + offset-based snippets stay aligned with the raw
 *     text.
 *   - It's not a full HTML parser. False positives possible on odd
 *     template shapes; false negatives possible on multi-line
 *     attributes spanning >1 line. The point is the cheap-to-run
 *     baseline scan, not a replacement for actual axe-core review.
 */
class A11yLintService
{
    public const SEVERITY_ERROR   = 'error';
    public const SEVERITY_WARNING = 'warning';

    /** How far back to look for a sibling <label> when an input has no for=. */
    private const SIBLING_LABEL_LOOKBACK = 600;

    public function lintAll(?array $roots = null): array
    {
        $roots = $roots ?? $this->defaultRoots();
        $findings = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (substr($file->getFilename(), -4) !== '.php') continue;
                foreach ($this->lintFile($file->getPathname()) as $f) {
                    $findings[] = $f;
                }
            }
        }
        return $findings;
    }

    public function lintFile(string $path): array
    {
        if (!is_readable($path)) return [];
        $raw = (string) file_get_contents($path);
        if ($raw === '') return [];

        // Strip PHP blocks for matching while preserving BYTE LENGTH +
        // NEWLINE COUNT so offsets stay aligned with the original. We
        // pad each match to its original byte length using a literal
        // 'X' fill, but keep newlines intact.
        $stripped = preg_replace_callback('/<\?(?:php|=)?[\s\S]*?\?>/m', function ($m) {
            $orig = $m[0];
            $newlines = substr_count($orig, "\n");
            // Build a replacement with the same byte length:
            //   <newlines>\n   (kept in their original positions)
            //   X-padding for every other char
            $out = '';
            $newlineRunRemaining = $newlines;
            for ($i = 0, $n = strlen($orig); $i < $n; $i++) {
                $out .= $orig[$i] === "\n" ? "\n" : 'X';
            }
            return $out;
        }, $raw) ?? $raw;

        $findings = [];

        // Pre-collect: (a) every label[for] in the file, (b) every
        // <label>...</label> RANGE (for wrapping detection + sibling
        // detection), and (c) every label-text presence within those
        // ranges (so we can tell "wrapping label has text content"
        // from "wrapping label is empty").
        $labelledIds = [];
        if (preg_match_all('/<label\b[^>]*\bfor\s*=\s*["\']([^"\']+)["\']/i', $stripped, $lm)) {
            $labelledIds = array_flip($lm[1]);
        }

        // Wrapping-label ranges: [startOffset, endOffset]
        $labelRanges = [];
        if (preg_match_all('/<label\b[^>]*>([\s\S]*?)<\/label>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $hit) {
                $start = $hit[1];
                $end   = $start + strlen($hit[0]);
                $labelRanges[] = [$start, $end];
            }
        }

        // ── img without alt ─────────────────────────────────────
        if (preg_match_all('/<img\b([^>]*)>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $attrs = $matches[1][$i][0];
                $offset = $hit[1];
                if (!preg_match('/\balt\s*=/i', $attrs)) {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'img-without-alt', self::SEVERITY_ERROR,
                        $hit[0],
                        '<img> tag is missing the alt attribute. Use alt="" for decorative images, alt="meaningful description" for content.'
                    );
                }
            }
        }

        // Pre-compute every "this isn't real HTML" RANGE so we can skip
        // inputs inside it. Two sources of false-positive HTML:
        //   1. <script>...</script> blocks — JS template strings that
        //      build inputs at runtime; HTML scanner shouldn't flag
        //      attribute escape syntax (aria-label=\"Reply\") inside
        //      a JS string literal.
        //   2. inline event-handler attributes (onclick="...",
        //      onload="...", oninput="...", etc.) — same situation:
        //      the markup inside is JS, not HTML. Comments module's
        //      reply-form trick lives in an onclick="" so this case
        //      matters in practice.
        $scriptRanges = [];
        if (preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/i', $stripped, $sm, PREG_OFFSET_CAPTURE)) {
            foreach ($sm[0] as $hit) {
                $scriptRanges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }
        if (preg_match_all('/\bon\w+\s*=\s*"[^"]*"/i', $stripped, $em, PREG_OFFSET_CAPTURE)) {
            foreach ($em[0] as $hit) {
                $scriptRanges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }
        if (preg_match_all("/\\bon\\w+\\s*=\\s*'[^']*'/i", $stripped, $em2, PREG_OFFSET_CAPTURE)) {
            foreach ($em2[0] as $hit) {
                $scriptRanges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }

        // ── input/select/textarea label association ────────────
        if (preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $tag = strtolower($matches[1][$i][0]);
                $attrs = $matches[2][$i][0];
                $offset = $hit[1];

                // Skip if this input lives inside a <script>...</script>
                // block — it's JS source, not a real HTML element.
                $inScript = false;
                foreach ($scriptRanges as [$ss, $se]) {
                    if ($offset >= $ss && $offset < $se) { $inScript = true; break; }
                }
                if ($inScript) continue;

                // Skip non-interactive input types.
                if ($tag === 'input' && preg_match('/\btype\s*=\s*["\']?(hidden|submit|button|reset|image)["\']?/i', $attrs)) {
                    continue;
                }

                // Has explicit ARIA labelling? Done.
                if (preg_match('/\baria-label(?:ledby|by)?\s*=\s*["\'][^"\']+["\']/i', $attrs)) continue;
                if (preg_match('/\baria-describedby\s*=\s*["\'][^"\']+["\']/i', $attrs)) continue;

                // Has id matching a label[for=]? Done.
                if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $idm)
                    && isset($labelledIds[$idm[1]])
                ) {
                    continue;
                }

                // Wrapped by a <label>...</label>? Done.
                $wrapped = false;
                foreach ($labelRanges as [$start, $end]) {
                    if ($offset >= $start && $offset < $end) { $wrapped = true; break; }
                }
                if ($wrapped) continue;

                // Sibling <label> within the lookback window? → loose
                // association (warning), not an error.
                $lookback = max(0, $offset - self::SIBLING_LABEL_LOOKBACK);
                $window   = substr($stripped, $lookback, $offset - $lookback);
                $hasSiblingLabel = (bool) preg_match('/<label\b[^>]*>[\s\S]*?<\/label>/i', $window);

                if ($hasSiblingLabel) {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'loose-label-association', self::SEVERITY_WARNING,
                        $hit[0],
                        "<{$tag}> has a sibling <label> but no programmatic association. Add for=\"x\" + id=\"x\" so screen readers connect them."
                    );
                } else {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'input-without-label', self::SEVERITY_ERROR,
                        $hit[0],
                        "<{$tag}> has no associated <label>, aria-label, or aria-labelledby. Screen-reader users won't know what the field is for."
                    );
                }
            }
        }

        // ── empty anchor (no text + no aria-label) ─────────────
        if (preg_match_all('/<a\b([^>]*)>([\s\S]*?)<\/a>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $attrs = $matches[1][$i][0];
                $body  = $matches[2][$i][0];
                $offset = $hit[1];

                if (preg_match('/\baria-label(?:ledby)?\s*=\s*["\'][^"\']+["\']/i', $attrs)) continue;

                // PHP block (now a run of X+newlines) between the tags
                // = potentially dynamic text → don't flag.
                if (preg_match('/X{3,}/', $body)) continue;

                // <img alt="text"> inside the anchor counts as text.
                if (preg_match('/<img\b[^>]*\balt\s*=\s*["\'][^"\']+["\']/i', $body)) continue;

                $textOnly = trim(strip_tags($body));
                if ($textOnly === '') {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'empty-anchor', self::SEVERITY_ERROR,
                        $hit[0],
                        '<a> has no text content, no aria-label, and no <img alt>. Keyboard + screen-reader users have nothing to read.'
                    );
                }
            }
        }

        // ── button without text ────────────────────────────────
        if (preg_match_all('/<button\b([^>]*)>([\s\S]*?)<\/button>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $attrs = $matches[1][$i][0];
                $body  = $matches[2][$i][0];
                $offset = $hit[1];
                if (preg_match('/\baria-label(?:ledby)?\s*=\s*["\'][^"\']+["\']/i', $attrs)) continue;
                if (preg_match('/X{3,}/', $body)) continue;
                if (preg_match('/<img\b[^>]*\balt\s*=\s*["\'][^"\']+["\']/i', $body)) continue;

                $textOnly = trim(strip_tags($body));
                if ($textOnly === '') {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'button-without-text', self::SEVERITY_ERROR,
                        $hit[0],
                        '<button> has no text + no aria-label. Add visible text or aria-label="..." for icon-only buttons.'
                    );
                }
            }
        }

        // ── multiple h1 in one template (warning) ──────────────
        // Mutually-exclusive PHP branches that each contain an <h1> are a
        // legitimate pattern (auth vs guest renders for the same page);
        // only one branch executes at runtime so it's not a real WCAG
        // violation. We detect that by scanning the ORIGINAL source (not
        // the X-padded $stripped) between successive H1 hits for if/else/
        // elseif/endif markers. If any are present, it's mutex; suppress.
        if (preg_match_all('/<h1\b/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            if (count($matches[0]) > 1) {
                $first  = $matches[0][0][1];
                $second = $matches[0][1][1];
                $between = substr($raw, $first, $second - $first);
                $isMutex = (bool) preg_match(
                    '/<\?php\s+(?:else\b|elseif\b|endif\b)/i',
                    $between
                );
                if (!$isMutex) {
                    $findings[] = $this->finding(
                        $path, $stripped, $second, 'multiple-h1', self::SEVERITY_WARNING,
                        '<h1>',
                        'Template contains more than one <h1>. Use a single h1 per page; demote others to h2/h3 to maintain heading hierarchy (WCAG 1.3.1).'
                    );
                }
            }
        }

        // ── outline:none / outline:0 without :focus-visible OR :focus ──
        if (preg_match_all('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $css    = $matches[1][$i][0];
                $offset = $hit[1];
                if (preg_match('/outline\s*:\s*(?:none|0)\b/i', $css)
                    && !preg_match('/:focus(?:-visible)?\b/i', $css)
                ) {
                    $findings[] = $this->finding(
                        $path, $stripped, $offset, 'removed-focus-outline', self::SEVERITY_WARNING,
                        '<style>...outline:none...</style>',
                        'CSS removes the focus outline without providing a :focus or :focus-visible alternative. Keyboard users lose visibility of where focus is. Add :focus-visible { outline: ... } or restore the default.'
                    );
                }
            }
        }

        return $findings;
    }

    public function defaultRoots(): array
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $roots = [
            $base . '/app/Views',
        ];
        $modulesDir = $base . '/modules';
        if (is_dir($modulesDir)) {
            foreach (scandir($modulesDir) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $viewsPath = $modulesDir . '/' . $entry . '/Views';
                if (is_dir($viewsPath)) $roots[] = $viewsPath;
            }
        }
        return $roots;
    }

    public function summarise(array $findings): array
    {
        $byRule = [];
        $bySeverity = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 0];
        $byFile = [];
        foreach ($findings as $f) {
            $byRule[$f['rule']] = ($byRule[$f['rule']] ?? 0) + 1;
            $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
            $byFile[$f['file']] = ($byFile[$f['file']] ?? 0) + 1;
        }
        arsort($byRule);
        arsort($byFile);
        return [
            'total'       => count($findings),
            'errors'      => $bySeverity[self::SEVERITY_ERROR],
            'warnings'    => $bySeverity[self::SEVERITY_WARNING],
            'by_rule'     => $byRule,
            'top_files'   => array_slice($byFile, 0, 20, true),
        ];
    }

    /**
     * Build the canonical finding row consumers (admin view +
     * artisan a11y:lint --json) expect.
     */
    private function finding(
        string $path,
        string $stripped,
        int    $offset,
        string $rule,
        string $severity,
        string $snippet,
        string $message
    ): array {
        return [
            'file'     => $path,
            'line'     => substr_count(substr($stripped, 0, $offset), "\n") + 1,
            'rule'     => $rule,
            'severity' => $severity,
            'snippet'  => mb_substr($snippet, 0, 200),
            'message'  => $message,
        ];
    }
}
