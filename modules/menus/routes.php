<?php
// modules/menus/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$router->get ('/admin/menus',                    'Modules\Menus\Controllers\MenuController@index',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/menus/create',             'Modules\Menus\Controllers\MenuController@createMenu',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/menus/{id}/items',         'Modules\Menus\Controllers\MenuController@items',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/menus/{id}/items',         'Modules\Menus\Controllers\MenuController@storeItem',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/menus/items/{id}/delete',  'Modules\Menus\Controllers\MenuController@deleteItem',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/menus/items/reorder',      'Modules\Menus\Controllers\MenuController@reorder',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/menus/{id}/save',         'Modules\Menus\Controllers\MenuController@builderSave',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
