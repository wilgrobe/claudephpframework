<?php
// core/Http/ChromeWrapper.php
namespace Core\Http;

use Core\Auth\Auth;
use Core\Response;
use Core\Services\SystemLayoutService;
use Core\View;

/**
 * ChromeWrapper — applies a system layout around a controller's
 * rendered view at response-emit time. The companion class to
 * Response::withLayout() / Response::chrome().
 *
 * The mental model (page-chrome plan, "Architecture"):
 *
 *   1. The controller renders its primary view as a FRAGMENT — the
 *      view sets $pageTitle / $pageStyles etc. but does NOT include
 *      layout/header.php or layout/footer.php itself.
 *
 *   2. The fragment lives inside one or more named "content slots"
 *      ('primary' is the conventional default).
 *
 *   3. A system layout sits AROUND the slots. The layout's grid can
 *      contain BlockRegistry-backed blocks (hero strips, marketing
 *      primitives, sidebar widgets) PLUS one or more
 *      placement_type='content_slot' rows that name the slot a
 *      controller fragment fills.
 *
 *   4. The chrome wrapper assembles the full HTML document by
 *      stitching: layout/header.php  →  page composer partial (with
 *      slot HTML interpolated)  →  layout/footer.php. The captured
 *      $pageTitle from the fragment flows into header.php via the
 *      capture-and-emit pattern.
 *
 *   5. Skip rules (page-chrome plan §"Skip rules"):
 *      - Non-text/html responses (JSON, redirects, file downloads).
 *      - Status outside 2xx.
 *      - XHR / HTMX requests (return fragments without page chrome).
 *      - The named layout doesn't exist or is admin-disabled.
 *      In all these cases the response passes through UNWRAPPED so a
 *      broken layout can never break the page.
 */
class ChromeWrapper
{
    /**
     * Apply chrome to the response. Returns the HTML body to send
     * (either the wrapped document, or the original body unchanged
     * when any skip rule fires).
     *
     * Pure function: doesn't mutate the response, so the kernel can
     * swap the body in or skip the call as it sees fit.
     */
    public static function wrap(Response $response): string
    {
        $config = $response->getChromeConfig();
        if (!$config) return $response->getBody();

        if (!self::shouldWrap($response, $config)) {
            return $response->getBody();
        }

        $sys      = new SystemLayoutService();
        $composer = $sys->get($config['layout']);
        if ($composer === null) {
            // Documented graceful fallback — broken/missing chrome must
            // never break the page.
            return $response->getBody();
        }

        // Resolve slots + capture-and-emit globals.
        $slots    = [];
        $captured = self::emptyCapture();

        if ($config['mode'] === 'multi') {
            // Multi-slot: caller pre-rendered each slot's HTML via
            // View::render(). No global capture happens — each
            // slot's $pageTitle has already been discarded by the
            // pre-render. Callers that need capture-and-emit on
            // multi-slot pages should set $pageTitle on the response
            // explicitly (future enhancement: withTitle()/withMeta()).
            foreach ($config['slots'] as $name => $html) {
                $slots[(string) $name] = (string) $html;
            }
        } else {
            // Single-slot: re-render the source view as a fragment so we
            // can grab the captured globals. If the response was built
            // without going through Response::view (caller built the
            // body by hand and chained ->withLayout), use the raw body
            // and accept empty captures.
            if ($config['view'] !== null) {
                try {
                    $fragment = View::renderFragment($config['view'], $config['data']);
                    $slots[$config['slot']] = $fragment['body'];
                    $captured = $fragment['captured'];
                } catch (\Throwable $e) {
                    // A throw during the fragment render is a real error
                    // — surface it. Don't mask it as "missing layout"
                    // graceful-fallback because the controller's view IS
                    // broken. Letting the exception propagate triggers
                    // the global handler in public/index.php, which
                    // returns a 500.
                    throw $e;
                }
            } else {
                $slots[$config['slot']] = $response->getBody();
            }
        }

        return self::renderChromeDocument($composer, $slots, $captured);
    }

    /**
     * Decide whether wrapping is appropriate for this response. Kept
     * separate from wrap() so tests can assert the skip rules in
     * isolation.
     */
    public static function shouldWrap(Response $response, array $config): bool
    {
        // Status check — only wrap 2xx HTML pages.
        $status = $response->status();
        if ($status < 200 || $status >= 300) return false;

        // Content-Type check — only wrap text/html. We accept charset
        // suffixes ("text/html; charset=UTF-8") via a prefix match.
        $ct = (string) ($response->getHeader('Content-Type') ?? '');
        if ($ct !== '' && !str_starts_with(strtolower($ct), 'text/html')) {
            return false;
        }

        // XHR / HTMX detection — these expect raw fragments.
        if (self::isXhrOrHtmx()) return false;

        return true;
    }

    private static function isXhrOrHtmx(): bool
    {
        $xhr  = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xhr === 'xmlhttprequest') return true;

        $htmx = (string) ($_SERVER['HTTP_HX_REQUEST'] ?? '');
        if (strtolower($htmx) === 'true') return true;

        return false;
    }

    /**
     * Stitch header.php + page composer (with slots) + footer.php into
     * a single HTML document. Captured fragment globals are extracted
     * into the include scope so header.php sees `$pageTitle` etc. as
     * if the inner view had set them inline.
     */
    private static function renderChromeDocument(array $composer, array $slots, array $captured): string
    {
        // Extract captured globals into the include scope. EXTR_SKIP so
        // a future addition to the captured shape can't silently
        // overwrite a header.php local. Keep the keys aligned with
        // what View::renderFragment() returns.
        $scope = array_filter($captured, fn($v) => $v !== null);

        // Composer partial inputs.
        $scope['composer']        = $composer;
        $scope['composerSlots']   = $slots;
        $scope['composerContext'] = ['viewer' => self::viewerForContext()];

        ob_start();
        try {
            extract($scope, EXTR_SKIP);
            include BASE_PATH . '/app/Views/layout/header.php';
            include BASE_PATH . '/app/Views/partials/page_composer.php';
            include BASE_PATH . '/app/Views/layout/footer.php';
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            // Drain the buffer before re-throwing so the half-built
            // document doesn't accidentally leak into a downstream
            // ob_get_*() call from the global error handler.
            if (ob_get_level() > 0) ob_end_clean();
            throw $e;
        }
    }

    /**
     * Best-effort viewer for the composer's `viewer` context key.
     * Falls back to null if Auth isn't bootable (test contexts).
     */
    private static function viewerForContext(): ?array
    {
        try {
            return Auth::getInstance()->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Initial empty captured-globals shape. Kept in sync with
     * View::renderFragment()'s return shape so consumers can rely on
     * the keys existing.
     */
    private static function emptyCapture(): array
    {
        return [
            'pageTitle'      => null,
            'pageStyles'     => null,
            'pageScripts'    => null,
            'pageMeta'       => null,
            'seoTitle'       => null,
            'seoDescription' => null,
            'seoKeywords'    => null,
            'seoOgImage'     => null,
            'canonical'      => null,
            'bodyClass'      => null,
        ];
    }
}
