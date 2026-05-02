<?php
// modules/featureflags/module.php
use Core\Container\Container;
use Core\Module\ModuleProvider;

/**
 * Feature-flags module — runtime flag evaluation for gradual rollouts.
 *
 * Folder is `featureflags/` (flat lowercase, matching the framework's
 * module autoloader convention: Modules\FeatureFlags\… ↔
 * modules/featureflags/…). View namespace is `feature_flags`
 * (underscored to pass View::addNamespace's regex).
 *
 * Resolution precedence (first-match):
 *   per-user override → global kill-switch → group membership →
 *   percentage rollout (deterministic hash of user_id + key) →
 *   otherwise on.
 *
 * Global helper `feature('key')` wraps the service for cheap
 * in-view conditionals. Per-request cache keeps repeated calls
 * from re-hitting the DB.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'feature_flags'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function register(Container $container): void
    {
        require_once __DIR__ . '/Helpers/helpers.php';
    }

    /**
     * GDPR handlers — per-user flag overrides are erased outright.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'featureflags',
                description: 'Per-user feature-flag overrides assigned to your account.',
                tables: [
                    ['table' => 'feature_flag_overrides', 'user_column' => 'user_id', 'action' => \Modules\Gdpr\Services\GdprHandler::ACTION_ERASE],
                ]
            ),
        ];
    }
};
