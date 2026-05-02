<?php
// app/Middleware/ThrottleMiddleware.php
namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * ThrottleMiddleware — <one-line description>.
 *
 * Middlewares run in the order registered on a route. Call $next($request)
 * to let the request continue to the next middleware or the handler; return
 * a Response directly to short-circuit the pipeline.
 */
class ThrottleMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        // Pre-handler: inspect or mutate $request here.
        //
        // Example short-circuit:
        //   if ($condition) {
        //       return Response::redirect('/somewhere')->withFlash('error', 'nope');
        //   }

        $response = $next($request);

        // Post-handler: inspect or mutate $response here before returning.
        return $response;
    }
}
