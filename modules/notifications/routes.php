<?php
// modules/notifications/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

$router->get ('/notifications',                 'Modules\Notifications\Controllers\NotificationsController@index',
    [AuthMiddleware::class]);
$router->post('/notifications/mark-all-read',   'Modules\Notifications\Controllers\NotificationsController@markAllRead',
    [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/notifications/{id}/read',       'Modules\Notifications\Controllers\NotificationsController@markRead',
    [CsrfMiddleware::class, AuthMiddleware::class]);
$router->post('/notifications/{id}/delete',     'Modules\Notifications\Controllers\NotificationsController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class]);
$router->get ('/notifications/count',           'Modules\Notifications\Controllers\NotificationsController@unreadCount',
    [AuthMiddleware::class]);

// Per-user preferences page. Declared after /count so the literal
// /preferences suffix isn't mistaken for a notification id by the
// /{id}/read pattern matcher.
$router->get ('/notifications/preferences',     'Modules\Notifications\Controllers\NotificationsController@preferences',
    [AuthMiddleware::class]);
$router->post('/notifications/preferences',     'Modules\Notifications\Controllers\NotificationsController@savePreferences',
    [CsrfMiddleware::class, AuthMiddleware::class]);
