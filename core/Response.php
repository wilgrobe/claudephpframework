<?php
// core/Response.php
namespace Core;

use Core\Http\ChromeWrapper;

class Response
{
    /**
     * Chrome configuration set by withLayout() / chrome(). Null when
     * the response should send its body unchanged.
     *
     * Single-slot shape (withLayout):
     *   ['mode'=>'single', 'layout'=>string, 'slot'=>string,
     *    'view'=>?string, 'data'=>array]
     *   - view + data are recorded by Response::view() so the chrome
     *     wrapper can re-render the inner view as a fragment to
     *     capture $pageTitle / $pageStyles / etc. for the outer
     *     header.php.
     *
     * Multi-slot shape (chrome):
     *   ['mode'=>'multi',  'layout'=>string, 'slots'=>array<string,string>]
     *   - slots are pre-rendered HTML strings supplied by the caller.
     */
    private ?array $chromeConfig = null;

    /**
     * View name + data captured by Response::view(). Used by
     * ChromeWrapper to re-render as a fragment when chrome is
     * applied via withLayout() — without this, $pageTitle etc.
     * would be lost (they were set as locals during the original
     * render and discarded once the rendered string was returned).
     */
    private ?string $sourceView = null;
    private array   $sourceData = [];

    public function __construct(
        private string $body    = '',
        private int    $status  = 200,
        private array  $headers = []
    ) {}

