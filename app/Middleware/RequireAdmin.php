<?php
// app/Middleware/RequireAdmin.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;

class RequireAdmin
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = Auth::getInstance();
        // Superadmin mode bypasses this check
        if ($auth->isSuperadminModeOn()) {
            return $next($request);
        }
        if (!$auth->hasRole(['super-admin', 'admin'])) {
            return Response::redirect('/dashboard')->withFlash('error', 'Administrative access required.');
        }
        return $next($request);
    }
}
