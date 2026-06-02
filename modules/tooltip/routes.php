<?php
// modules/tooltip/routes.php
/** @var \Core\Router\Router $router */
/** @var \Core\Container\Container $container */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;
use Core\Module\SubmoduleRegistry;

$A = 'Modules\Tooltip\Controllers\TooltipAdminController';
$C = 'Modules\Tooltip\Controllers\CategoryController';
$O = 'Modules\Tooltip\Controllers\OverrideController';
$N = 'Modules\Tooltip\Controllers\AnalyticsController';
$P = 'Modules\Tooltip\Controllers\PublicTooltipController';

$sub = $container->has(SubmoduleRegistry::class)
    ? $container->get(SubmoduleRegistry::class)
    : null;
$on = fn (string $k): bool => $sub === null ? true : $sub->isEnabledForCurrent('tooltip', $k);

$auth      = [AuthMiddleware::class];
$admin     = [AuthMiddleware::class, RequireAdmin::class];
$adminCsrf = [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class];

// ── Public JSON API ─────────────────────────────────────────────────────────
$router->get('/api/tooltips/{slug}', "$P@show", []);
if ($on('analytics')) {
    $router->post('/api/tooltips/{slug}/track', "$P@track", [CsrfMiddleware::class]);
}

// ── Admin: literal segments BEFORE the bare {id} catch-alls ──────────────────
$router->get ('/admin/tooltips',            "$A@index",  $admin);
$router->get ('/admin/tooltips/create',     "$A@create", $admin);
$router->post('/admin/tooltips',            "$A@store",  $adminCsrf);

$router->get ('/admin/tooltips/categories',           "$C@index",  $admin);
$router->post('/admin/tooltips/categories',           "$C@store",  $adminCsrf);
$router->post('/admin/tooltips/categories/{id}',      "$C@update", $adminCsrf);
$router->post('/admin/tooltips/categories/{id}/delete', "$C@delete", $adminCsrf);

if ($on('analytics')) {
    $router->get('/admin/tooltips/analytics', "$N@index", $admin);
}
if ($on('per-page-overrides')) {
    $router->post('/admin/tooltips/overrides/{oid}/delete', "$O@delete", $adminCsrf);
}

// ── Per-tooltip actions (specific suffixes before the bare {id}) ─────────────
$router->post('/admin/tooltips/{id}/delete',        "$A@delete",       $adminCsrf);
$router->post('/admin/tooltips/{id}/toggle-active', "$A@toggleActive", $adminCsrf);
if ($on('per-page-overrides')) {
    $router->get ('/admin/tooltips/{id}/overrides', "$O@index", $admin);
    $router->post('/admin/tooltips/{id}/overrides', "$O@store", $adminCsrf);
}

// ── Bare {id} (edit + update) LAST ──────────────────────────────────────────
$router->get ('/admin/tooltips/{id}', "$A@show",   $admin);
$router->post('/admin/tooltips/{id}', "$A@update", $adminCsrf);
