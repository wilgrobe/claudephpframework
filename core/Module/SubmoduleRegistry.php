<?php
// core/Module/SubmoduleRegistry.php
namespace Core\Module;

/**
 * Aggregates SubmoduleDescriptors contributed by every active module.
 *
 * Two responsibilities:
 *
 *   1. **Static catalog** — what submodules are available per module.
 *      Populated at boot by ModuleRegistry calling each provider's
 *      `submodules()` method. Mirrors BlockRegistry's shape.
 *
 *   2. **Runtime gating** — which submodules are enabled for the current
 *      request. The framework does NOT decide this itself; it delegates to
 *      the bound {@see SubmoduleGate}. The default {@see AllSubmodulesEnabled}
 *      enables everything (correct for a standalone install), while a host
 *      such as a multi-tenant host binds a gate that scopes by project/plan.
 *
 * Default behavior (no gate bound / gate returns null): every declared
 * submodule is enabled, so a bare framework install never acts as if its
 * features are switched off.
 */
final class SubmoduleRegistry
{
    /** @var array<string, SubmoduleDescriptor[]> moduleName → descriptors */
    private array $catalog = [];

    /**
     * Register every SubmoduleDescriptor a module contributes. Throws
     * on duplicate keys within the same module (collision is a
     * programmer error). Two different modules CAN reuse keys —
     * `store.cart` and `subscriptions.cart` are independently scoped.
     *
     * @param SubmoduleDescriptor[] $descriptors
     */
    public function registerForModule(string $moduleName, array $descriptors): void
    {
        if (empty($descriptors)) return;

        $seen = [];
        foreach ($descriptors as $d) {
            if (!$d instanceof SubmoduleDescriptor) {
                throw new \InvalidArgumentException(
                    "SubmoduleRegistry::registerForModule[$moduleName] expects SubmoduleDescriptor instances"
                );
            }
            if (isset($seen[$d->key])) {
                throw new \RuntimeException(
                    "Duplicate submodule key '{$d->key}' in module '$moduleName'"
                );
            }
            $seen[$d->key] = true;
        }
        // Allow re-registration of the same module (idempotent) but
        // overwrite — last-call-wins is the right behavior if a
        // module's submodules() shape changes between requests.
        $this->catalog[$moduleName] = array_values($descriptors);
    }

    /** @return SubmoduleDescriptor[] */
    public function availableForModule(string $moduleName): array
    {
        return $this->catalog[$moduleName] ?? [];
    }

    /** @return array<string, SubmoduleDescriptor[]> */
    public function allAvailable(): array
    {
        return $this->catalog;
    }

    public function hasModule(string $moduleName): bool
    {
        return isset($this->catalog[$moduleName]);
    }

    /**
     * Enabled submodule keys for the current request's module.
     *
     * Consults the bound {@see SubmoduleGate}. A null result means "no
     * gating applies" → every declared submodule is enabled (the bare-
     * framework default). A gating deployment returns its explicit set.
     *
     * @return string[]  submodule keys that are turned on
     */
    public function enabledForCurrent(string $moduleName): array
    {
        $enabled = $this->gate()->enabledFor($moduleName);
        if ($enabled === null) {
            // No gating — return the full available catalog so the module
            // doesn't act as if everything is disabled.
            return array_map(fn($d) => $d->key, $this->availableForModule($moduleName));
        }
        return $enabled;
    }

    public function isEnabledForCurrent(string $moduleName, string $submoduleKey): bool
    {
        // If the registry doesn't know about this module at all (i.e.
        // module not loaded at this runtime OR module declares no
        // submodules), default-on. The only sensible callers of this
        // method are INSIDE modules that ARE loaded — vacuous true
        // here means "no toggle exists for this combination" rather
        // than "this feature is gated off." Matters most for CLI /
        // test contexts that exercise gating code outside the
        // module's normal runtime.
        if (!isset($this->catalog[$moduleName])) {
            return true;
        }
        return in_array($submoduleKey, $this->enabledForCurrent($moduleName), true);
    }

    /**
     * Static convenience for controller / service / view gating.
     *
     * Returns true (default-on) when the container or registry isn't
     * resolvable — same safe-fallback rule as the inline pattern used
     * in routes.php files. The use case is something like:
     *
     *   if (!SubmoduleRegistry::featureEnabled('forms', 'webhook-notifications')) {
     *       return;  // skip the webhook dispatch for this tenant
     *   }
     *
     * Don't use this in performance-hot loops — each call resolves
     * through the container and the registry's per-request cache.
     * For tight loops, resolve the registry once and call
     * isEnabledForCurrent() directly.
     */
    public static function featureEnabled(string $moduleName, string $submoduleKey): bool
    {
        try {
            if (!class_exists(\Core\Container\Container::class)) return true;
            $reg = \Core\Container\Container::global()->get(self::class);
            if (!$reg instanceof self) return true;
            return $reg->isEnabledForCurrent($moduleName, $submoduleKey);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Static convenience for reading per-submodule settings from
     * controller / service / view code — the read-side sibling of
     * featureEnabled(). Returns [] when the registry isn't resolvable
     * (no gate / CLI / standalone) so callers can `?? $default` cleanly.
     *
     *   $cfg = SubmoduleRegistry::settingsFor('store', 'shipping-flat-rate');
     *   $rate = (int) ($cfg['rate_cents'] ?? 500);
     *
     * @return array<string, mixed>
     */
    public static function settingsFor(string $moduleName, string $submoduleKey): array
    {
        try {
            if (!class_exists(\Core\Container\Container::class)) return [];
            $reg = \Core\Container\Container::global()->get(self::class);
            if (!$reg instanceof self) return [];
            return $reg->settingsForCurrent($moduleName, $submoduleKey);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Per-submodule settings for the current request, delegated to the bound
     * {@see SubmoduleGate}. Returns [] when no gating applies or the submodule
     * has no stored settings. Shape is whatever the gate persisted, so callers
     * can read config keys directly.
     *
     *   $sla = SubmoduleRegistry::settingsFor('helpdesk', 'sla-tracking');
     *   $hours = (int) ($sla['first_response_hours'] ?? 24);
     *
     * @return array<string, mixed>
     */
    public function settingsForCurrent(string $moduleName, string $submoduleKey): array
    {
        return $this->gate()->settingsFor($moduleName, $submoduleKey);
    }

    /**
     * Resolve the bound {@see SubmoduleGate}. Falls back to the no-gating
     * {@see AllSubmodulesEnabled} default when the container/binding isn't
     * available (early CLI, tests, standalone install).
     */
    private function gate(): SubmoduleGate
    {
        try {
            if (class_exists(\Core\Container\Container::class)) {
                $c = \Core\Container\Container::global();
                if ($c !== null && $c->has(SubmoduleGate::class)) {
                    $g = $c->get(SubmoduleGate::class);
                    if ($g instanceof SubmoduleGate) return $g;
                }
            }
        } catch (\Throwable) {
            // fall through to the no-gating default
        }
        return new AllSubmodulesEnabled();
    }
}
