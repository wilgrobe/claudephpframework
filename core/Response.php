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

        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $body;
    }

    public function withFlash(string $type, string $message): self
    {
        Session::flash($type, $message);
        return $this;
    }

    public static function redirect(string $url, int $code = 302): self
    {
        $r = new self('', $code, ['Location' => $url]);
        return $r;
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
