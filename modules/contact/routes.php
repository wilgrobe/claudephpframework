<?php
// modules/contact/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

// Public form page. Falls back to the module's default view so /contact
// always works without seeding a custom pages row.
$router->get ('/contact',
    'Modules\Contact\Controllers\ContactController@show');

// Public submit. CSRF-protected so the form must come from us.
$router->post('/contact',
    'Modules\Contact\Controllers\ContactController@submit',
    [CsrfMiddleware::class]);

// Admin queue
$router->get ('/admin/contact-messages',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@index',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/contact-messages/{id}',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@show',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/contact-messages/{id}/replied',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@markReplied',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/contact-messages/{id}/archive',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@archive',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/contact-messages/{id}/delete',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// Settings panel
$router->get ('/admin/settings/contact',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@settingsShow',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/settings/contact',
    'Modules\Contact\Controllers\Admin\ContactMessagesController@settingsSave',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
