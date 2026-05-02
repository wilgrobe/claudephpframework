<?php
// modules/api-keys/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

$K = 'Modules\ApiKeys\Controllers\ApiKeyController';

// ── User-facing management ────────────────────────────────────────────────
$router->get ('/account/api-keys',                "$K@index",  [AuthMiddleware::class]);
$router->post('/account/api-keys',                "$K@mint",   [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/account/api-keys/{id}/revoke',    "$K@revoke", [CsrfMiddleware::class, AuthMiddleware::class]);

// Example /api route — a minimal "who am I" endpoint for clients to
// verify their token. Apps add their own /api/* routes using
// ApiAuthMiddleware. An app registering its own /api/users route would
// copy this pattern.
$router->get('/api/me', static function (\Core\Request $r) {
    return \Core\Response::apiJson([
        'user_id' => $_SERVER['X_API_AUTH_USER_ID'] ?? null,
        'key_id'  => $_SERVER['X_API_AUTH_KEY_ID']  ?? null,
        'scopes'  => $_SERVER['X_API_AUTH_SCOPES']  ?? [],
    ]);
}, [\Modules\ApiKeys\Middleware\ApiAuthMiddleware::class]);
