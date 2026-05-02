<?php
// modules/integrations/module.php
use Core\Module\ModuleProvider;

/**
 * Integrations module — superadmin status dashboard + test probes for
 * every third-party integration the framework knows about. All credentials
 * live in .env; this module never writes config — it only inspects and
 * exercises providers.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'integrations'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }
};
