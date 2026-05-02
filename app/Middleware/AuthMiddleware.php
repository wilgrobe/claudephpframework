<?php
// app/Middleware/AuthMiddleware.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;

class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = Auth::getInstance();

        // Case 1: User has passed credentials but not yet completed 2FA
        if (!empty($_SESSION['2fa_pending_user_id']) && empty($_SESSION['user_id'])) {
            return Response::redirect('/auth/2fa/challenge');
        }

        // Case 2: No session at all — redirect to login
        if ($auth->guest()) {
            Session::set('intended', $request->path());
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
