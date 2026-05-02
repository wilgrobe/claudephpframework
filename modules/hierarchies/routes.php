<?php
// modules/hierarchies/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\Hierarchies\Controllers\HierarchiesAdminController';

$admin     = [AuthMiddleware::class, RequireAdmin::class];
$adminCsrf = [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class];

// ── Hierarchy list + CRUD ────────────────────────────────────────────────
$router->get ('/admin/hierarchies',                        "$A@index",            $admin);
$router->get ('/admin/hierarchies/create',                 "$A@createForm",       $admin);
$router->post('/admin/hierarchies/create',                 "$A@store",            $adminCsrf);
$router->get ('/admin/hierarchies/{slug}',                 "$A@show",             $admin);
$router->post('/admin/hierarchies/{id}/delete',            "$A@deleteHierarchy",  $adminCsrf);

// ── Node ops ─────────────────────────────────────────────────────────────
$router->post('/admin/hierarchies/{id}/nodes',             "$A@addNode",          $adminCsrf);
$router->post('/admin/hierarchies/nodes/{id}/update',      "$A@updateNode",       $adminCsrf);
$router->post('/admin/hierarchies/nodes/{id}/delete',      "$A@deleteNode",       $adminCsrf);
$router->post('/admin/hierarchies/nodes/{id}/move',        "$A@moveNode",         $adminCsrf);
$router->post('/admin/hierarchies/nodes/reorder',          "$A@reorder",          $adminCsrf);
