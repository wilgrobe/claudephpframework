<?php
// core/View.php
namespace Core;

/**
 * View renderer with namespaces, layouts, sections, and components.
 *
 * Basic render:
 *   View::render('auth.login', ['email' => $email])
 *
 * Namespaced views (from modules):
 *   View::render('blog::post.show', [...])
 *
 * Layouts (in the child view):
 *   <?php View::extend('layouts.app'); ?>
 *   <?php View::section('title', 'Profile'); ?>
 *
 *   <?php View::section('content'); ?>
 *     <h1>Hello, <?= e($user['name']) ?></h1>
 *   <?php View::endSection(); ?>
 *
 * In the layout:
 *   <title><?= View::yield('title', 'Default Title') ?></title>
 *   <main><?= View::yield('content') ?></main>
 *
 * Components (render a partial with props):
 *   <?= View::component('alerts.success', ['message' => 'Saved!']) ?>
 *
 * Or using the global helper:
 *   <?= component('alerts.success', ['message' => 'Saved!']) ?>
 */
class View
{
    private static string $viewsPath = '';

    /** @var array<string, string> namespace => absolute directory */
    private static array $namespaces = [];

    // ── Layout state (per render, not thread-safe — PHP is single-threaded) ──

    /** Name of the parent layout the current render is extending (or null). */
    private static ?string $extending = null;

    /** @var array<string, string> captured sections, keyed by name */
    private static array $sections = [];

    /** Active block-form capture stack (names currently ob-buffering). */
    private static array $sectionStack = [];

    // ── Render ────────────────────────────────────────────────────────────────

    /**
     * Render a view file to a string.
     *
     * If the view calls View::extend('layouts.x'), this function captures
     * all sections it declares, then renders the layout with those sections
     * resolvable via View::yield().
     *
     * Nested render() calls (partials) don't interfere with the outer
     * render's layout state because we save + restore it around each call.
     */
    public static function render(string $view, array $data = []): string
    {
        $path = self::resolvePath($view);
        if (!file_exists($path)) {
            throw new \RuntimeException("View not found: [$view] at [$path]");
        }

        // Save outer layout state so nested partials / components don't
        // clobber it. We restore on the way out regardless of whether the
        // child view throws.
        $outerExtending    = self::$extending;
        $outerSections     = self::$sections;
        $outerSectionStack = self::$sectionStack;

        self::$extending    = null;
        self::$sections     = [];
        self::$sectionStack = [];

        try {
            extract($data, EXTR_SKIP);
            ob_start();
            include $path;
            $content = ob_get_clean();

            // If the child declared extend(), render the layout now — and
            // expose the child's raw output as the default 'content' section
            // if the child didn't explicitly section('content', ...) it.
            if (self::$extending !== null) {
                if (!isset(self::$sections['content'])) {
                    self::$sections['content'] = $content;
                }
                $layoutName = self::$extending;
                // Preserve sections across the layout render — yield() reads
                // from self::$sections, which we leave in place for the
                // nested render call.
                $preservedSections = self::$sections;
                $outerExtendingPrior = self::$extending;
                self::$extending = null; // layouts don't re-extend
                try {
                    // Re-enter render() for the parent. It will save/restore
                    // its own state, but we need the sections visible during
                    // its include — we stash them after reset.
                    $content = self::renderLayout($layoutName, $data, $preservedSections);
                } finally {
                    self::$extending = $outerExtendingPrior;
                }
            }
            return $content;
        } finally {
            self::$extending    = $outerExtending;
            self::$sections     = $outerSections;
            self::$sectionStack = $outerSectionStack;
        }
    }

    /**
     * Internal: render the parent layout with the child's sections exposed.
     * Separate from render() because we need to bypass the state-reset.
     */
    private static function renderLayout(string $layout, array $data, array $sections): string
    {
        $path = self::resolvePath($layout);
        if (!file_exists($path)) {
            throw new \RuntimeException("Layout not found: [$layout] at [$path]");
        }

        // Swap our section state to the child's captures. Save outer so the
        // outermost render() call's finally clause still restores correctly.
        $savedSections = self::$sections;
        self::$sections = $sections;
        try {
            extract($data, EXTR_SKIP);
            ob_start();
            include $path;
            return ob_get_clean();
        } finally {
            self::$sections = $savedSections;
        }
    }

