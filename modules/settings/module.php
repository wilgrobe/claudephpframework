<?php
// modules/settings/module.php
use Core\Module\ModuleProvider;

/**
 * Settings module — superadmin-only configuration surface. Generic key/value
 * editor plus dedicated purpose-built forms for Footer, Appearance, and
 * Group Policy. The COLOR_DEFAULTS constant on SettingsController is
 * referenced by the live layout (via SettingsController::COLOR_DEFAULTS) so
 * its fully-qualified name changes when this module moves — update the
 * layout import if you notice broken CSS default fallbacks.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'settings'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }
};
