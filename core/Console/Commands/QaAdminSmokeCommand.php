<?php
// core/Console/Commands/QaAdminSmokeCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Container\Container;
use Core\Database\Database;
use Core\Http\Request;
use Core\Router\Router;

/**
 * qa:admin-smoke — fire a GET against every /admin/* route as a
 * superadmin and assert the framework answered without a fatal.
 *
 * Why this exists: a typo'd method call (e.g. Database::fetchRow that
 * should have been fetchOne) sits in code as lint-clean PHP and only
 * trips a fatal when an actual request hits the line. Code review +
 * unit tests routinely miss that class of bug. This command exercises
 * every static admin path in-process so CI catches them automatically.
 *
 * Scope:
 *   - Method: GET (POSTs need CSRF tokens + state preconditions, out
 *     of scope for a smoke pass).
 *   - Path:   /admin/* only. Public routes get hit by browsers; admin
 *     routes are the cold paths where typos hide.
 *   - Skip:   any path containing "{" — parameterized routes need
 *     fixture data (a real user_id, page slug, etc.) that this
 *     command intentionally doesn't synthesize. Override with
 *     --include-params if you want them attempted with the literal
 *     placeholder text (will mostly 404, but occasionally exposes
 *     a controller bug that fires before the parameter is read).
 *
 * Auth:
 *   Sets $_SESSION['user_id'] to a superadmin's id before dispatch.
 *   The user is resolved from --user=<email> when supplied, otherwise
 *   the first row in users with a superadmin role is used. NO real
 *   session is created in the DB — we mutate $_SESSION in-process
 *   only, so the run leaves no audit trail other than what the
 *   controllers themselves write.
 *
 * Failure detection:
 *   A route fails if (a) Response status >= 500, or (b) the rendered
 *   body contains any of: "Fatal error", "Uncaught", "Whoops",
 *   "Stack trace". The substring check catches PHP's own error
 *   output even when the framework mishandles status codes.
 *
 * Output:
 *   Default: human-readable table + "PASS N / FAIL M" footer.
 *   --json:  machine-readable per-route JSON for CI integration.
 *
 * Exit code: 0 if all routes pass, 1 if any failed (suitable for
 * `set -e` or a CI gate).
 */
class QaAdminSmokeCommand extends Command
{
    private Container $container;
    public function __construct(Container $container) { $this->container = $container; }

    public function name(): string        { return 'qa:admin-smoke'; }
    public function description(): string { return 'GET every /admin/* route as superadmin; flag any fatal/500'; }

    public function handle(array $argv): int
    {
        $userEmail     = $this->flag($argv, '--user');
        $includeParams = in_array('--include-params', $argv, true);
        $jsonOut       = in_array('--json', $argv, true);

        $router = $this->container->make(Router::class);
        $db     = $this->container->make(Database::class);

        // Resolve the actor: explicit email if passed, otherwise the
        // first user with the is_superadmin flag set. (The flag lives on
        // the users table directly per Auth::loadUser — there's no
        // is_superadmin column on roles.)
        $actor = $userEmail !== null
            ? $db->fetchOne("SELECT id, email FROM users WHERE email = ?", [$userEmail])
            : $db->fetchOne(
                "SELECT id, email FROM users
                  WHERE is_superadmin = 1
                  ORDER BY id ASC LIMIT 1"
            );
        if (!$actor) {
            $this->line('  ERROR: could not resolve a superadmin user. Pass --user=<email> explicitly.');
            return 1;
        }
        $actorId = (int) $actor['id'];

        // Discover candidate routes.
        $routes = array_filter($router->routes(), function ($r) use ($includeParams) {
            if (strtoupper($r['method']) !== 'GET') return false;
            if (!str_starts_with($r['path'], '/admin/')) return false;
            if (!$includeParams && str_contains($r['path'], '{')) return false;
            return true;
        });
        $routes = array_values($routes);

        if (empty($routes)) {
            $this->line('  No admin GET routes discovered. Nothing to smoke.');
            return 0;
        }

        // In-process auth: mutate session so controllers behave like
        // we're a logged-in superadmin. No session row is written.
        if (session_status() === PHP_SESSION_NONE) {
            // CLI has no native session; fake the superglobal directly.
            $_SESSION = $_SESSION ?? [];
        }
        $_SESSION['user_id']         = $actorId;
        $_SESSION['superadmin_mode'] = 1;

        $results = [];
        $failedCount = 0;

        foreach ($routes as $r) {
            $path = $r['path'];
            $result = $this->probe($router, $path);
            $results[] = $result;
            if (!$result['pass']) $failedCount++;
        }

        if ($jsonOut) {
            echo json_encode([
                'actor_user_id' => $actorId,
                'actor_email'   => $actor['email'],
                'routes_total'  => count($results),
                'routes_failed' => $failedCount,
                'results'       => $results,
            ], JSON_PRETTY_PRINT) . "\n";
            return $failedCount > 0 ? 1 : 0;
        }

        printf("\n  %-50s  %-6s  %s\n", 'Path', 'Status', 'Note');
        $this->line('  ' . str_repeat('─', 80));
        foreach ($results as $row) {
            printf("  %-50s  %-6s  %s\n",
                substr($row['path'], 0, 50),
                $row['status'] ?? 'ERR',
                $row['pass'] ? 'OK' : ('FAIL — ' . ($row['reason'] ?? 'unknown'))
            );
        }
        $this->line('  ' . str_repeat('─', 80));
        $this->line(sprintf('  %d total · %d passed · %d failed (actor: %s, user_id=%d)',
            count($results), count($results) - $failedCount, $failedCount, $actor['email'], $actorId));

        return $failedCount > 0 ? 1 : 0;
    }

    /**
     * Dispatch one in-process GET against the router and grade it.
     * Wrapped in a top-level try/catch so a thrown fatal during
     * dispatch counts as a failure rather than killing the whole run.
     */
    private function probe(Router $router, string $path): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $_GET = $_POST = $_FILES = [];

        $request = Request::capture();

        ob_start();
        try {
            $response = $router->dispatch($request);
            $body = method_exists($response, 'getContent') ? (string) $response->getContent() : (string) $response;
            $status = method_exists($response, 'getStatus') ? (int) $response->getStatus() : 200;
        } catch (\Throwable $e) {
            ob_end_clean();
            return [
                'path'   => $path,
                'status' => 'ERR',
                'pass'   => false,
                'reason' => 'Exception: ' . $e->getMessage(),
            ];
        }
        ob_end_clean();

        $bad = ['Fatal error', 'Uncaught', 'Whoops', 'Stack trace'];
        foreach ($bad as $needle) {
            if (str_contains($body, $needle)) {
                return [
                    'path'   => $path,
                    'status' => $status,
                    'pass'   => false,
                    'reason' => "body contains '$needle'",
                ];
            }
        }
        if ($status >= 500) {
            return ['path' => $path, 'status' => $status, 'pass' => false, 'reason' => "HTTP $status"];
        }

        return ['path' => $path, 'status' => $status, 'pass' => true];
    }

    /** Parse --foo=bar from $argv. */
    private function flag(array $argv, string $name): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, $name . '=')) return substr($arg, strlen($name) + 1);
        }
        return null;
    }
}
