<?php
// modules/siteblocks/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\CsrfMiddleware;

// Newsletter signup endpoint — backs the siteblocks.newsletter_signup
// block. Public POST (no auth gate); the controller validates email
// shape and rate-limits via the existing Throttle middleware if/when
// you decide to layer it on. For v1 the form is light enough that the
// captcha posture from registration carries the same protective intent
// — add CaptchaService::verify() inside the controller if abuse becomes
// a real signal.
$router->post('/newsletter/subscribe',
    'Modules\Siteblocks\Controllers\NewsletterController@subscribe',
    [CsrfMiddleware::class]
);
