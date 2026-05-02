<?php
// core/helpers.php

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $configs = [];
        [$file, $dotKey] = array_pad(explode('.', $key, 2), 2, null);
        if (!isset($configs[$file])) {
            $path = BASE_PATH . "/config/$file.php";
            $configs[$file] = file_exists($path) ? require $path : [];
        }
        if ($dotKey === null) return $configs[$file] ?? $default;
        $keys = explode('.', $dotKey);
        $val  = $configs[$file];
        foreach ($keys as $k) {
            if (!is_array($val) || !array_key_exists($k, $val)) return $default;
            $val = $val[$k];
        }
        return $val;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = csrf_token();
        return "<input type=\"hidden\" name=\"_token\" value=\"" . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . "\">";
    }
}

if (!function_exists('e')) {
    /** HTML-escape a value safely */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return rtrim(config('app.url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    /**
     * Resolve a URL. Two forms:
     *
     *   route('/profile')                         → absolute URL to /profile
     *   route('users.show', ['id' => 42])         → absolute URL, named-route
     *                                              resolution via the Router
     *
     * Heuristic: a leading '/' means "path literal" (the pre-refactor
     * semantics, preserved for BC). Anything else is treated as a route name
     * and resolved via Router::urlFor(). A name that doesn't match any
     * registered route throws InvalidArgumentException so bad route() calls
     * surface loudly rather than producing silent 404-bait URLs.
     */
    function route(string $target, array $params = []): string
    {
        // Path-literal form (BC): '/profile', '/admin/users'
        if (str_starts_with($target, '/')) {
            $url = rtrim(config('app.url'), '/') . $target;
            if ($params) $url .= '?' . http_build_query($params);
            return $url;
        }

        // Named-route form: resolve via the global Router binding.
        $c      = \Core\Container\Container::global();
        $router = $c->has(\Core\Router\Router::class) ? $c->get(\Core\Router\Router::class) : null;
        if (!$router) {
            throw new \RuntimeException("route('$target') called before the Router was registered in the container.");
        }
        $path = $router->urlFor($target, $params);
        return rtrim(config('app.url'), '/') . $path;
    }
}

if (!function_exists('auth')) {
    function auth(): \Core\Auth\Auth
    {
        return \Core\Auth\Auth::getInstance();
    }
}

if (!function_exists('app')) {
    /**
     * Fetch the global service container, or resolve a binding from it.
     *
     *   app()                       -> Container
     *   app(MailDriver::class)      -> MailDriver instance
     *   app(UserController::class)  -> new UserController (autowired)
     *
     * Resolution rules are those of Core\Container\Container::make() —
     * explicit bindings win, contextual bindings next, then type-hinted
     * reflection, then default values.
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $c = \Core\Container\Container::global();
        if ($abstract === null) return $c;
        return $c->make($abstract, $parameters);
    }
}

if (!function_exists('component')) {
    /**
     * Render a UI component (a reusable partial under views/components/).
     * Short name is auto-prefixed: component('alerts.success') renders
     * views/components/alerts/success.php.
     *
     *   <?= component('alerts.success', ['message' => 'Saved!']) ?>
     *
     * For a module-provided component, pass the full namespaced name:
     *   <?= component('blog::components.post_card', ['post' => $p]) ?>
     */
    function component(string $name, array $props = []): string
    {
        return \Core\View::component($name, $props);
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        $old = \Core\Session::get('old', []);
        return e($old[$key] ?? $default);
    }
}

if (!function_exists('session_errors')) {
    function session_errors(): array
    {
        return \Core\Session::get('errors', []);
    }
}

if (!function_exists('has_error')) {
    function has_error(string $field): bool
    {
        $errors = \Core\Session::get('errors', []);
        return !empty($errors[$field]);
    }
}

if (!function_exists('error_message')) {
    function error_message(string $field): string
    {
        $errors = \Core\Session::get('errors', []);
        if (!empty($errors[$field])) {
            return '<span class="form-error">' . e($errors[$field][0]) . '</span>';
        }
        return '';
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        // Resolve through the container so this helper shares the same
        // bulk-warmed SettingsService that ThemeService + setting()
        // callers in DI-aware code use - no duplicated per-request cache.
        // Falls back to a private static instance only if the container
        // hasn't been booted yet (e.g. during early bootstrap).
        static $fallback = null;
        try {
            $c = \Core\Container\Container::global();
            return $c->get(\Core\Services\SettingsService::class)->get($key, $default, 'site');
        } catch (\Throwable) {
            $fallback ??= new \Core\Services\SettingsService();
            return $fallback->get($key, $default, 'site');
        }
    }
}

if (!function_exists('consent_allowed')) {
    /**
     * Whether the current visitor has consented to a given cookie category.
     * `necessary` is always true. The other three (preferences, analytics,
     * marketing) require an explicit accept via the cookie banner.
     *
     * Use this to gate any tracking script, marketing pixel, or persistent
     * preference cookie that isn't strictly required for the site to work:
     *
     *     <?php if (consent_allowed('analytics')): ?>
     *         <script src="https://plausible.io/js/script.js"></script>
     *     <?php endif; ?>
     *
     * Defaults to false (deny) when the cookieconsent module isn't
     * installed — fail-closed is the GDPR-safe choice.
     */
    function consent_allowed(string $category): bool
    {
        if ($category === 'necessary') return true;
        if (!class_exists(\Modules\Cookieconsent\Services\CookieConsentService::class)) {
            return false;
        }
        try {
            return (new \Modules\Cookieconsent\Services\CookieConsentService())->isAllowed($category);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('ccpa_opted_out')) {
    /**
     * Has the current viewer opted out of "sale" / "sharing" of their
     * personal information per CCPA / CPRA?
     *
     * Use this to gate any code path that would constitute a "sale"
     * or "sharing" under California law — third-party ad pixels,
     * marketing data exchanges with partners, audience-segment exports.
     * Most analytics + transactional features are unaffected.
     *
     *     <?php if (!ccpa_opted_out()): ?>
     *         <script src="https://example-ad-network.com/pixel.js"></script>
     *     <?php endif; ?>
     *
     * Defaults to false (allow) when the ccpa module isn't installed.
     * The CCPA module's CcpaService check honors: signed-in user flag,
     * device cookie, email match, AND the live Sec-GPC: 1 browser header.
     */
    function ccpa_opted_out(?int $userId = null, ?string $email = null): bool
    {
        if (!class_exists(\Modules\Ccpa\Services\CcpaService::class)) return false;
        try {
            if ($userId === null) {
                $auth = \Core\Auth\Auth::getInstance();
                if ($auth->check()) $userId = (int) $auth->id();
            }
            return (new \Modules\Ccpa\Services\CcpaService())->isOptedOut($userId, $email);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('menu')) {
    function menu(string $location): array
    {
        // Container-resolved so multiple menu() calls in one request share
        // the same MenuService (and its request-scoped getMenu memo).
        static $fallback = null;
        try {
            $svc = \Core\Container\Container::global()->get(\Core\Services\MenuService::class);
        } catch (\Throwable) {
            $fallback ??= new \Core\Services\MenuService();
            $svc = $fallback;
        }
        return $svc->getMenu($location, $_SERVER['REQUEST_URI'] ?? '/');
    }
}

if (!function_exists('integration_config')) {
    /**
     * Read third-party integration config from the environment.
     * Wraps Core\Services\IntegrationConfig::config() so services and
     * views can look up credentials without importing the class.
     *
     * See .env.example and IntegrationConfig::DEFS for supported types.
     */
    function integration_config(string $type): array
    {
        return \Core\Services\IntegrationConfig::config($type);
    }
}

if (!function_exists('integration_enabled')) {
    function integration_enabled(string $type): bool
    {
        return \Core\Services\IntegrationConfig::enabled($type);
    }
}

if (!function_exists('captcha_widget')) {
    /** Render the configured CAPTCHA widget. Empty string when disabled. */
    function captcha_widget(): string
    {
        return \Core\Services\CaptchaService::widget();
    }
}

if (!function_exists('captcha_verify')) {
    /** Verify a CAPTCHA token. Returns true when CAPTCHA is disabled. */
    function captcha_verify(?string $token, ?string $remoteIp = null): bool
    {
        return \Core\Services\CaptchaService::verify($token, $remoteIp);
    }
}

if (!function_exists('cache')) {
    /**
     * Access the shared cache. Three calling conventions:
     *   cache()                      -> returns the CacheService instance
     *   cache('key')                 -> read
     *   cache('key', $value, $ttl=0) -> write (ttl in seconds, 0 = forever)
     *
     * Redis when CACHE_DRIVER=redis and reachable, otherwise a
     * filesystem cache under STORAGE_PATH/cache/. Driver choice is
     * transparent to callers — read/write semantics are identical.
     */
    function cache(?string $key = null, mixed $value = null, int $ttl = 0): mixed
    {
        $svc = \Core\Services\CacheService::instance();
        if ($key === null)         return $svc;
        if (func_num_args() === 1) return $svc->get($key);
        $svc->set($key, $value, $ttl);
        return $value;
    }
}

if (!function_exists('asset')) {
    /**
     * Build a URL for a static asset (CSS, JS, image) that respects the
     * optional ASSET_URL env var. When ASSET_URL is set (e.g. a CDN in
     * front of /public), all asset URLs get rewritten through it;
     * otherwise they stay on APP_URL-relative paths like "/assets/…".
     *
     *   asset('/assets/css/app.css')
     *     -> "/assets/css/app.css"                         (no CDN)
     *     -> "https://cdn.example.com/assets/css/app.css"  (with ASSET_URL)
     */
    function asset(string $path): string
    {
        // Absolute URLs pass through untouched.
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = trim((string) ($_ENV['ASSET_URL'] ?? ''));
        $path = '/' . ltrim($path, '/');
        return $base !== '' ? rtrim($base, '/') . $path : $path;
    }
}

if (!function_exists('can_create_group')) {
    /**
     * Whether the current user is allowed to create a new group under the
     * site's group-policy settings. Mirrors GroupController's server-side
     * enforcement so views can hide "Create Group" affordances rather
     * than surfacing a button that the server will immediately reject.
     *
     * Guests get false (they'd be redirected to /login on click anyway),
     * admins and superadmin-mode users bypass the toggle, and everyone
     * else honors the allow_group_creation setting.
     */
    function can_create_group(): bool
    {
        $auth = \Core\Auth\Auth::getInstance();
        if ($auth->guest()) return false;
        if ($auth->isSuperadminModeOn()) return true;
        if ($auth->hasRole(['super-admin', 'admin'])) return true;
        return (bool) setting('allow_group_creation', true);
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $text): string
    {
        return \Core\SEO\SeoManager::slugify($text);
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        echo $message ?: "HTTP $code";
        exit;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $v) {
            echo '<pre>' . htmlspecialchars(print_r($v, true)) . '</pre>';
        }
        exit;
    }
}

if (!function_exists('toggle_switch')) {
    /**
     * Render a toggle-switch boolean control matching the superadmin-mode
     * toggle style in the header bar. Returns HTML; callers echo it.
     *
     *   <?= toggle_switch('footer_enabled', !empty($values['footer_enabled'])) ?>
     *
     * CSS classes (.toggle-switch / .toggle-slider) live in
     * app/Views/layout/header.php — rendered on every page that includes
     * the layout, so any admin view can use this helper without loading
     * extra stylesheets.
     *
     * When unchecked, the checkbox isn't submitted at all — this is the
     * default HTML behaviour. Settings handlers that read
     * `!empty($request->post('key'))` interpret absence as "off" correctly.
     */
    function toggle_switch(string $name, bool $checked, string $value = '1', ?string $title = null, bool $submitOnChange = false): string
    {
        $nameEsc  = htmlspecialchars($name,  ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $valueEsc = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $checkAttr = $checked ? 'checked' : '';
        $titleAttr = $title !== null
            ? ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            : '';
        $onchange  = $submitOnChange ? ' onchange="this.form.submit()"' : '';

        return '<label class="toggle-switch"' . $titleAttr . '>'
             . '<input type="checkbox" name="' . $nameEsc . '" value="' . $valueEsc . '" ' . $checkAttr . $onchange . '>'
             . '<span class="toggle-slider"></span>'
             . '</label>';
    }
}

if (!function_exists('module_active')) {
    /**
     * Is the named module currently active (discovered + dependencies satisfied)?
     *
     * Used by layout templates to gate links to module-owned routes so a
     * disabled module doesn't leave dead links in the chrome (e.g. the
     * notifications bell icon pointing at /notifications when the module
     * was skipped at boot for a missing dep).
     *
     * Returns false if:
     *   - the module isn't discovered at all (folder missing or no module.php)
     *   - the module is discovered but failed dependency resolution
     *   - the framework hasn't booted the registry yet (CLI / early bootstrap)
     *
     * The result is memoised per request — the registry's `all()` and
     * `skippedModules()` are cheap, but each layout render touches every
     * module-link helper many times, so caching keeps the cost flat.
     */
    function module_active(string $name): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $reg = \Core\Container\Container::global()->get(\Core\Module\ModuleRegistry::class);
                // A module is "active" only if it survived BOTH gates:
                //   1. dependency resolution (skippedModules)
                //   2. the admin-disable lifecycle (adminDisabledModules)
                // Without the second filter, layout-chrome links keep
                // showing up after an admin disables a module via
                // /admin/modules, leading to clicks that 404.
                $skipped       = array_keys($reg->skippedModules());
                $adminDisabled = array_keys($reg->adminDisabledModules());
                foreach ($reg->all() as $modName => $_) {
                    $cache[$modName] = !in_array($modName, $skipped, true)
                                    && !in_array($modName, $adminDisabled, true);
                }
            } catch (\Throwable) {
                // Container missing or registry not bound — treat every
                // call as "inactive" to avoid showing potentially-broken
                // links. CLI tools that need to bypass this can boot the
                // registry first, or just not call the helper.
                $cache = [];
            }
        }
        return !empty($cache[$name]);
    }
}
