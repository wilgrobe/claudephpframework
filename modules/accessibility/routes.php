<?php
// modules/accessibility/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\\Accessibility\\Controllers\\AdminA11yController';

$router->get ('/admin/a11y',         "$A@index",  [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/a11y/rescan',  "$A@rescan", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
