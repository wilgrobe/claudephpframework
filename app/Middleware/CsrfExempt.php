<?php
// app/Middleware/CsrfExempt.php
namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * Marker middleware: opt out of the global CSRF default.
 *
 * The Router prepends CsrfMiddleware to every POST/PUT/PATCH/DELETE
 * route by default (Sec audit, 2026-04-30). Routes that legitimately
 * cannot supply a CSRF token — payment-gateway webhooks, server-to-
 * server callbacks, anything signed by an external HMAC — list this
 * class in their middleware to suppress the auto-attachment.
 *
 *   $router->post('/webhooks/stripe/store', "$W@receive",
 *       [\App\Middleware\CsrfExempt::class]);
 *
 * The handler itself does no work — it's pattern-matched by the Router
 * and never actually runs. We make it pass-through anyway so a hand-
 * registered listing inside a normal middleware chain is harmless.
 */
class CsrfExempt
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
