<?php
// core/Console/Commands/MakeModuleCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;

/**
 * Scaffold a full empty module at modules/<name>/:
 *
 *   modules/<name>/
 *     module.php        — ModuleProvider instance
 *     routes.php        — empty route file (loaded before routes/web.php)
 *     Controllers/      — empty
 *     Views/            — empty
 *     migrations/       — empty
 *
 * Usage:
 *   php artisan make:module blog
 */
class MakeModuleCommand extends Command
{
    public function name(): string        { return 'make:module'; }
    public function description(): string { return 'Scaffold a new empty module'; }
    public function usage(): string       { return 'php artisan make:module NAME'; }

    public function handle(array $argv): int
    {
        $name = $this->arg($argv, 2);
        if (empty($name) || str_starts_with($name, '--')) {
            $this->error('Usage: ' . $this->usage());
            return 1;
        }

        // Module names are lowercase + alphanumeric + underscore. This also
        // means the View namespace is the same string, and the PHP
        // namespace segment is the Studly form.
        $slug = strtolower($name);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            $this->error("Invalid module name: [$name]. Use lowercase letters/digits/underscore, starting with a letter.");
            return 1;
        }

        $moduleDir = BASE_PATH . "/modules/$slug";
        if (is_dir($moduleDir)) {
            $this->error("Module directory already exists: modules/$slug");
            return 1;
        }

        // Create directory tree
        foreach (['Controllers', 'Views', 'migrations'] as $sub) {
            mkdir("$moduleDir/$sub", 0755, true);
        }

        // .gitkeep in each subdir so empty dirs commit cleanly
        foreach (['Controllers', 'Views', 'migrations'] as $sub) {
            file_put_contents("$moduleDir/$sub/.gitkeep", '');
        }

        // module.php
        $providerPhp = <<<PHP
<?php
// modules/$slug/module.php
use Core\\Module\\ModuleProvider;

return new class extends ModuleProvider {
    public function name(): string            { return '$slug'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }
};

PHP;
        file_put_contents("$moduleDir/module.php", $providerPhp);

        // routes.php
        $studly = ucfirst($slug);
        $routesPhp = <<<PHP
<?php
// modules/$slug/routes.php
/** @var \\Core\\Router\\Router \$router */

use App\\Middleware\\AuthMiddleware;
use App\\Middleware\\CsrfMiddleware;

// Example route (delete once you add your own):
// \$router->get('/$slug', 'Modules\\\\$studly\\\\Controllers\\\\{$studly}Controller@index', [AuthMiddleware::class]);

PHP;
        file_put_contents("$moduleDir/routes.php", $routesPhp);

        $this->success("scaffolded modules/$slug/");
        $this->line("  module.php        ← provider (name='$slug', views under 'modules/$slug/Views')");
        $this->line('  routes.php        ← add $router->get/post/... calls here');
        $this->line('  Controllers/      ← put controller classes here');
        $this->line('  Views/            ← Response::view(\'' . $slug . '::foo\') resolves to Views/foo.php');
        $this->line('  migrations/       ← artisan migrate picks these up automatically');
        $this->line('');
        $this->line("  Next:  php artisan make:controller {$studly}Controller --module=$slug");
        return 0;
    }
}
