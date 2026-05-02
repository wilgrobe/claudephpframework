<?php
// core/Router/Router.php
namespace Core\Router;

use Core\Http\Request;
use Core\Response;

/**
 * Router — HTTP verb dispatch with route groups, named routes, and
 * Controller@method / closure handlers.
 *
 *   // Verbs: get, post, put, patch, delete, options, head, any
 *   $router->get('/users/{id}', 'UserController@show')->name('users.show');
 *   $router->delete('/users/{id}', 'UserController@destroy')
 *          ->name('users.destroy');
 *
 *   // Groups: shared prefix + middleware for a block of routes.
 *   $router->group(
 *       ['prefix' => '/admin', 'middleware' => [AuthMiddleware::class, RequireAdmin::class], 'name' => 'admin.'],
 *       function ($router) {
 *           $router->get('/users',            'UserController@index')->name('users.index');
 *           $router->get('/users/{id}/edit',  'UserController@edit')->name('users.edit');
 *       }
 *   );
 *
 *   // Named-route URL generation (use via route('admin.users.edit', ['id'=>42])):
 *   $router->urlFor('admin.users.edit', ['id' => 42]);   // → /admin/users/42
 *
 *   // Form method override: on HTML forms, a hidden _method field on POST
 *   // dispatches to PUT/PATCH/DELETE routes — handled in Request::capture().
 */
class Router
{
    /**
     * @var array<int, array{
     *   method: string, path: string, handler: mixed,
     *   middleware: array<int, string>, name: ?string
     * }>
     */
    private array $routes = [];

    /** @var array<string, int> name → index in $routes (for O(1) urlFor lookup) */
    private array $namedIndex = [];

    /**
     * Currently-active group attributes. `group()` pushes attrs; each verb
     * call merges with the stack before storing. Nested groups concatenate
     * (prefixes join, middleware arrays merge).
     *
     * @var array<int, array{prefix: string, middleware: array<int,string>, name: string}>
     */
    private array $groupStack = [];

    // ── Verb methods ──────────────────────────────────────────────────────────

    public function get(string $path, mixed $handler, array $middleware = []): self    { return $this->addRoute('GET',     $path, $handler, $middleware); }
    public function post(string $path, mixed $handler, array $middleware = []): self   { return $this->addRoute('POST',    $path, $handler, $middleware); }
    public function put(string $path, mixed $handler, array $middleware = []): self    { return $this->addRoute('PUT',     $path, $handler, $middleware); }
    public function patch(string $path, mixed $handler, array $middleware = []): self  { return $this->addRoute('PATCH',   $path, $handler, $middleware); }
    public function delete(string $path, mixed $handler, array $middleware = []): self { return $this->addRoute('DELETE',  $path, $handler, $middleware); }
    public function options(string $path, mixed $handler, array $middleware = []): self{ return $this->addRoute('OPTIONS', $path, $handler, $middleware); }
    public function head(string $path, mixed $handler, array $middleware = []): self   { return $this->addRoute('HEAD',    $path, $handler, $middleware); }

    /** Match any verb — useful for catch-alls and health endpoints. */
    public function any(string $path, mixed $handler, array $middleware = []): self
    {
        foreach (['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'] as $m) {
            $this->addRoute($m, $path, $handler, $middleware);
        }
        return $this;
    }

    // ── Groups ────────────────────────────────────────────────────────────────

    /**
     * Register a block of routes with shared prefix / middleware / name-prefix.
     *
     * Attributes:
     *   'prefix'     — prepended to each route path (e.g. '/admin')
     *   'middleware' — prepended to each route's middleware stack
     *   'name'       — prepended to each route's name (e.g. 'admin.' + 'users.index' = 'admin.users.index')
     *
     * Nested groups compound: outer middleware runs first, outer prefix comes first.
     */
    public function group(array $attrs, callable $routes): self
    {
        $this->groupStack[] = [
            'prefix'     => $attrs['prefix']     ?? '',
            'middleware' => $attrs['middleware'] ?? [],
            'name'       => $attrs['name']       ?? '',
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
        return $this;
    }

    /**
     * Name the most-recently-added route. Intended for the chain form:
     *
     *   $router->get('/users/{id}', 'UserController@show')->name('users.show');
     *
     * Re-using a name overwrites the previous mapping — duplicate names
     * would make urlFor() ambiguous.
     */
    public function name(string $name): self
    {
        if (empty($this->routes)) {
            throw new \LogicException('Router::name() called before any route was registered.');
        }
        $lastIndex = array_key_last($this->routes);

        // Prepend any active group's name prefix.
        $groupName = implode('', array_column($this->groupStack, 'name'));
        $fullName  = $groupName . $name;

        $this->routes[$lastIndex]['name'] = $fullName;
        $this->namedIndex[$fullName]      = $lastIndex;
        return $this;
    }

    // ── Dispatch + URL generation ────────────────────────────────────────────

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri    = '/' . ltrim(parse_url($request->path(), PHP_URL_PATH) ?? '', '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            $params = $this->matchRoute($route['path'], $uri);
            if ($params === null) continue;

            $request->setParams($params);

            // Default-deny CSRF: every state-changing request gets
            // CsrfMiddleware prepended unless the route explicitly opts
            // out via App\Middleware\CsrfExempt::class. This guards
            // against the easy-to-miss case of a new POST route shipping
            // without CsrfMiddleware in its list. Webhooks (Stripe etc.)
            // mark themselves exempt; everything else picks up CSRF
            // protection automatically.
            $middleware = $route['middleware'];
            if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
                $exemptClass = '\\App\\Middleware\\CsrfExempt';
                $csrfClass   = '\\App\\Middleware\\CsrfMiddleware';
                $isExempt = false;
                foreach ($middleware as $mw) {
                    if (is_string($mw) && ltrim($mw, '\\') === ltrim($exemptClass, '\\')) {
                        $isExempt = true;
                        break;
                    }
                }
                if (!$isExempt) {
                    $hasCsrf = false;
                    foreach ($middleware as $mw) {
                        if (is_string($mw) && ltrim($mw, '\\') === ltrim($csrfClass, '\\')) {
                            $hasCsrf = true;
                            break;
                        }
                    }
                    if (!$hasCsrf) {
                        array_unshift($middleware, ltrim($csrfClass, '\\'));
                    }
                }
            }

            return $this->runMiddleware($middleware, $request, function (Request $req) use ($route) {
                return $this->callHandler($route['handler'], $req);
            });
        }

