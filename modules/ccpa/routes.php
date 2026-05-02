<?php
// modules/ccpa/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$P = 'Modules\\Ccpa\\Controllers\\CcpaController';
$A = 'Modules\\Ccpa\\Controllers\\AdminCcpaController';

// Public — works for guests + signed-in users
$router->get ('/do-not-sell',           "$P@show");
$router->post('/do-not-sell',           "$P@submit",   [CsrfMiddleware::class]);
$router->post('/do-not-sell/withdraw',  "$P@withdraw", [CsrfMiddleware::class]);

// Admin
$router->get ('/admin/ccpa',  "$A@index", [AuthMiddleware::class, RequireAdmin::class]);
