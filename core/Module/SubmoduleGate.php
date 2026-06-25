<?php
// core/Module/SubmoduleGate.php
namespace Core\Module;

/**
 * Extension point for runtime submodule gating.
 *
 * The framework's {@see SubmoduleRegistry} owns the static CATALOG of which
 * submodules each module declares. WHICH of those are enabled for the current
 * request is a deployment concern the framework deliberately does NOT decide:
 * a bare framework install enables everything, while a multi-tenant host
 * gates per project/plan.
 *
 * A deployment binds its own implementation to this contract in
 * config/services.php; the framework ships {@see AllSubmodulesEnabled} as the
 * default (no gating). SubmoduleRegistry resolves the bound gate and never
 * needs to know about tenants, projects, or any control-plane database.
 */
interface SubmoduleGate
{
    /**
     * Enabled submodule keys for $moduleName in the current request, or
     * NULL to signal "no gating applies" (the registry then treats every
     * declared submodule as enabled).
     *
     * @return string[]|null
     */
    public function enabledFor(string $moduleName): ?array;

    /**
     * Per-submodule settings for the current request, or [] when none are
     * stored / no gating applies.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(string $moduleName, string $submoduleKey): array;
}