        return new Response($this->notFoundPage(), 404);
    }

    /**
     * Resolve a named route to a URL. Missing placeholders throw; extra
     * $params spill over into the query string.
     *
     *   $router->urlFor('users.show', ['id' => 42, 'tab' => 'bio'])
     *     → '/users/42?tab=bio'
     */
    public function urlFor(string $name, array $params = []): string
    {
        if (!isset($this->namedIndex[$name])) {
            throw new \InvalidArgumentException("Unknown route name: [$name]");
        }
        $route = $this->routes[$this->namedIndex[$name]];
        $path  = $route['path'];

        // Substitute {placeholders}. Preserve the original param name so we
        // know which $params keys to consume (vs. spill to query string).
        $consumed = [];
        $path = preg_replace_callback('/\{([^}]+)\}/', function ($m) use (&$params, &$consumed, $name) {
            $key = $m[1];
            if (!array_key_exists($key, $params)) {
                throw new \InvalidArgumentException("Missing parameter [$key] for named route [$name]");
            }
            $consumed[$key] = true;
            return rawurlencode((string) $params[$key]);
        }, $path);

        $leftover = array_diff_key($params, $consumed);
        if ($leftover) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($leftover);
        }
        return $path;
    }

    /** @return array<int, array{method:string,path:string,name:?string}> — introspection for admin UIs */
    public function routes(): array
    {
        return array_map(fn($r) => [
            'method' => $r['method'],
            'path'   => $r['path'],
            'name'   => $r['name'] ?? null,
        ], $this->routes);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function addRoute(string $method, string $path, mixed $handler, array $middleware): self
    {
        // Apply any active group attributes.
        $prefix = implode('', array_column($this->groupStack, 'prefix'));
        $mwStack = [];
        foreach ($this->groupStack as $g) $mwStack = array_merge($mwStack, $g['middleware']);
        $middleware = array_merge($mwStack, $middleware);

        // Keep the slash-semantics predictable: group prefixes concatenate
        // without duplicating slashes, and a trailing '/' on the final route
        // is stripped only if it leaves a non-empty path (so '/' itself stays).
        $fullPath = $prefix . $path;
        if ($fullPath !== '/' && str_ends_with($fullPath, '/')) {
            $fullPath = rtrim($fullPath, '/');
        }
        if ($fullPath === '') $fullPath = '/';

        $this->routes[] = [
            'method'     => $method,
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => null,
        ];
        return $this;
    }

    private function matchRoute(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $uri, $matches)) return null;
        array_shift($matches);
        return array_values($matches);
    }

    private function runMiddleware(array $middleware, Request $request, callable $final): Response
    {
        $handler = $final;
        foreach (array_reverse($middleware) as $mw) {
            $next    = $handler;
            $handler = function (Request $req) use ($mw, $next): Response {
                $instance = new $mw();
                return $instance->handle($req, $next);
            };
        }
        return $handler($request);
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
            return $result instanceof Response ? $result : new Response((string) $result);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            // Fully-qualified (Modules\Pages\Controllers\PageController) passes
            // through; the custom Modules\... autoloader handles resolution.
            // Bare or Admin\-prefixed names fall back to the App\Controllers\
            // root so existing route strings keep working.
            if (str_contains($class, '\\') && (str_starts_with($class, 'Modules\\') || str_starts_with($class, 'App\\') || str_starts_with($class, 'Core\\'))) {
                $className = $class;
            } elseif (str_contains($class, '\\')) {
                $className = 'App\\Controllers\\' . $class;
            } else {
                $className = 'App\\Controllers\\' . $class;
            }

            if (!class_exists($className)) {
                return new Response("Controller [$className] not found.", 500);
            }
            $controller = new $className();
            if (!method_exists($controller, $method)) {
                return new Response("Method [$method] not found on [$className].", 500);
            }
            return $controller->$method($request);
        }

        return new Response('Invalid route handler.', 500);
    }

    private function notFoundPage(): string
    {
        return '<!DOCTYPE html><html><head><title>404 Not Found</title>
        <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;}
        .box{text-align:center;padding:3rem;}.box h1{font-size:5rem;margin:0;color:#dee2e6;}.box p{color:#6c757d;}</style>
        </head><body><div class="box"><h1>404</h1><p>The page you requested was not found.</p>
        <a href="/">Go Home</a></div></body></html>';
    }
}
