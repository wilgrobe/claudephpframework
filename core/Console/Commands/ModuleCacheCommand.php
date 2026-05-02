<?php
// core/Console/Commands/ModuleCacheCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Module\ModuleRegistry;

/**
 * module:cache — write a deploy-time manifest of installed modules so
 * ModuleRegistry::discover() can skip its per-request DirectoryIterator scan.
 *
 * Add this to your deploy script AFTER `composer install` and BEFORE
 * traffic is routed to the new code. On a dev box you typically don't run
 * it — the scan fallback handles module changes automatically.
 *
 *   php artisan module:cache           # write storage/cache/modules.php
 *   php artisan module:clear           # remove it
 */
class ModuleCacheCommand extends Command
{
    public function name(): string        { return 'module:cache'; }
    public function description(): string { return 'Write storage/cache/modules.php so module discovery skips its filesystem scan.'; }

    public function handle(array $argv): int
    {
        // Pull the configured roots so that the cache covers every
        // module the live registry would find — including premium
        // modules from a sibling claudephpframeworkpremium checkout.
        $modulesConfig = is_file(BASE_PATH . '/config/modules.php')
            ? require BASE_PATH . '/config/modules.php'
            : ['paths' => [BASE_PATH . '/modules']];
        $paths = is_array($modulesConfig['paths'] ?? null)
            ? $modulesConfig['paths']
            : [BASE_PATH . '/modules'];

        $cacheFile = BASE_PATH . '/' . ($modulesConfig['cache_file'] ?? ModuleRegistry::DEFAULT_CACHE_FILE);

        $existingPaths = array_filter($paths, 'is_dir');
        if (empty($existingPaths)) {
            $this->warn('No module roots exist on disk — nothing to cache.');
            $this->line('  configured roots: ' . implode(', ', $paths));
            return 0;
        }

        /** @var ModuleRegistry $registry */
        $registry = app(ModuleRegistry::class);
        $entries  = $registry->dumpCache($existingPaths, $cacheFile);

        $this->success('Wrote ' . $cacheFile);
        $this->line('  scanned roots:    ' . implode(', ', $existingPaths));
        $this->line('  cached modules:   ' . (empty($entries) ? '(none)' : implode(', ', array_keys($entries))));
        return 0;
    }
}
