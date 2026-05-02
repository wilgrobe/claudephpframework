<?php
// modules/cookieconsent/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

$C = 'Modules\\Cookieconsent\\Controllers\\CookieConsentController';

// ── Public ────────────────────────────────────────────────────────────
// CSRF-protected even though they're public — the banner forms ship with
// a token. Prevents drive-by POSTs from a third-party site that flips a
// signed-in user's preferences.
$router->post('/cookie-consent',          "$C@save",     [CsrfMiddleware::class]);
$router->post('/cookie-consent/withdraw', "$C@withdraw", [CsrfMiddleware::class]);

// ── Admin ─────────────────────────────────────────────────────────────
// RequireAdmin gates the page; the controller's canManage() additionally
// requires the cookieconsent.manage permission (or superadmin).
$router->get ('/admin/cookie-consent',
    "$C@adminIndex",
    [AuthMiddleware::class, RequireAdmin::class]);

$router->post('/admin/cookie-consent',
    "$C@adminSave",
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

$router->post('/admin/cookie-consent/bump-version',
    "$C@bumpVersion",
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
