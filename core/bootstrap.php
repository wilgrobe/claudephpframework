<?php
// core/bootstrap.php
/**
 * Builds the framework container and returns it.
 *
 * Call once from public/index.php and artisan, before any routing or
 * command dispatch. Existing singleton-style classes (Database::getInstance(),
 * Auth::getInstance(), CacheService::instance(), SentryService::init()) still
 * work — the container just binds them in so new code can type-hint.
 *
 *   $container = require BASE_PATH . '/core/bootstrap.php';
 *
 * The container is also stashed in Container::global() so the app() helper
 * can reach it without threading it everywhere.
 */

use Core\Container\Container;

$container = new Container();
Container::setGlobal($container);

// ── Core HTTP primitives ──────────────────────────────────────────────────
$container->singleton(\Core\Router\Router::class);
$container->singleton(\Core\Request::class, fn() => \Core\Request::capture());

// ── Existing singleton-style services: bind their instances ──────────────
// Each of these already enforces singleton via ::getInstance() / ::instance();
// we pass the same instance through the container so type-hinted resolution
// returns the exact same object (no double-construction, no config drift).
$container->instance(\Core\Database\Database::class, \Core\Database\Database::getInstance());
$container->instance(\Core\Auth\Auth::class,         \Core\Auth\Auth::getInstance());
$container->instance(\Core\Services\CacheService::class, \Core\Services\CacheService::instance());

// ── Per-request services (transient) ──────────────────────────────────────
// These are cheap to build and don't hold state across calls in a single
// request. Transient is fine; make them singletons later if a profiler shows
// constructor cost.
$container->bind(\Core\Services\MailService::class);
$container->bind(\Core\Services\SmsService::class);
$container->bind(\Core\Services\NotificationService::class);
// SettingsService holds a per-request bulk-loaded cache of the settings
// table; binding as a singleton means the whole request shares one cache
// instead of three+ independent SettingsService instances each running
// their own warmup query.
$container->singleton(\Core\Services\SettingsService::class);
// ThemeService resolves all its tokens via SettingsService. Singleton +
// constructor injection means renderFontLinks() + renderOverrideStyle()
// share both the resolved-token memo and the warmed settings cache.
$container->singleton(\Core\Services\ThemeService::class, function ($c) {
    return new \Core\Services\ThemeService($c->get(\Core\Services\SettingsService::class));
});
// MenuService is touched by every page (header + footer menus); singleton
// shares the request-scoped getMenu() memo across calls.
$container->singleton(\Core\Services\MenuService::class);
$container->bind(\Core\Services\FileUploadService::class);
$container->bind(\Core\Services\MessageRetryService::class);
$container->bind(\Core\Services\SessionCleanupService::class);
$container->bind(\Core\Services\SearchService::class);
$container->bind(\Core\Services\SearchIndexer::class);
$container->bind(\Core\Services\PaymentsService::class);
$container->bind(\Core\Services\WebhookService::class);
$container->bind(\Core\Services\AnalyticsService::class);
$container->bind(\Core\Services\CdnService::class);
$container->bind(\Core\Services\MediaCdnService::class);
$container->bind(\Core\Services\VideoService::class);
$container->bind(\Core\Services\CaptchaService::class);

// ── Queue + Scheduling ────────────────────────────────────────────────────
// DatabaseQueue is a thin wrapper over Database::insert/update, so it's safe
// to build once per container lifetime. Worker and Scheduler compose it.
$container->singleton(\Core\Queue\DatabaseQueue::class);
$container->singleton(\Core\Queue\Worker::class);
$container->singleton(\Core\Scheduling\Scheduler::class);

// ── Driver interfaces → concrete bindings ─────────────────────────────────
// Interfaces live under Core\Contracts\. Concrete choice is driven by config
// (payments.gateway, mail.driver, search.engine, etc.) so swapping providers
// is a config edit, not a code change.
$serviceBindings = BASE_PATH . '/config/services.php';
if (file_exists($serviceBindings)) {
    (require $serviceBindings)($container);
}

// ── Modules ───────────────────────────────────────────────────────────────
// Registry scans every root in config('modules.paths') for module.php
// providers and registers them. Safe to call even when no modules exist
// yet — it just no-ops.
//
// The paths list usually resolves to:
//   - <core>/modules/                     ← always present
//   - <premium>/modules/                  ← when MODULE_PREMIUM_PATH or the
//                                           sibling claudephpframeworkpremium
//                                           checkout exists, otherwise omitted
//
// Premium modules pass through ModuleProvider::tier() + the
// EntitlementCheck contract during dependency resolution, so that the
// hosted builder can gate them per-tenant without changing discovery.
if (class_exists(\Core\Module\ModuleRegistry::class)) {
    $container->singleton(\Core\Module\ModuleRegistry::class);

    // Default the EntitlementCheck binding to AlwaysGrant. The builder
    // (or any custom installation) can override this in
    // config/services.php to plug in a real licence check.
    if (interface_exists(\Core\Module\EntitlementCheck::class)
        && !$container->has(\Core\Module\EntitlementCheck::class)) {
        $container->singleton(
            \Core\Module\EntitlementCheck::class,
            fn() => new \Core\Module\AlwaysGrantEntitlement()
        );
    }

    $modulesConfig = require BASE_PATH . '/config/modules.php';
    $paths = is_array($modulesConfig['paths'] ?? null) ? $modulesConfig['paths'] : [BASE_PATH . '/modules'];
    $container->get(\Core\Module\ModuleRegistry::class)->discoverMany($paths);
}

// BlockRegistry is the aggregated catalogue of every block declared by an
// active module. Resolved lazily — first call to ModuleRegistry::blockRegistry()
// triggers the dependency check and aggregation. Bound here so any controller
// or service can `$container->get(BlockRegistry::class)`.
if (class_exists(\Core\Module\BlockRegistry::class)
    && class_exists(\Core\Module\ModuleRegistry::class)) {
    $container->singleton(\Core\Module\BlockRegistry::class, function ($c) {
        return $c->get(\Core\Module\ModuleRegistry::class)->blockRegistry();
    });
}

return $container;