    // ── Layout directives (called from inside views) ─────────────────────────

    /**
     * Declare this view extends a layout. Call once near the top of the view.
     * The layout is rendered after the child finishes executing.
     */
    public static function extend(string $layout): void
    {
        self::$extending = $layout;
    }

    /**
     * Define a named section. Two forms:
     *
     *   View::section('title', 'My Page')           — one-line value
     *   View::section('content');                    — start a block
     *     ... HTML output ...
     *   View::endSection();                          — end the block
     *
     * Duplicate sections overwrite the previous — last-writer-wins.
     */
    public static function section(string $name, ?string $content = null): void
    {
        if ($content !== null) {
            self::$sections[$name] = $content;
            return;
        }
        self::$sectionStack[] = $name;
        ob_start();
    }

    /** End a block-form section started with section($name). */
    public static function endSection(): void
    {
        if (empty(self::$sectionStack)) {
            throw new \LogicException('View::endSection() called without a matching section().');
        }
        $name = array_pop(self::$sectionStack);
        self::$sections[$name] = ob_get_clean();
    }

    /**
     * Output a section's captured content. Called from layouts.
     * Missing sections emit $default (empty string if not supplied).
     */
    public static function yield(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    // ── Components ───────────────────────────────────────────────────────────

    /**
     * Render a component (a partial under the "components" namespace or
     * under views/components/). Accepts a full view name like
     * "components.alerts.success" OR a short name like "alerts.success"
     * which gets the "components." prefix added.
     */
    public static function component(string $name, array $props = []): string
    {
        // Short name → expand to components.{name}; caller can still pass
        // a fully-qualified view like 'blog::components.thing' if they want
        // a module-provided component.
        if (!str_starts_with($name, 'components.') && !str_contains($name, '::')) {
            $name = 'components.' . $name;
        }
        return self::render($name, $props);
    }

    // ── Legacy: partials ─────────────────────────────────────────────────────

    /**
     * Render a partial — same as render() but named for the pattern of
     * "a view including another view". View::partial is preserved for the
     * many existing views that already use it.
     */
    public static function partial(string $view, array $data = []): string
    {
        return self::render($view, $data);
    }

    /**
     * Render a "fragment" view — one that's expected to participate in
     * page-chrome wrapping (Response::withLayout / Response::chrome) and
     * therefore does NOT include layout/header.php + layout/footer.php
     * itself. The chrome wrapper provides those.
     *
     * Returns:
     *   [
     *     'body'     => '...captured HTML...',
     *     'captured' => [
     *       'pageTitle'      => string|null,
     *       'pageStyles'     => array|null,
     *       'pageScripts'    => array|null,
     *       'pageMeta'       => array|null,
     *       'seoTitle'       => string|null,
     *       'seoDescription' => string|null,
     *       'seoKeywords'    => string|null,
     *       'seoOgImage'     => string|null,
     *       'canonical'      => string|null,
     *       'bodyClass'      => string|null,
     *     ],
     *   ]
     *
     * The capture-and-emit pattern (page-chrome plan §"Inner view → outer
     * template variable hand-off"): the fragment view sets these globals
     * during execution; we snapshot them here and the chrome wrapper
     * exposes them as locals when rendering the outer header.
     *
     * Pre-existing extend()/section() state is preserved across the call
     * the same way render() handles it. A fragment view that calls
     * extend() throws — wrapping a chromed view in another layout would
     * double-wrap the response and is a configuration error.
     */
    public static function renderFragment(string $view, array $data = []): array
    {
        $path = self::resolvePath($view);
        if (!file_exists($path)) {
            throw new \RuntimeException("View not found: [$view] at [$path]");
        }

        // Save outer layout state — same dance render() does so a parent
        // render call's section captures don't get clobbered.
        $outerExtending    = self::$extending;
        $outerSections     = self::$sections;
        $outerSectionStack = self::$sectionStack;

        self::$extending    = null;
        self::$sections     = [];
        self::$sectionStack = [];

        try {
            extract($data, EXTR_SKIP);
            ob_start();
            include $path;
            $body = ob_get_clean();

            if (self::$extending !== null) {
                throw new \LogicException(
                    "View [$view] called View::extend() but is being rendered as a chrome fragment. "
                    . "Chromed views must NOT extend a layout — the chrome wrapper provides the outer template."
                );
            }

            // Snapshot the well-known globals the inner view may have set.
            // Using `?? null` keeps the captured array shape stable so the
            // chrome wrapper can rely on the keys existing.
            $captured = [
                'pageTitle'      => isset($pageTitle)      ? (string) $pageTitle      : null,
                'pageStyles'     => isset($pageStyles)     && is_array($pageStyles)  ? $pageStyles  : null,
                'pageScripts'    => isset($pageScripts)    && is_array($pageScripts) ? $pageScripts : null,
                'pageMeta'       => isset($pageMeta)       && is_array($pageMeta)    ? $pageMeta    : null,
                'seoTitle'       => isset($seoTitle)       ? (string) $seoTitle       : null,
                'seoDescription' => isset($seoDescription) ? (string) $seoDescription : null,
                'seoKeywords'    => isset($seoKeywords)    ? (string) $seoKeywords    : null,
                'seoOgImage'     => isset($seoOgImage)     ? (string) $seoOgImage     : null,
                'canonical'      => isset($canonical)      ? (string) $canonical      : null,
                'bodyClass'      => isset($bodyClass)      ? (string) $bodyClass      : null,
            ];

            return ['body' => $body, 'captured' => $captured];
        } finally {
            self::$extending    = $outerExtending;
            self::$sections     = $outerSections;
            self::$sectionStack = $outerSectionStack;
        }
    }

    // ── Path resolution (unchanged from prior) ───────────────────────────────

    /**
     * Resolve a view name to a filesystem path.
     *
     * Forms:
     *   "auth.login"        → <app/Views>/auth/login.php
     *   "blog::post.show"   → <modules/blog/Views>/post/show.php
     *
     * The "::" prefix selects a registered namespace (usually a module).
     * Without the prefix, the default viewsPath is used.
     */
    private static function resolvePath(string $view): string
    {
        // Split namespace if present
        $namespace = null;
        if (str_contains($view, '::')) {
            [$namespace, $view] = explode('::', $view, 2);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $namespace)) {
                throw new \InvalidArgumentException("Invalid view namespace: [$namespace]");
            }
            if (!isset(self::$namespaces[$namespace])) {
                throw new \RuntimeException("Unknown view namespace: [$namespace]");
            }
        }

