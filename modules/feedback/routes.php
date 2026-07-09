<?php
// modules/feedback/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

// ── Public ────────────────────────────────────────────────────────────────
// The feedback form + submit. Both honor the builder.feedback.enabled toggle
// (the controller 404s when the site hasn't turned the feature on).
$router->get ('/feedback',
    'Modules\Feedback\Controllers\FeedbackController@show');
$router->post('/feedback',
    'Modules\Feedback\Controllers\FeedbackController@submit',
    [CsrfMiddleware::class]);

// Public showcase of published testimonials (also toggle-gated).
$router->get ('/testimonials',
    'Modules\Feedback\Controllers\FeedbackController@testimonials');

// ── Admin queue (always reachable for site admins) ──────────────────────────
// NB: /admin/site-feedback (not /admin/feedback) — on the builder apex the
// builder-operator module owns /admin/feedback for BUILD feedback; this is
// the SITE's end-user feedback inbox, so it gets a distinct path everywhere.
$router->get ('/admin/site-feedback',
    'Modules\Feedback\Controllers\Admin\FeedbackAdminController@index',
    [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/site-feedback/{id}/status',
    'Modules\Feedback\Controllers\Admin\FeedbackAdminController@setStatus',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/site-feedback/{id}/delete',
    'Modules\Feedback\Controllers\Admin\FeedbackAdminController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
