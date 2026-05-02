<?php
// modules/api-keys/Middleware/ApiAuthMiddleware.php
namespace Modules\ApiKeys\Middleware;

use Core\Request;
use Core\Response;
use Modules\ApiKeys\Services\ApiKeyService;

/**
 * Gates /api/* routes. Recognizes `Authorization: Bearer {token}`.
 *
 * Unlike session-based auth, this middleware does NOT start a PHP
 * session or set $_SESSION state. It sets:
 *   $_SERVER['X_API_AUTH_USER_ID']  = int
 *   $_SERVER['X_API_AUTH_KEY_ID']   = int
 *   $_SERVER['X_API_AUTH_SCOPES']   = array<string>
 * so downstream controllers can read them via a thin helper.
 *
 * Requires a specific scope? Chain this middleware with the
 * `require_api_scope($scope)` helper inside the controller — the
 * middleware itself only verifies that a key exists and is valid,
 * not that it has specific scopes (each endpoint declares its own).
 *
 * This middleware responds with a JSON 401/403 (not HTML) so API
 * clients get machine-parseable errors.
 */
class ApiAuthMiddleware
{
    /**
     * Middleware signature: handle(Request, callable $next). Return
     * the Response; do NOT chain to $next if auth fails. The framework
     * router invokes middleware via ->handle(), not __invoke (matches
     * the convention in app/Middleware/*).
     */
    public function handle(Request $request, callable $next): Response
    {
        $header = (string) ($request->header('Authorization') ?? '');
        if ($header === '' || !preg_match('~^Bearer\s+(\S+)$~', $header, $m)) {
            return self::apiError(401, 'missing_bearer_token', 'Provide an Authorization: Bearer <token> header.');
        }
        $token = $m[1];

        $auth = (new ApiKeyService())->authenticate($token);
        if ($auth === null) {
            return self::apiError(401, 'invalid_token', 'Token is unknown, revoked, or expired.');
        }

        // Stash auth context on $_SERVER so the controller can read it
        // via api_auth(). Avoids a global, avoids $_SESSION.
        $_SERVER['X_API_AUTH_USER_ID'] = (int) $auth['user_id'];
        $_SERVER['X_API_AUTH_KEY_ID']  = (int) $auth['key_id'];
        $_SERVER['X_API_AUTH_SCOPES']  = $auth['scopes'];

        return $next($request);
    }

    public static function apiError(int $status, string $code, string $message): Response
    {
        return Response::apiJson([
            'error'   => $code,
            'message' => $message,
        ], $status);
    }
}
