<?php
// core/Module/SubmoduleDescriptor.php
namespace Core\Module;

/**
 * Value object describing one billable / optional feature unit a module
 * exposes. Returned from `ModuleProvider::submodules()` and aggregated
 * into `SubmoduleRegistry` at boot.
 *
 * The key is scoped per module — `store` declaring a 'cart' submodule
 * doesn't collide with `subscriptions` declaring one of the same name.
 * `requires` is intra-module only: a 'shipping' submodule depending on
 * 'checkout' is normal, but submodules can't reach across module
 * boundaries (use `ModuleProvider::requires()` for cross-module deps).
 */
final class SubmoduleDescriptor
{
    /**
     * Acceptable $settingsSchema field types — the same set BlockDescriptor
     * uses, so a host's submodule-settings panel can reuse the block
     * field renderer. Schema-less submodules render no settings UI (the
     * common case — most submodules are pure enable/disable).
     */
    public const SCHEMA_TYPES = ['text', 'textarea', 'number', 'checkbox', 'select', 'json', 'repeater', 'string_list'];

    /**
     * @param string   $key            module-scoped, kebab-case (e.g. 'cart', 'gift-cards')
     * @param string   $label          human-readable name shown in a host build UI
     * @param string   $description    one-line explanation of what the submodule does
     * @param int      $costTokens     per-pick cost a host may surface; default 150
     * @param string[] $requires       other submodule keys (intra-module) that must be enabled too
     * @param array    $settingsSchema per-submodule config fields, indexed array of
     *                                 {key,label,type,default,placeholder,help,options,item_schema};
     *                                 shape identical to BlockDescriptor::$settingsSchema. Empty = no settings.
     *                                 Persisted per (project, module, key) in project_submodules.settings;
     *                                 read at tenant runtime via SubmoduleRegistry::settingsForCurrent().
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly int    $costTokens     = 150,
        public readonly array  $requires       = [],
        public readonly array  $settingsSchema = [],
    ) {
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            throw new \InvalidArgumentException(
                "SubmoduleDescriptor key must be lowercase kebab-case; got: '$key'"
            );
        }
    }
}
