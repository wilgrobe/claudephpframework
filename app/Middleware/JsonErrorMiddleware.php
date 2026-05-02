<?php
// app/Middleware/JsonErrorMiddleware.php
namespace App\Middleware;

use Core\Http\HttpException;
use Core\Request;
use Core\Response;

/**
 * Turns any exception thrown inside an API route into a JSON response with
 * the right HTTP status code. Wrap the /api/v1 route group with this to
 * get consistent error shapes across every endpoint.
 *
 * Error shape:
 *   { "error": "<message>", "errors": { <field>: [<msg>, …], … } }
 *
 * `errors` is only present when the thrown HttpException carried a payload
 * (e.g. validation failures on HTTP 422). For non-HttpException throwables
 * in debug mode the stack trace is included under `debug` so you don't
 * need to tail server logs during dev.
 */
class JsonErrorMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        try {
            $response = $next($request);

            // Default Content-Type for API routes is JSON, even if the
            // handler built a bare Response('...'). Routes that already
            // called apiJson()/json() will have Content-Type set and
            // pass through unchanged.
            if (!$response->hasHeader('Content-Type')) {
                $response->header('Content-Type', 'application/json');
            }
            return $response;

        } catch (HttpException $e) {
            $body = ['error' => $e->getMessage() ?: $this->statusPhrase($e->statusCode())];
            if ($e->errors()) $body['errors'] = $e->errors();
            return Response::apiJson($body, $e->statusCode());

        } catch (\Throwable $e) {
            // Log to the same place the web error handler logs — admins get
            // one unified source of truth for production errors.
            error_log('[api] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            $body = ['error' => 'Internal server error'];
            if (config('app.debug', false)) {
                // Dev-only: surface the real reason so you don't have to
                // tail logs from another terminal.
                $body['error'] = $e::class . ': ' . $e->getMessage();
                $body['debug'] = [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
                ];
            }
            return Response::apiJson($body, 500);
        }
    }

    private function statusPhrase(int $code): string
    {
        return match ($code) {
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            410 => 'Gone',
            422 => 'Unprocessable entity',
            429 => 'Too many requests',
            default => 'Error',
        };
    }
}
