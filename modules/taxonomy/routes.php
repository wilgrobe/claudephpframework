<?php
// modules/taxonomy/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\Taxonomy\Controllers\TaxonomyAdminController';

// ── Vocabulary CRUD ───────────────────────────────────────────────────────
$router->get ('/admin/taxonomy/sets',               "$A@setsIndex", [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/taxonomy/sets/create',        "$A@setCreate", [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/taxonomy/sets/create',        "$A@setStore",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/taxonomy/sets/{id}',          "$A@setEdit",   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/taxonomy/sets/{id}',          "$A@setUpdate", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/taxonomy/sets/{id}/delete',   "$A@setDelete", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// ── Term management (within a set) ────────────────────────────────────────
$router->post('/admin/taxonomy/sets/{id}/terms',    "$A@termStore",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/taxonomy/terms/{id}/delete',  "$A@termDelete", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
