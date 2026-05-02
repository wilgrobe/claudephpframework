<?php
// core/Module/EntitlementCheck.php
namespace Core\Module;

/**
 * Decides whether a given premium module is licensed to load for the
 * current request / tenant.
 *
 * The framework calls isEntitled($moduleName) once per premium module
 * during dependency resolution. A `false` return causes that module to
 * be treated like a missing dependency: skipped at boot, no routes /
 * views / blocks / migrations contributed, /admin/modules surfaces it
 * as 'disabled_unlicensed', and any module that requires it cascades
 * into 'disabled_dependency'.
 *
 * The default implementation in the open-source repo is
 * AlwaysGrantEntitlement — every premium module that's physically on
 * disk loads. The future web-app builder ships its own implementation
 * (driven by per-tenant subscription state, token balance, or whatever
 * the licensing model resolves to) and binds it into the container,
 * overriding the default.
 *
 * Bind in config/services.php:
 *
 *   $container->singleton(
 *       \Core\Module\EntitlementCheck::class,
 *       \App\Services\TenantEntitlement::class
 *   );
 *
 * Core modules never go through this gate — their tier() returns 'core'
 * and the registry skips the entitlement check entirely. The contract
 * exists purely to govern premium-tier module visibility.
 */
interface EntitlementCheck
{
    /**
     * Return true if the named premium module is licensed for the
     * current request. Implementations should be cheap (memoised /
     * cached) — this is called once per premium module per request.
     *
     * @param string $moduleName  The module's name() — same string the
     *                            ModuleProvider returns.
     */
    public function isEntitled(string $moduleName): bool;
}
