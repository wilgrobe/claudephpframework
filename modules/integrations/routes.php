<?php
// modules/integrations/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireSuperadmin;

$router->get ('/admin/integrations',      'Modules\Integrations\Controllers\IntegrationController@index',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/integrations/test', 'Modules\Integrations\Controllers\IntegrationController@test',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
