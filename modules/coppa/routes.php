<?php
// modules/coppa/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\\Coppa\\Controllers\\AdminCoppaController';

$router->get('/admin/coppa', "$A@index", [AuthMiddleware::class, RequireAdmin::class]);
