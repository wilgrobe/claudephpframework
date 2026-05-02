<?php
// app/Middleware/GuestMiddleware.php
namespace App\Middleware;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;

class GuestMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::getInstance()->check()) {
            return Response::redirect('/dashboard');
        }
        return $next($request);
    }
}
