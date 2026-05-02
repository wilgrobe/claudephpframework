<?php
// modules/featureflags/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\FeatureFlags\Controllers\FeatureFlagAdminController';

$admin     = [AuthMiddleware::class, RequireAdmin::class];
$adminCsrf = [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class];

$router->get ('/admin/feature-flags',                                 "$A@index",         $admin);
$router->get ('/admin/feature-flags/create',                          "$A@createForm",    $admin);
$router->post('/admin/feature-flags',                                 "$A@upsert",        $adminCsrf);
$router->get ('/admin/feature-flags/{key}/edit',                      "$A@edit",          $admin);
$router->post('/admin/feature-flags/{key}/delete',                    "$A@delete",        $adminCsrf);

$router->get ('/admin/feature-flags/{key}/overrides',                 "$A@overrides",     $admin);
$router->post('/admin/feature-flags/{key}/overrides',                 "$A@setOverride",   $adminCsrf);
$router->post('/admin/feature-flags/{key}/overrides/{uid}/clear',     "$A@clearOverride", $adminCsrf);
