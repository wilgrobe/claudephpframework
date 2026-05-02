<?php
// modules/retention/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\\Retention\\Controllers\\AdminRetentionController';

$router->get ('/admin/retention',                  "$A@index",   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/retention/sync',             "$A@sync",    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/retention/run-all',          "$A@runAll",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/retention/{id}',             "$A@show",    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/retention/{id}/edit',        "$A@edit",    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/retention/{id}/preview',     "$A@preview", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/retention/{id}/run',         "$A@run",     [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
