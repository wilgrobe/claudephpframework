<?php
// modules/email/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\CsrfExempt;
use App\Middleware\RequireAdmin;

$U = 'Modules\\Email\\Controllers\\UnsubscribeController';
$W = 'Modules\\Email\\Controllers\\WebhookController';
$A = 'Modules\\Email\\Controllers\\AdminController';

// ── Public unsubscribe ────────────────────────────────────────────────
// /unsubscribe/{token}/one-click is the RFC 8058 endpoint — providers
// (Gmail, Yahoo) POST to it from their inbox UI without a CSRF cookie,
// so it has to opt OUT of CSRF. The token IS the proof of identity.
$router->get ('/unsubscribe/{token}',           "$U@landing");
$router->post('/unsubscribe/{token}',           "$U@confirm",  [CsrfMiddleware::class]);
$router->post('/unsubscribe/{token}/one-click', "$U@oneClick", [CsrfExempt::class]);

// ── Auth: preference center ───────────────────────────────────────────
$router->get ('/account/email-preferences',     "$U@preferenceCenter", [AuthMiddleware::class]);
$router->post('/account/email-preferences',     "$U@savePreferences",  [CsrfMiddleware::class, AuthMiddleware::class]);

// ── Provider webhooks ─────────────────────────────────────────────────
// All four exempt from CSRF — providers post raw JSON without a session.
// Auth is per-handler: SES via SNS signing, Mailgun via HMAC, SendGrid +
// Postmark via shared MAIL_WEBHOOK_SECRET env.
$router->post('/webhooks/email/ses',            "$W@ses",      [CsrfExempt::class]);
$router->post('/webhooks/email/sendgrid',       "$W@sendgrid", [CsrfExempt::class]);
$router->post('/webhooks/email/postmark',       "$W@postmark", [CsrfExempt::class]);
$router->post('/webhooks/email/mailgun',        "$W@mailgun",  [CsrfExempt::class]);

// ── Admin ─────────────────────────────────────────────────────────────
$router->get ('/admin/email-suppressions',                    "$A@index",   [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/email-suppressions',                    "$A@add",     [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/email-suppressions/{id}/delete',        "$A@delete",  [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/email-suppressions/blocks',             "$A@blocks",  [AuthMiddleware::class, RequireAdmin::class]);
$router->get ('/admin/email-suppressions/bounces',            "$A@bounces", [AuthMiddleware::class, RequireAdmin::class]);
