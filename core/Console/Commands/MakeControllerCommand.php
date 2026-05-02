<?php
// core/Console/Commands/MakeControllerCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;

/**
 * Scaffold a new controller file.
 *
 *   php artisan make:controller WidgetController
 *     → app/Controllers/WidgetController.php  (namespace App\Controllers)
 *
 *   php artisan make:controller WidgetController --module=content
 *     → modules/content/Controllers/WidgetController.php
 *       (namespace Modules\Content\Controllers, views referenced as 'content::')
 *
 *   php artisan make:controller Admin/FooController
 *     → app/Controllers/Admin/FooController.php  (namespace App\Controllers\Admin)
 *
 * The scaffold includes a constructor stub, a sample index() action, and
 * the standard Core\Request / Core\Response / Core\Auth imports so the
 * generated file compiles on day one.
 */
class MakeControllerCommand extends Command
{
    public function name(): string        { return 'make:controller'; }
    public function description(): string { return 'Create a new controller class'; }
    public function usage(): string       { return 'php artisan make:controller NAME [--module=<name>]'; }

    public function handle(array $argv): int
    {
        $name = $this->arg($argv, 2);
        if (empty($name) || str_starts_with($name, '--')) {
            $this->error('Usage: ' . $this->usage());
            return 1;
        }

        // Normalize: accept Admin/FooController or Admin\FooController
        $name = str_replace('\\', '/', $name);
        if (!preg_match('#^[A-Za-z0-9/]+$#', $name)) {
            $this->error("Invalid controller name: [$name]. Allow letters, digits, and / only.");
            return 1;
        }

        $module = $this->option($argv, 'module');

        if ($module) {
            $moduleSlug = strtolower($module);
            $moduleStudly = ucfirst($moduleSlug);
            $dir     = BASE_PATH . '/modules/' . $moduleSlug . '/Controllers/' . dirname("./$name");
            $dir     = rtrim(str_replace('/.', '', $dir), '/');
            $namespace = 'Modules\\' . $moduleStudly . '\\Controllers' . (str_contains($name, '/') ? '\\' . str_replace('/', '\\', dirname($name)) : '');
            $viewNs    = $moduleSlug;
            $className = basename($name);
        } else {
            $dir       = BASE_PATH . '/app/Controllers' . (str_contains($name, '/') ? '/' . dirname($name) : '');
            $namespace = 'App\\Controllers' . (str_contains($name, '/') ? '\\' . str_replace('/', '\\', dirname($name)) : '');
            $viewNs    = null;
            $className = basename($name);
        }

        $path = "$dir/$className.php";

        if (is_file($path)) {
            $this->error('File already exists: ' . substr($path, strlen(BASE_PATH) + 1));
            return 1;
        }

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $viewStub = $viewNs
            ? "'$viewNs::index'"
            : "'" . strtolower(preg_replace('/Controller$/', '', $className)) . ".index'";

        $content = <<<PHP
<?php
// {$this->relativePath($path)}
namespace $namespace;

use Core\\Auth\\Auth;
use Core\\Request;
use Core\\Response;

class $className
{
    private Auth \$auth;

    public function __construct()
    {
        \$this->auth = Auth::getInstance();
    }

    public function index(Request \$request): Response
    {
        return Response::view($viewStub, [
            'user' => \$this->auth->user(),
        ]);
    }
}

PHP;

        file_put_contents($path, $content);
        $this->success('created ' . $this->relativePath($path));
        $this->line('  Namespace: ' . $namespace);
        if ($viewNs) {
            $this->line("  Views:     call Response::view('$viewNs::…') — register view files under modules/$viewNs/Views/");
        }
        $this->line('  Next:      add a route in ' . ($module ? "modules/$viewNs/routes.php" : 'routes/web.php'));
        return 0;
    }

    private function relativePath(string $path): string
    {
        return substr($path, strlen(BASE_PATH) + 1);
    }
}
