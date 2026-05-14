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
     * @param string   $key         module-scoped, kebab-case (e.g. 'cart', 'gift-cards')
     * @param string   $label       human-readable name shown in the wizard
     * @param string   $description one-line explanation of what the submodule does
     * @param int      $costTokens  per-pick token cost; default 150 matches wizard.step4.per_submodule
     * @param string[] $requires    other submodule keys (intra-module) that must be enabled too
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly int    $costTokens = 150,
        public readonly array  $requires   = [],
    ) {
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            throw new \InvalidArgumentException(
                "SubmoduleDescriptor key must be lowercase kebab-case; got: '$key'"
            );
        }
    }
}
