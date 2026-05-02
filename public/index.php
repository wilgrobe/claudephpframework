<?php
// public/index.php

// Output buffering MUST be first. Any stray notice/warning/deprecation that
// leaks to stdout before session_start() silently breaks Set-Cookie, which
// in turn breaks CSRF validation on the next request. Buffering defers all
// output until headers are committed.
ob_start();

define('BASE_PATH', dirname(__DIR__));

// Load .env
if (file_exists(BASE_PATH . '/.env')) {
    foreach (file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\n\r\"'");
        putenv(trim($k) . '=' . trim($v, " \t\n\r\"'"));
    }
}

// Raise memory floor — 128M default is too tight for framework bootstrap
// Only raises, never lowers (respects a higher php.ini value)
if (function_exists('ini_parse_quantity')) {
    if (ini_parse_quantity(ini_get('memory_limit')) < 256 * 1024 * 1024) {
        ini_set('memory_limit', '256M');
    }
} else {
    ini_set('memory_limit', '256M');
}

// Autoload (helpers.php is loaded via Composer's "files" autoload)
require BASE_PATH . '/vendor/autoload.php';

// Session config
$cfg = config('app.session');
ini_set('session.cookie_lifetime', $cfg['lifetime'] * 60);
ini_set('session.cookie_httponly', $cfg['httponly'] ? '1' : '0');
ini_set('session.cookie_secure',   $cfg['secure']   ? '1' : '0');
ini_set('session.cookie_samesite', $cfg['samesite'] ?? 'Lax');
session_name($cfg['name'] ?? 'phpfw_session');

// Session storage backend. 'db' swaps in the DbSessionHandler so session
// payloads persist to the `sessions` table instead of the filesystem;
// essential for multi-node deploys and enables the admin active-sessions
// surface. Falling back to 'file' is the PHP default — keeps zero
// configuration working on fresh installs.
if (($cfg['driver'] ?? 'file') === 'db') {
    // Must set handler BEFORE session_start so open() participates
    // in the startup handshake. The 'true' second arg (register_shutdown)
    // installs session_write_close on shutdown — important for handlers
    // that hold DB connections (PHP's default would wait until object
    // destruction ordering, which can bite under exception unwinding).
    session_set_save_handler(
        new \Core\Session\DbSessionHandler(\Core\Database\Database::getInstance()),
        true
    );
}

