<?php
// modules/gdpr/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$U = 'Modules\\Gdpr\\Controllers\\AccountDataController';
$A = 'Modules\\Gdpr\\Controllers\\AdminGdprController';

// ── User self-service ────────────────────────────────────────────────
$router->get ('/account/data',                       "$U@index",        [AuthMiddleware::class]);
$router->post('/account/data/export',                "$U@exportStart",  [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get ('/account/data/download/{token}',      "$U@download",     [AuthMiddleware::class]);
$router->post('/account/data/erase',                 "$U@eraseRequest", [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get ('/account/data/erase/cancel/{token}',  "$U@eraseCancel"); // signed token doubles as auth
$router->post('/account/data/restrict',              "$U@restrict",     [CsrfMiddleware::class, AuthMiddleware::class]);

// ── Admin DSAR queue + per-user actions ──────────────────────────────
$router->get ('/admin/gdpr',                                      "$A@index",          [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/gdpr/handlers',                             "$A@handlers",       [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/gdpr/dsar/{id}',                            "$A@dsarShow",       [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/gdpr/dsar/{id}/status',                     "$A@dsarSetStatus",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/gdpr/dsar/{id}/build-export',               "$A@userBuildExport",[CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/gdpr/users/{userId}/erase',                 "$A@userErase",      [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/gdpr/users/{userId}/build-export',          "$A@userBuildExport",[CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/gdpr/users/{userId}/restrict',              "$A@userRestrict",   [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
