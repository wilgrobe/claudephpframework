<?php
// core/Module/AllSubmodulesEnabled.php
namespace Core\Module;

/**
 * Default {@see SubmoduleGate}: no runtime gating. `enabledFor()` returns null
 * so the registry treats every declared submodule as enabled, and there are no
 * per-submodule settings. This is the correct behavior for a standalone
 * framework install — there is no host gating model to drive a selection.
 *
 * A host binds its own gate in config/services.php to gate per project/plan.
 */
final class AllSubmodulesEnabled implements SubmoduleGate
{
    public function enabledFor(string $moduleName): ?array
    {
        return null;
    }

    public function settingsFor(string $moduleName, string $submoduleKey): array
    {
        return [];
    }
}