        // SECURITY: Validate view name — only allow alphanumeric, dots, and underscores.
        // This prevents path traversal via '../' or absolute path injection.
        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $view)) {
            throw new \InvalidArgumentException("Invalid view name: [$view]");
        }
        // Reject double-dots specifically (belt-and-suspenders)
        if (str_contains($view, '..')) {
            throw new \InvalidArgumentException("View name must not contain '..': [$view]");
        }

        $base = $namespace !== null
            ? self::$namespaces[$namespace]
            : (self::$viewsPath ?: (BASE_PATH . '/app/Views'));
        $relative = str_replace('.', '/', $view) . '.php';
        $resolved = realpath($base . '/' . $relative);

        // Assert resolved path is actually inside the views directory
        $basePath = realpath($base);
        if ($resolved === false || $basePath === false || !str_starts_with($resolved, $basePath . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("View path traversal detected: [$view]");
        }

        return $resolved;
    }

    /** Set the base directory views are resolved against. */
    public static function setViewsPath(string $path): void
    {
        self::$viewsPath = $path;
    }

    /**
     * Register a view namespace → directory mapping. Used by modules to
     * expose their Views/ directory so callers can render `blog::post.show`.
     */
    public static function addNamespace(string $namespace, string $path): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $namespace)) {
            throw new \InvalidArgumentException("Invalid view namespace: [$namespace]");
        }
        self::$namespaces[$namespace] = rtrim($path, DIRECTORY_SEPARATOR);
    }
}