// Start the session BEFORE any output so the Set-Cookie for PHPSESSID
// always goes out cleanly. csrf_token() will no-op on its own session_start
// call because session_status() will already be PHP_SESSION_ACTIVE.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
// X-XSS-Protection is deprecated in modern browsers; CSP is the correct mechanism
// header('X-XSS-Protection: 1; mode=block');
//
// img-src: 'self', data URIs, and https. In non-production we also allow
// http: so locally-hosted asset servers (MinIO on http://localhost:9000,
// smtp4dev, etc.) can serve images into the app. Production stays
// HTTPS-only for image loads.
$imgSrc = "'self' data: https:" . (config('app.env') !== 'production' ? ' http:' : '');
// frame-src: hosts that may be loaded into an <iframe>. Default is 'self'
// only via default-src; we explicitly allow YouTube's no-cookie embed
// domain and Vimeo's player so the siteblocks.video_embed block works.
// Add additional providers (Loom, Wistia, etc.) here if/when their
// embeds are introduced — keep the list narrow rather than allowing
// `https:` blanket so a compromised page can't phone home to anything.
$frameSrc = "'self' https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com";
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src $imgSrc; frame-src $frameSrc; form-action 'self'; base-uri 'self';");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// SECURITY: HSTS — instruct browsers to only connect via HTTPS.
// Sent even without checking HTTPS flag so that proxies/load balancers
// that terminate TLS before PHP still propagate it correctly.
// Remove the 'preload' directive until you have registered with hstspreload.org.
if (config('app.env') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Timezone
date_default_timezone_set(config('app.timezone', 'UTC'));

// Sentry: parse DSN once up front so the exception handlers below can
// forward events without paying the parse cost per request. Does nothing
// when SENTRY_DSN is empty.
\Core\Services\SentryService::init();

// SECURITY: Error handling — never expose stack traces in production.
// In development (APP_DEBUG=true) exceptions surface normally for debugging.
// In production they log to storage/logs and return a generic 500 page.
if (!config('app.debug', false)) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', ($_ENV['LOG_PATH'] ?? BASE_PATH . '/storage/logs') . '/php_error.log');

    set_exception_handler(function (\Throwable $e) {
        // Forward to Sentry first (no-op when disabled). Wrapped so a
        // Sentry transport failure can never mask the original exception.
        try { \Core\Services\SentryService::captureException($e); } catch (\Throwable $_) {}

        error_log(sprintf(
            "[%s] %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>'
            . '<h1>Something went wrong</h1>'
            . '<p>An unexpected error occurred. Please try again or contact support.</p>'
            . '</body></html>';
    });

    set_error_handler(function (int $severity, string $message, string $file, int $line) {
        if (error_reporting() & $severity) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
        return false;
    });
} else {
    // Dev: surface errors but keep deprecations & strict notices out of the
    // HTTP response body. Deprecation lines bleeding into output break
    // Set-Cookie headers (and therefore CSRF) even when the page otherwise
    // renders fine. They still go to the log.
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', ($_ENV['LOG_PATH'] ?? BASE_PATH . '/storage/logs') . '/php_error.log');
    // Hide noisy non-fatal classes from the response body (still logged).
    // Note: E_STRICT was removed in PHP 8.4 — don't reference it or you
    // trip the very deprecation warning this line is meant to suppress.
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}

// Build the service container and register core singletons / driver bindings.
// Returns a fully-populated Core\Container\Container; also stashes it on
// Container::global() so the app() helper can reach it. Module discovery
// (registering providers, view namespaces, module autoloader) happens inside
// bootstrap.php too.
$container = require BASE_PATH . '/core/bootstrap.php';

// Dispatch
use Core\Router\Router;
use Core\Request;

/** @var Router $router */
$router  = $container->get(Router::class);
/** @var Request $request */
$request = $container->get(Request::class);

// Module route files are loaded BEFORE routes/web.php so the web file can
// override a module-provided route if it ever needs to (the Router matches
// in registration order; specifically-registered routes win against later
// catch-alls, same as before).
if ($container->has(\Core\Module\ModuleRegistry::class)) {
    /** @var \Core\Module\ModuleRegistry $modules */
    $modules = $container->get(\Core\Module\ModuleRegistry::class);
    $modules->loadRoutes($router);
}

// API routes load before web routes so /api/v1/* takes priority over any
// web catch-all that might otherwise swallow it (notably the public
// /{slug} page handler at the bottom of routes/web.php).
if (is_file(BASE_PATH . '/routes/api.php')) {
    require BASE_PATH . '/routes/api.php';
}

require BASE_PATH . '/routes/web.php';

// Final boot phase — event listeners, view composers, anything that needs
// the fully-constructed router.
if (isset($modules)) {
    $modules->boot($router);
}

// Maintenance-mode gate. When /admin/settings/access has the site flag on,
// everyone except superadmins sees a 503 page. The login surface always
// passes through so admins can sign in to clear the flag — this covers
// /login, /logout, the dev shortcut, every /auth/2fa/* variant (challenge,
// resend, recovery), and OAuth callbacks so an admin using social login
// can still complete the round-trip. Kept inline here (not middleware) so
// a bug in the router's matching can't accidentally leak the site while
// maintenance is on.
$path = '/' . ltrim(parse_url($request->path(), PHP_URL_PATH) ?? '', '/');
$maintenanceExact = [
    '/login',
    '/logout',
    '/dev/login-as',   // dev shortcut — gated to non-production in the controller
];
$maintenancePrefixes = [
    '/auth/2fa/',   // /auth/2fa/challenge, /auth/2fa/resend, /auth/2fa/recovery
    '/auth/oauth/', // OAuth redirect + callback
];
$allowedDuringMaintenance =
       in_array($path, $maintenanceExact, true)
    || array_filter($maintenancePrefixes, fn($p) => str_starts_with($path, $p));
if (setting('maintenance_mode', false)
    && !\Core\Auth\Auth::getInstance()->isSuperAdmin()
    && !$allowedDuringMaintenance
) {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=UTF-8');
    $siteName = htmlspecialchars((string) setting('site_name', 'This site'), ENT_QUOTES | ENT_HTML5);
    echo '<!DOCTYPE html><html><head><title>Down for maintenance</title>'
       . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#111}'
       . '.box{text-align:center;padding:3rem;max-width:480px}.box h1{font-size:2.25rem;margin:0 0 .5rem 0}'
       . '.box p{color:#6c757d;margin:.5rem 0}.box a{color:#4f46e5}</style></head><body>'
       . '<div class="box"><h1>' . $siteName . ' is down for maintenance</h1>'
       . '<p>We\'re making some updates. Please check back shortly.</p>'
       . '<p><a href="/login">Admin sign-in</a></p></div></body></html>';
    exit;
}

// Admin IP allowlist gate. Cheap fast path: only fires for /admin/*
// when the security module is installed AND the toggle is on. Lives
// next to the maintenance + policy gates so the layered gating is
// readable in one place.
if (class_exists(\Modules\Security\Middleware\AdminIpAllowlistMiddleware::class)) {
    $blocked = \Modules\Security\Middleware\AdminIpAllowlistMiddleware::isBlocked($request);
    if ($blocked instanceof \Core\Response) {
        $blocked->send();
        exit;
    }
}

// Sliding session inactivity timeout. Reads sessions.last_activity
// (the prior request's timestamp at this point) and signs out users
// past the configured idle threshold. 0 / unset = disabled.
if (class_exists(\Modules\Security\Middleware\SessionIdleTimeoutMiddleware::class)) {
    $expired = \Modules\Security\Middleware\SessionIdleTimeoutMiddleware::isExpired($request);
    if ($expired instanceof \Core\Response) {
        $expired->send();
        exit;
    }
}

// PII-access logging. Records a sealed pii.viewed audit row when an
// authenticated admin GETs a PII-bearing surface. Doesn't block —
// fires-and-forgets the audit insert. Throttled in-process to one
// row per (admin, path) per 30s. Setting toggle controls enable/disable.
if (class_exists(\Modules\Security\Middleware\PiiAccessLoggerMiddleware::class)) {
    \Modules\Security\Middleware\PiiAccessLoggerMiddleware::maybeLog($request);
}

// Policy-acceptance gate. After the maintenance gate (so admins can
// always reach login), but before dispatch (so a user with unaccepted
// required policies can't reach any other URL until they accept).
// Static call avoids needing a class instance pre-route. Kept inline
// rather than middleware so the allow-list of bypass routes is
// immediately visible right next to the maintenance gate it mirrors.
if (class_exists(\Modules\Policies\Middleware\RequirePolicyAcceptance::class)) {
    $blocked = \Modules\Policies\Middleware\RequirePolicyAcceptance::isBlocked($request);
    if ($blocked instanceof \Core\Response) {
        $blocked->send();
        exit;
    }
}

$response = $router->dispatch($request);
$response->send();

// The response is fully dispatched by this point. No post-response work
// currently runs here — the scheduled_tasks entry for retry-messages
// (seeded 2026-04-22) drains failed emails/SMS every minute via
// `php artisan schedule:run`, which replaces the previous opportunistic
// 1-in-10-requests drain that used to live at the bottom of this file.
// If you add new post-response work later, wrap it the same way
// MessageRetryService was — fastcgi_finish_request() first, then the work.
