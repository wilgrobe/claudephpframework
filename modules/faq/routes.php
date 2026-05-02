<?php
// modules/faq/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

// Public FAQ page
$router->get('/faq', 'Modules\Faq\Controllers\FaqController@publicIndex');

// Admin: FAQ CRUD
$router->get ('/admin/faqs',            'Modules\Faq\Controllers\FaqController@index',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/faqs',            'Modules\Faq\Controllers\FaqController@store',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/faqs/{id}/edit',  'Modules\Faq\Controllers\FaqController@edit',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/faqs/{id}/edit',  'Modules\Faq\Controllers\FaqController@update',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/faqs/{id}/delete','Modules\Faq\Controllers\FaqController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);

// Admin: Categories
$router->get ('/admin/faqs/categories',              'Modules\Faq\Controllers\FaqController@categories',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/faqs/categories',              'Modules\Faq\Controllers\FaqController@storeCategory',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/faqs/categories/{id}/delete',  'Modules\Faq\Controllers\FaqController@deleteCategory',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
