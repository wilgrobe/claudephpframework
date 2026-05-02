<?php
// modules/pages/routes.php
/**
 * Pages module routes. Loaded by ModuleRegistry before routes/web.php.
 * $router, $container are in scope (injected by the loader).
 *
 * @var \Core\Router\Router $router
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

// Admin CRUD. Middleware stack mirrors the pre-module setup exactly so BC
// behavior is preserved (AuthMiddleware + RequireAdmin on reads; CSRF added
// on writes).
$router->get ('/admin/pages',              'Modules\Pages\Controllers\PageController@index',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/pages/create',       'Modules\Pages\Controllers\PageController@create',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/pages/create',       'Modules\Pages\Controllers\PageController@store',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/pages/{id}/edit',    'Modules\Pages\Controllers\PageController@edit',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/pages/{id}/edit',    'Modules\Pages\Controllers\PageController@update',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/pages/{id}/delete',  'Modules\Pages\Controllers\PageController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// Page composer (Batch 2 of the content-blocks rollout). Layout + placements
// are edited on a separate page so we don't need to cram a second form into
// /admin/pages/{id}/edit.
$router->get ('/admin/pages/{id}/layout',         'Modules\Pages\Controllers\PageController@editLayout',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/pages/{id}/layout',         'Modules\Pages\Controllers\PageController@saveLayout',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/pages/{id}/layout/delete',  'Modules\Pages\Controllers\PageController@deleteLayout',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// Legacy /page/{slug} 301 — kept during the module port. The public /{slug}
// catch-all still lives in routes/web.php because it interacts with SEO
// redirects; moving it here would entangle the module with the SEO subsystem.
$router->get('/page/{slug}', function (\Core\Request $req) {
    return \Core\Response::redirect('/' . $req->param(0), 301);
});
