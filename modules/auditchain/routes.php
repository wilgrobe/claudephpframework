<?php
// modules/auditchain/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$A = 'Modules\\Auditchain\\Controllers\\AdminAuditChainController';

$router->get ('/admin/audit-chain',                  "$A@index",   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/audit-chain/verify',           "$A@verify",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/audit-chain/breaks',           "$A@breaks",  [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/audit-chain/breaks/{id}/ack',  "$A@ackBreak",[CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