    public function send(): void
    {
        $body = $this->chromeConfig !== null
            ? ChromeWrapper::wrap($this)
            : $this->body;

        // First-party analytics: inject the tracker before </body> on HTML
        // responses when the analytics module is installed. Single chokepoint
        // so it covers EVERY page shell (chrome / guest / 3-pane / module
        // views). Same-origin script → default CSP allows it. Idempotent +
        // fully guarded, so it's a no-op on non-HTML, framework-only installs,
        // or sites without the module.
        $body = self::injectAnalyticsTracker($body, (string) ($this->headers['Content-Type'] ?? ''));
        $body = self::injectPolicyClauses($body, (string) ($this->headers['Content-Type'] ?? ''));

        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        // HTTP HEAD must return identical status + headers to GET but no
        // body. The router maps HEAD onto the matching GET route, so the
        // handler runs and sets headers/status; we just withhold the body.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $body;
        }
    }

    /**
     * Inject the first-party analytics SDK before </body> for HTML pages when
     * the `analytics` module is installed. Builder feature; safe no-op when the
     * module/helpers are absent (framework-only) or the response isn't HTML.
     */
    private static function injectAnalyticsTracker(string $body, string $contentType): string
    {
        if ($body === '' || stripos($contentType, 'text/html') === false) return $body;
        if (stripos($body, '/analytics/sdk.js') !== false) return $body;   // already present
        $pos = strripos($body, '</body>');
        if ($pos === false) return $body;
        if (!function_exists('module_active') || !module_active('analytics')) return $body;
        if (function_exists('setting') && setting('analytics_tracking_enabled', true) === false) return $body;
        return substr($body, 0, $pos)
             . '<script src="/analytics/sdk.js" defer></script>'
             . substr($body, $pos);
    }

    /**
     * Fallback: ensure the /privacy + /terms pages carry installed modules'
     * policySections() clauses even on page shells that bypass the page render
     * closure (e.g. the 3-pane unified-chrome layout). No-op when the closure
     * already injected them (the `policy-module-clauses` marker is present), or
     * on non-HTML / non-policy URLs / framework-only installs.
     */
    private static function injectPolicyClauses(string $body, string $contentType): string
    {
        if ($body === '' || stripos($contentType, 'text/html') === false) return $body;
        if (stripos($body, 'policy-module-clauses') !== false) return $body;   // already injected
        $path = strtolower(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $kind = ['/privacy' => 'privacy', '/terms' => 'tos', '/tos' => 'tos'][$path] ?? null;
        if ($kind === null) return $body;
        $pos = strripos($body, '</body>');
        if ($pos === false) return $body;
        if (!function_exists('module_active') || !class_exists(\Core\Module\ModuleRegistry::class)) return $body;
        try {
            $reg = \Core\Container\Container::global()->get(\Core\Module\ModuleRegistry::class);
            $extra = '';
            foreach ($reg->all() as $prov) {
                if (!$prov instanceof \Core\Module\ModuleProvider) continue;
                $secs = $prov->policySections();
                if (!is_array($secs) || empty($secs[$kind]['body_html'])) continue;
                $h = (string) ($secs[$kind]['heading'] ?? '');
                if ($h !== '') $extra .= '<h2>' . htmlspecialchars($h, ENT_QUOTES) . '</h2>';
                $extra .= (string) $secs[$kind]['body_html'];
            }
        } catch (\Throwable) { return $body; }
        if ($extra === '') return $body;
        $block = '<div class="policy-module-clauses" style="max-width:760px;margin:1.5rem auto;padding:0 1rem">'
               . \Core\Validation\Validator::sanitizeHtml($extra) . '</div>';
        return substr($body, 0, $pos) . $block . substr($body, $pos);
    }

    public function withFlash(string $type, string $message): self
    {
        Session::flash($type, $message);
        return $this;
    }

    /**
     * Redirect to a local OR external URL. Pre-43.197b this was the only
     * redirect builder, leading to caller-side open-redirect bugs at
     * sites that passed user-controlled `$back` / Referer values
     * through. Phase 43.197b H4 keeps this method as a back-compat
     * surface — callers should now prefer the explicit-intent
     * `Response::redirectLocal` (path-only) or `Response::redirectExternal`
     * (allowlisted full URL) so the intent is auditable.
     *
     * Defense-in-depth: strip CR/LF from the URL before it reaches the
     * Location header. PHP's header() rejects CRLF since 5.1.2 but
     * stripping here means callers passing user-tainted strings get a
     * cleaned URL rather than a runtime error.
     */
    public static function redirect(string $url, int $code = 302): self
    {
        $url = preg_replace('/[\r\n]+/', '', $url) ?? '';
        $r = new self('', $code, ['Location' => $url]);
        return $r;
    }

    /**
     * Phase 43.197b H4 — explicit local-path redirect. Refuses URLs
     * that aren't a single forward-slash-prefixed path (no scheme, no
     * host, no protocol-relative `//evil.com`). When the URL doesn't
     * meet that bar, falls back to `/`. Use this for any redirect
     * where the destination should be inside this app.
     */
    public static function redirectLocal(string $localPath, int $code = 302): self
    {
        $localPath = preg_replace('/[\r\n]+/', '', $localPath) ?? '';
        // Must start with exactly one `/` and not `//` (protocol-relative).
        if ($localPath === '' || $localPath[0] !== '/' || (isset($localPath[1]) && $localPath[1] === '/')) {
            $localPath = '/';
        }
        return new self('', $code, ['Location' => $localPath]);
    }

    /**
     * Phase 43.197b H4 — explicit external redirect. Caller declares
     * intent to leave the app. Validates the URL against an allowlist
     * derived from APP_URL host + the optional REDIRECT_ALLOWLIST_HOSTS
     * env (comma-separated). Off-list URLs fall back to `/` with
     * error_log so the operator notices.
     */
    public static function redirectExternal(string $url, int $code = 302): self
    {
        $url = preg_replace('/[\r\n]+/', '', $url) ?? '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            error_log('[Response::redirectExternal] rejected (no host): ' . $url);
            return new self('', $code, ['Location' => '/']);
        }
        $allowed = [];
        $appUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?? '');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') $allowed[] = strtolower($appHost);
        $extra = (string) ($_ENV['REDIRECT_ALLOWLIST_HOSTS'] ?? getenv('REDIRECT_ALLOWLIST_HOSTS') ?? '');
        foreach (explode(',', $extra) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '') $allowed[] = $h;
        }
        if (!in_array(strtolower($host), $allowed, true)) {
            error_log('[Response::redirectExternal] off-allowlist host: ' . $host);
            return new self('', $code, ['Location' => '/']);
        }
        return new self('', $code, ['Location' => $url]);
    }

    public static function view(string $view, array $data = []): self
    {
        $content = View::render($view, $data);
        $r = new self($content, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            // Prevent sensitive page content from being cached in browser history or proxies
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
        // Stash the source so a later ->withLayout() call can re-render
        // the view as a fragment (capture-and-emit). No cost when
        // chrome is never applied — we just hold a string reference.
        $r->sourceView = $view;
        $r->sourceData = $data;
        return $r;
    }

    /**
     * Phase 43.197a C4 — central download response builder with
     * Content-Disposition CRLF sanitization. Pre-fix every download
     * site hand-rolled `str_replace('"', '', $name)` which strips
     * quotes but NOT CRLF. A filename like
     * `evil.zip"\r\nSet-Cookie: x=y\r\n\r\n<html>...` injects
     * arbitrary headers + response body via Response::send's raw
     * `header("$name: $value")` loop. PHP's bare `header()` rejects
     * CRLF since 5.1.2 but the audit identified 7+ sites where
     * user-controlled filenames reach Response constructor without
     * intermediate sanitization.
     *
     * This helper:
     *   1. Strips control chars (0x00-0x1f, 0x7f) + raw quotes +
     *      backslashes + slashes from the filename (preserves the
     *      base name; never lets traversal sneak through).
     *   2. Trims + caps at 200 chars (filesystems vary; 200 is safe).
     *   3. Falls back to $fallback when result is empty.
     *   4. Emits both `filename="..."` (legacy ASCII fallback) and
     *      `filename*=UTF-8''<percent-encoded>` (RFC 6266) so unicode
     *      filenames survive the round-trip.
     *
     * @param string $body         The response body (may be large; caller
     *                             handles streaming via direct echo+exit
     *                             when memory matters per Phase 43.192b).
     * @param string $filename     User-controlled candidate filename
     * @param string $contentType  MIME type; defaults to application/octet-stream
     * @param string $fallback     Used when filename sanitizes to empty
     */
    public static function file(string $body, string $filename, string $contentType = 'application/octet-stream', string $fallback = 'download'): self
    {
        $safe = self::safeFilename($filename, $fallback);
        $asciiSafe = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe) ?? $fallback;
        if ($asciiSafe === '') $asciiSafe = $fallback;
        $cd = sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $asciiSafe,
            rawurlencode($safe),
        );
        return new self($body, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => $cd,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Phase 43.197a C4 — defensive filename sanitization. Strips
     * everything that could either inject headers (CRLF, control
     * chars) or escape the basename (slashes, backslashes, quotes).
     * Preserves user-friendly chars (letters, digits, dot, dash,
     * underscore, space, common unicode).
     */
    public static function safeFilename(string $name, string $fallback = 'download'): string
    {
        // Strip control chars (incl CR/LF/TAB) + raw double-quote +
        // backslash + forward-slash. Keep unicode + spaces.
        $clean = preg_replace('/[\x00-\x1f\x7f"\\\\\/]/', '', $name) ?? '';
        $clean = trim($clean);
        if ($clean === '' || $clean === '.' || $clean === '..') return $fallback;
        return mb_substr($clean, 0, 200);
    }

    /**
     * Like view() but allows caching for public, non-sensitive pages.
     */
    public static function publicView(string $view, array $data = [], int $maxAge = 300): self
    {
        $content = View::render($view, $data);
        $r = new self($content, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => "public, max-age=$maxAge",
        ]);
        $r->sourceView = $view;
        $r->sourceData = $data;
        return $r;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        // SECURITY: Prefix JSON responses with )]}', to prevent XSSI (Cross-Site Script
        // Inclusion). JavaScript consumers must strip the first 6 characters before
        // calling JSON.parse(), or use the safeJson() helper below.
        // This is standard practice (used by Angular, Google APIs, etc.).
        $body = ")]}',\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            $body,
            $status,
            [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Pure JSON response for public APIs. No XSSI prefix — server-to-server
     * and mobile clients expect a valid JSON document starting with `{`.
     *
     * Use this for /api/v1/* endpoints. Use json() for browser-consumed
     * same-origin responses that could otherwise be stolen via <script> tag
     * inclusion by a malicious cross-origin page.
     */
    public static function apiJson(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            $body,
            $status,
            [
                'Content-Type'           => 'application/json',
                'Cache-Control'          => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    // ── Chrome API (page-chrome Batch A) ──────────────────────────────────────

    /**
     * Mark this response for chrome wrapping by the named system layout.
     * The current body becomes the named slot's content (default
     * `primary`); a controller's view should be a FRAGMENT (no
     * include of layout/header.php or layout/footer.php) for the
     * chrome wrapper to produce a valid document.
     *
     * If the layout doesn't exist (fresh install, admin-deleted, no
     * migration yet), the response sends UNWRAPPED — broken chrome
     * must never break the page. See ChromeWrapper for the full
     * skip-rule list.
     *
     * Idempotent: calling this twice replaces the prior chrome config.
     * Calling chrome() afterward replaces it with multi-slot mode.
     */
    public function withLayout(string $name, string $slot = 'primary'): self
    {
        $slot = trim($slot);
        if ($slot === '') $slot = 'primary';

        $this->chromeConfig = [
            'mode'   => 'single',
            'layout' => $name,
            'slot'   => $slot,
            'view'   => $this->sourceView,
            'data'   => $this->sourceData,
        ];
        return $this;
    }

    /**
     * Multi-slot variant of withLayout(). Each slot maps a slot_name to
     * pre-rendered HTML; slots in the layout but missing here render
     * empty, slots passed but not referenced by the layout are
     * silently dropped.
     *
     *   return Response::chrome('messaging.thread', [
     *       'primary' => View::render('messaging::public.thread_main', $data),
     *       'sidebar' => View::render('messaging::public.thread_sidebar', $data),
     *   ]);
     *
     * The body is empty until wrap-time (no inner view has been
     * rendered yet). Headers default to the same Content-Type +
     * cache-control set Response::view() emits.
     */
    public static function chrome(string $name, array $slots): self
    {
        $r = new self('', 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
        // Coerce keys to strings + values to strings. A non-string slot
        // value (e.g. someone passing an object) would silently become
        // a useless cast; better to take a defined hit at config time.
        $clean = [];
        foreach ($slots as $k => $v) {
            $clean[(string) $k] = is_string($v) ? $v : (string) $v;
        }
        $r->chromeConfig = [
            'mode'   => 'multi',
            'layout' => $name,
            'slots'  => $clean,
        ];
        return $r;
    }

    /**
     * Return a snapshot of the current chrome config (or null when
     * the response isn't chromed). ChromeWrapper consumes this; tests
     * can use it to assert configuration without forcing a send().
     *
     * @return array<string,mixed>|null
     */
    public function getChromeConfig(): ?array
    {
        return $this->chromeConfig;
    }

    /** Read the raw body — used by ChromeWrapper as the slot content fallback. */
    public function getBody(): string
    {
        return $this->body;
    }

    /** Case-insensitive header lookup — returns the first matching value or null. */
    public function getHeader(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $k => $v) {
            if (strtolower((string) $k) === $lower) return (string) $v;
        }
        return null;
    }

    // ── Mutators ──────────────────────────────────────────────────────────────

    /** Fluent header setter — overwrites any prior value for $name. */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        // Case-insensitive match so callers don't have to know the canonical
        // capitalization we used internally. HTTP header names are case-insensitive.
        $lower = strtolower($name);
        foreach ($this->headers as $k => $_) {
            if (strtolower((string) $k) === $lower) return true;
        }
        return false;
    }

    public function status(): int { return $this->status; }
}
