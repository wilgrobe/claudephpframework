<?php
// modules/loginanomaly/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\\Loginanomaly\\Controllers\\AdminAnomalyController';

$router->get ('/admin/security/anomalies',           "$A@index", [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/security/anomalies/{id}/ack',  "$A@ack",   [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
