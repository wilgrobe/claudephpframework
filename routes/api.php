<?php
// routes/api.php
/**
 * API routes — loaded by public/index.php BEFORE routes/web.php so API
 * catch-alls don't collide with the public /{slug} handler.
 *
 * All routes here live under /api/v1 and run inside the
 * JsonErrorMiddleware pipeline: any HttpException thrown by a handler
 * becomes a properly-shaped JSON error response with the right status code.
 *
 * @var \Core\Router\Router $router
 */

use App\Middleware\JsonErrorMiddleware;
use Core\Http\HttpException;
use Core\Http\Resources\UserResource;
use Core\Response;

$router->group(
    [
        'prefix'     => '/api/v1',
        'middleware' => [JsonErrorMiddleware::class],
        'name'       => 'api.v1.',
    ],
    function ($router) {

        // ── Health check (unauthenticated) ────────────────────────────────
        $router->get('/health', function () {
            return Response::apiJson([
                'status'  => 'ok',
                'version' => 'v1',
                'time'    => time(),
            ]);
        })->name('health');

        // ── Example: current user ─────────────────────────────────────────
        // Shows the standard shape: auth check (throws on miss), resource
        // transformer for serialization, apiJson() for the wire format.
        //
        // Add your own API token middleware in front of this group when you
        // need non-session auth (mobile clients, external integrations).
        $router->get('/users/me', function () {
            $auth = \Core\Auth\Auth::getInstance();
            if ($auth->guest()) {
                throw HttpException::unauthorized();
            }
            return Response::apiJson(['data' => UserResource::from($auth->user())]);
        })->name('users.me');

        // ── Example: user list (paginated) ────────────────────────────────
        // Add back once you've decided on auth model. Shown here as the
        // canonical pattern for list endpoints:
        //
        // $router->get('/users', function (\Core\Request $req) {
        //     $auth = \Core\Auth\Auth::getInstance();
        //     if ($auth->guest()) throw HttpException::unauthorized();
        //     if ($auth->cannot('users.view')) throw HttpException::forbidden();
        //
        //     $paginator = \Core\Database\Database::getInstance()
        //         ->table('users')
        //         ->where('is_active', 1)
        //         ->orderBy('created_at', 'desc')
        //         ->paginate((int) $req->query('per_page', 20),
        //                    (int) $req->query('page', 1));
        //
        //     return Response::apiJson(UserResource::paginated($paginator));
        // })->name('users.index');
    }
);
