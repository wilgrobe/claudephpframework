<?php
// modules/policies/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$P = 'Modules\\Policies\\Controllers\\PolicyController';
$A = 'Modules\\Policies\\Controllers\\AdminPolicyController';

// ── Public + auth user ────────────────────────────────────────────────
// /policies/{slug} is public so a guest at /register can read what
// they're about to accept. /policies/accept is auth-only.
$router->get ('/policies/accept',                    "$P@acceptForm",   [AuthMiddleware::class]);
$router->post('/policies/accept',                    "$P@acceptSubmit", [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get ('/account/policies',                   "$P@accountHistory", [AuthMiddleware::class]);
$router->get ('/policies/{slug}/v/{versionId}',      "$P@showVersion");
$router->get ('/policies/{slug}',                    "$P@show");

// ── Admin ─────────────────────────────────────────────────────────────
$router->get ('/admin/policies',                              "$A@index",      [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/policies/kinds',                        "$A@createKind", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/policies/kinds/{kindId}/delete',        "$A@deleteKind", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/policies/{kindId}',                     "$A@show",       [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/policies/{kindId}/v/{versionId}',       "$A@showVersion",[AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/policies/{kindId}/source',              "$A@setSource",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/policies/{kindId}/bump',                "$A@bumpVersion",[CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
