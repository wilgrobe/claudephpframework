<?php
// app/Middleware/RequireSuperadmin.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;

/**
 * Gate routes that only superadmins can reach.
 *
 * Distinct from RequireAdmin: regular role-based admins are rejected. The
 * check uses the users.is_superadmin column directly so that revoking
 * superadmin status is effective immediately without depending on role
 * assignment cleanup.
 */
class RequireSuperadmin
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = Auth::getInstance();
        if (!$auth->isSuperAdmin()) {
            return Response::redirect('/dashboard')
                ->withFlash('error', 'Superadmin access required.');
        }
        return $next($request);
    }
}
