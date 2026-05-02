<?php
// core/Console/Commands/MakeMiddlewareCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;

/**
 * Scaffold a middleware class at app/Middleware/<Name>.php. The existing
 * middlewares (AuthMiddleware, CsrfMiddleware, RequireAdmin, etc.) all
 * follow the same shape — a handle($request, $next) method that either
 * calls $next($request) to continue or returns a Response to short-circuit.
 *
 *   php artisan make:middleware ThrottleMiddleware
 */
class MakeMiddlewareCommand extends Command
{
    public function name(): string        { return 'make:middleware'; }
    public function description(): string { return 'Create a new middleware class'; }
    public function usage(): string       { return 'php artisan make:middleware NAME'; }

    public function handle(array $argv): int
    {
        $name = $this->arg($argv, 2);
        if (empty($name) || str_starts_with($name, '--')) {
            $this->error('Usage: ' . $this->usage());
            return 1;
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $this->error("Invalid middleware name: [$name]. Use StudlyCase.");
            return 1;
        }

        $path = BASE_PATH . "/app/Middleware/$name.php";
        if (is_file($path)) {
            $this->error("Middleware already exists: app/Middleware/$name.php");
            return 1;
        }

        $content = <<<PHP
<?php
// app/Middleware/$name.php
namespace App\\Middleware;

use Core\\Request;
use Core\\Response;

/**
 * $name — <one-line description>.
 *
 * Middlewares run in the order registered on a route. Call \$next(\$request)
 * to let the request continue to the next middleware or the handler; return
 * a Response directly to short-circuit the pipeline.
 */
class $name
{
    public function handle(Request \$request, callable \$next): Response
    {
        // Pre-handler: inspect or mutate \$request here.
        //
        // Example short-circuit:
        //   if (\$condition) {
        //       return Response::redirect('/somewhere')->withFlash('error', 'nope');
        //   }

        \$response = \$next(\$request);

        // Post-handler: inspect or mutate \$response here before returning.
        return \$response;
    }
}

PHP;

        file_put_contents($path, $content);
        $this->success("created app/Middleware/$name.php");
        $this->line("  Use in a route:  \$router->get('/path', 'Controller@action', [\\App\\Middleware\\$name::class]);");
        return 0;
    }
}
