<?php
// modules/import-export/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$I = 'Modules\ImportExport\Controllers\ImportExportAdminController';

$admin     = [AuthMiddleware::class, RequireAdmin::class];
$adminCsrf = [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class];

$router->get ('/admin/import',               "$I@index",       $admin);
$router->post('/admin/import/upload',        "$I@upload",      $adminCsrf);
$router->get ('/admin/import/{id}',          "$I@show",        $admin);
$router->post('/admin/import/{id}/map',      "$I@saveMapping", $adminCsrf);
$router->post('/admin/import/{id}/run',      "$I@run",         $adminCsrf);

$router->get ('/admin/export/{type}.csv',    "$I@export",      $admin);
