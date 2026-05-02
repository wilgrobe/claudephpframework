<?php
// modules/profile/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

$router->get ('/profile',       'Modules\Profile\Controllers\ProfileController@show',
    [AuthMiddleware::class]);
$router->get ('/profile/edit',  'Modules\Profile\Controllers\ProfileController@editForm',
    [AuthMiddleware::class]);
$router->post('/profile/edit',  'Modules\Profile\Controllers\ProfileController@update',
    [CsrfMiddleware::class, AuthMiddleware::class]);

// Theme preference toggle - any logged-in user, no admin gate.
$router->post('/profile/theme', 'Modules\Profile\Controllers\ProfileController@updateTheme',
    [CsrfMiddleware::class, AuthMiddleware::class]);

// Per-channel notification preferences. New 2026-05-01 — gates the
// in-app + email channels independently per notification type. Service
// reads the same table on every send via NotificationService::isAllowed.
$router->get ('/profile/notifications', 'Modules\Profile\Controllers\NotificationPreferencesController@show',
    [AuthMiddleware::class]);
$router->post('/profile/notifications', 'Modules\Profile\Controllers\NotificationPreferencesController@save',
    [CsrfMiddleware::class, AuthMiddleware::class]);

// /profile/2fa/* routes stay in routes/web.php — they belong to the core
// TwoFactorController and are framework-level primitives.
