<?php
// modules/audit-log-viewer/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\AuditLogViewer\Controllers\AuditLogController';
$T = 'Modules\AuditLogViewer\Controllers\ActivityController';
$W = 'Modules\AuditLogViewer\Controllers\WebhookDashboardController';

// Phase 27 — unified activity timeline.
$router->get('/admin/activity', "$T@index", [AuthMiddleware::class, RequireAdmin::class]);

// Phase 28 — webhook delivery dashboard.
$router->get ('/admin/webhooks',                          "$W@index",  [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/webhooks/{source}/{id}',            "$W@show",   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/webhooks/{source}/{id}/replay',     "$W@replay", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// `.csv` MUST be declared before `/{id}` — declaration-order matching
// means /admin/audit-log.csv would otherwise hit the show route with
// id="audit-log.csv" (well, except {id} matches anything by default;
// this is the more careful pattern even when not strictly needed).
$router->get('/admin/audit-log',          "$A@index",  [AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/audit-log.csv',      "$A@export", [AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/audit-log/{id}',     "$A@show",   [AuthMiddleware::class, RequireAdmin::class]);
