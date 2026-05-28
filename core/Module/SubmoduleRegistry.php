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
 *   2. **Tenant-scoped lookup** — which submodules are enabled for the
 *      current request's project. Resolves the project_id from
 *      `Tenant::current()` via `sourceProjectId()`, reads central
 *      `project_submodules`, caches the entire (project → modules →
 *      keys) tree for the rest of the request.
 *
 * Backwards-compat behavior (CONFIGURABLE — see decision log in the
 * docblock of `enabledForProject` below):
 *   - Apex requests (no tenant) → all submodules enabled (the wizard
 *     decides spend, runtime gating doesn't apply).
 *   - Standalone-framework deployments where `projects` table is
 *     absent → all submodules enabled (no wizard exists to drive the
 *     selection model).
 *   - Built tenant whose source project has zero `project_submodules`
 *     rows for a module → NONE enabled. Operator must run
 *     `php artisan submodules:backfill --enable-all --project=N`
 *     before deploying or the gated features turn off.
 */
final class SubmoduleRegistry
{
    /** @var array<string, SubmoduleDescriptor[]> moduleName → descriptors */
    private array $catalog = [];

    /**
     * Per-request cache of enabled submodule keys, keyed by
     * (project_id, module_name). Populated lazily on first lookup.
     *
     * @var array<int, array<string, string[]>>
     */
    private array $enabledByProject = [];

    /** Cache for the projects-table-existence probe (per request). */
    private ?bool $projectsTableExists = null;

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
     * Enabled submodule keys for the current request's tenant + module.
     * See class docblock for the apex / standalone-framework / no-rows
     * fallback rules.
     *
     * @return string[]  submodule keys that are turned on
     */
    public function enabledForCurrent(string $moduleName): array
    {
        $projectId = $this->resolveCurrentProjectId();
        if ($projectId === null) {
            // Apex / CLI / standalone framework — runtime gating doesn't
            // apply. Return the full available catalog so the module
            // doesn't act as if everything is disabled.
            return array_map(fn($d) => $d->key, $this->availableForModule($moduleName));
        }
        return $this->enabledForProject($projectId, $moduleName);
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
     * Enabled submodule keys for an explicit project + module.
     *
     * If the project has any `project_submodules` row at all (across
     * any module), we trust the table — modules with zero rows are
     * intentionally "nothing enabled" because the wizard's
     * uncheck-everything case produces exactly that state.
     *
     * If the project has NO rows at all (legacy build pre-dating
     * submodule UX), we also return NONE — operator must run
     * `submodules:backfill --enable-all --project=N` to seed
     * defaults. Documented in the class docblock + the backfill
     * command's `--help` output.
     *
     * @return string[]
     */
    public function enabledForProject(int $projectId, string $moduleName): array
    {
        if (isset($this->enabledByProject[$projectId])) {
            return $this->enabledByProject[$projectId][$moduleName] ?? [];
        }

        $cdb = $this->centralDb();
        if ($cdb === null) {
            // Central DB unreachable from this context (early CLI?).
            // Fall through to "all enabled" so we don't accidentally
            // disable everything because of a transient bootstrap
            // ordering issue.
            return array_map(fn($d) => $d->key, $this->availableForModule($moduleName));
        }

        try {
            $stmt = $cdb->prepare(
                "SELECT module_slug, submodule_key FROM project_submodules WHERE project_id = ?"
            );
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Missing project_submodules table → standalone framework
            // or fresh install pre-migrate. Default-on so we don't
            // crash. Cache the lookup so we don't retry the same query.
            $this->enabledByProject[$projectId] = [];
            return array_map(fn($d) => $d->key, $this->availableForModule($moduleName));
        }

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[(string) $r['module_slug']][] = (string) $r['submodule_key'];
        }
        $this->enabledByProject[$projectId] = $grouped;
        return $grouped[$moduleName] ?? [];
    }

    /**
     * Find the current request's source project_id. Walks
     * `Tenant::current()` → reverse-look up via
     * `projects WHERE tenant_id = ?`. Returns null on the apex,
     * the CLI, or when the central `projects` table doesn't exist
     * (standalone framework).
     */
    private function resolveCurrentProjectId(): ?int
    {
        if (!class_exists(\App\Tenancy\Tenant::class)) return null;
        $tenant = \App\Tenancy\Tenant::current();
        if ($tenant === null) return null;

        if (!$this->projectsTableExists()) return null;

        // Cached on the Tenant via sourceProjectId — see Tenant.php.
        if (method_exists($tenant, 'sourceProjectId')) {
            return $tenant->sourceProjectId();
        }
        return null;
    }

    private function projectsTableExists(): bool
    {
        if ($this->projectsTableExists !== null) return $this->projectsTableExists;
        $cdb = $this->centralDb();
        if ($cdb === null) return $this->projectsTableExists = false;
        try {
            $exists = (int) $cdb->query(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'projects'"
            )->fetchColumn();
            return $this->projectsTableExists = $exists > 0;
        } catch (\Throwable) {
            return $this->projectsTableExists = false;
        }
    }

    private function centralDb(): ?\PDO
    {
        if (!class_exists(\App\Database\CentralDatabase::class)) return null;
        try {
            return \App\Database\CentralDatabase::get();
        } catch (\Throwable) {
            return null;
        }
    }
}
