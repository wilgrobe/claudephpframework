<?php
// modules/audit-log-viewer/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\AuditLogViewer\Controllers\AuditLogController';

$router->get('/admin/audit-log',          "$A@index", [AuthMiddleware::class, RequireAdmin::class]);
$router->get('/admin/audit-log/{id}',     "$A@show",  [AuthMiddleware::class, RequireAdmin::class]);
