<?php
// core/Console/Commands/ModuleClearCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Module\ModuleRegistry;

/**
 * module:clear — remove the module manifest so ModuleRegistry falls back to
 * scanning modules/ on each request. Useful locally after adding or
 * renaming a module.
 */
class ModuleClearCommand extends Command
{
    public function name(): string        { return 'module:clear'; }
    public function description(): string { return 'Remove storage/cache/modules.php so module discovery re-scans on every request.'; }

    public function handle(array $argv): int
    {
        $cacheFile = BASE_PATH . '/' . ModuleRegistry::DEFAULT_CACHE_FILE;
        if (!is_file($cacheFile)) {
            $this->line('No module cache file at ' . $cacheFile . ' — nothing to clear.');
            return 0;
        }

        if (@unlink($cacheFile)) {
            $this->success('Removed ' . $cacheFile);
            return 0;
        }
        $this->error('Failed to remove ' . $cacheFile);
        return 1;
    }
}
