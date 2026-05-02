<?php
// core/Module/ModuleRegistry.php
namespace Core\Module;

use Core\Container\Container;
use Core\Router\Router;
use Core\View;

/**
 * Discovers and orchestrates modules across one or more module roots.
 *
 * The framework ships two roots:
 *   - <core>/modules/                — open-source modules (always present)
 *   - <premium>/modules/             — paid modules (present only when the
 *                                      paired claudephpframeworkpremium
 *                                      checkout is mounted alongside core)
 *
 * Both roots use identical layout (modules/<name>/module.php), so the
 * registry treats them uniformly. The two-tier split is enforced via
 * ModuleProvider::tier() and the EntitlementCheck contract — file
 * placement determines what's available at all, tier+entitlement
 * determine what actually loads.
 *
 * Lifecycle:
 *   1. discover($dir) | discoverMany([$dirs])
 *        - Scans each dir for module.php files
 *        - Requires each; each returns a ModuleProvider instance
 *        - Registers an autoloader for Modules\{Name}\... that resolves
 *          via the per-module path map ($this->paths) — modules from
 *          either root autoload correctly
 *        - Registers View namespaces for modules that expose viewsPath()
 *        - Calls $provider->register($container) to let the module bind services
 *
 *   2. loadRoutes($router)
 *        - Requires each ACTIVE module's routesFile() with $router in scope
 *
 *   3. boot($router, $container)
 *        - Calls $provider->boot($router, $container) on each ACTIVE provider
 *
 * Skip reasons (modules in $providers but not in $active):
 *   - disabled_admin       — SA pressed Disable in /admin/modules
 *   - disabled_dependency  — a required peer is itself unavailable
 *   - disabled_unlicensed  — premium module whose EntitlementCheck returned false
 *
 * Also exposes migrationPaths() so the Migrator can scan per-module migration dirs.
 */
class ModuleRegistry
{
    /** @var ModuleProvider[] all discovered providers, keyed by name */
    private array $providers = [];

    /**
     * Active providers — providers from $this->providers that pass the
     * dependency check AND haven't been admin-disabled. loadRoutes()
     * and boot() iterate THIS, not the full set, so disabled modules
     * contribute nothing past discovery. Populated by
     * resolveDependencies(), which is called automatically on first
     * access if it hasn't run yet.
     *
     * @var ModuleProvider[]
     */
    private array $active = [];

    /**
     * Modules that failed dependency checks. Same shape as
     * DependencyChecker::check()'s 'skipped' return: name => [
     *     'provider' => ModuleProvider, 'missing' => string[]
     * ].
     *
     * @var array<string, array{provider: ModuleProvider, missing: string[]}>
     */
    private array $skipped = [];

    /**
     * Modules an SA has explicitly disabled via /admin/modules.
     * State='disabled_admin' in module_status. These are filtered out
     * BEFORE the dep checker runs so that disabling A also cascades to
     * any module that requires A (which falls into $skipped with
     * 'missing' = [A]).
     *
     * @var ModuleProvider[]
     */
    private array $adminDisabled = [];

    /** Tracks whether resolveDependencies() has been called for this request. */
    private bool $resolved = false;

    /** @var array<string, string> moduleName => absolute module directory */
    private array $paths = [];

    /**
     * Folder basename → absolute module directory. Used ONLY by the
     * autoloader, which resolves classes by lowercase namespace segment
     * (matching the folder convention) — name() may contain underscores
     * (e.g. 'import_export') that can't appear in a PHP namespace, so
     * keying by name() would miss those modules. The folder is the
     * universal anchor.
     *
     * @var array<string, string>
     */
    private array $folderPaths = [];

    /**
     * Premium modules whose EntitlementCheck returned false this request.
     * Treated like a missing dependency at resolve time. Same shape as
     * $skipped: name => ['provider' => ..., 'missing' => ['(unlicensed)']].
     *
     * @var array<string, array{provider: ModuleProvider, missing: string[]}>
     */
    private array $unlicensed = [];

    /**
     * Roots passed to discover()/discoverMany(). Tracked so dumpCache()
     * can scan all of them when regenerating the manifest, and so
     * /admin/modules can show which root a given module came from.
     *
     * @var string[]
     */
    private array $roots = [];

    private Container $container;

    /** Tracks whether the PSR-4 autoloader for Modules\ has been registered. */
    private bool $autoloaderRegistered = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Default location for the deploy-time module manifest. When present,
     * discover() skips the DirectoryIterator scan and requires only the
     * module.php files listed here. See dumpCache() + `php artisan module:cache`.
     */
    public const DEFAULT_CACHE_FILE = 'storage/cache/modules.php';

    /**
     * Scan $modulesDir for modules. Safe to call when the directory doesn't
     * exist — no modules are registered in that case. Additive: calling
     * discover() multiple times (or discoverMany()) accumulates module
     * registrations across roots.
     *
     * Skips the filesystem scan when a manifest exists at
     * BASE_PATH . '/' . DEFAULT_CACHE_FILE. The manifest is a plain PHP file
     * returning ['moduleName' => '/abs/path/to/module/dir', ...]. Opcache
     * caches the manifest's compiled bytecode so subsequent requests pay
     * only the `require` cost, not a directory scan. The cache spans
     * every root passed to discover()/discoverMany() — there is no
     * per-root manifest.
     *
     * Production deploys should run `php artisan module:cache` after deploy.
     * Dev and fresh installs work without the cache — the scan is the
     * fallback, not a failure mode.
     */
    public function discover(string $modulesDir): void
    {
        // Track every root we're asked about, even ones that don't yet
        // exist on disk. dumpCache() needs the full list, and
        // /admin/modules surfaces the absent-root case so an SA can tell
        // "no premium repo mounted" apart from "premium repo present but
        // empty".
        $this->roots[] = $modulesDir;

        // Autoloader is registered exactly once for the lifetime of the
        // registry. It uses the cumulative $this->paths map to resolve
        // any module from any root, so it doesn't need to know up-front
        // which directories will be scanned.
        $this->registerAutoloader();

        // First-call cache check: if a manifest exists, it spans ALL
        // roots and we register everything in it. Subsequent discover()
        // calls become no-ops because $this->providers is already
        // populated.
        if ($this->roots === [$modulesDir]) {
            $cached = $this->loadCache();
            if ($cached !== null) {
                foreach ($cached as $name => $moduleDir) {
                    $this->registerFromDir((string) $name, (string) $moduleDir);
                }
                $this->cacheLoaded = true;
                return;
            }
        }

        // If the cache was loaded on the first call, later discover()
        // calls have nothing to do — the manifest already covered them.
        if ($this->cacheLoaded) return;

        if (!is_dir($modulesDir)) {
            return;
        }

        foreach (new \DirectoryIterator($modulesDir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) continue;
            $moduleDir    = $entry->getPathname();
            $providerFile = $moduleDir . '/module.php';
            if (!is_file($providerFile)) continue;

            $this->registerFromDir(null, $moduleDir);
        }
    }

    /**
     * Tracks whether the manifest cache has been consumed during this
     * registry's lifetime. When true, discover() becomes a no-op for
     * later roots — the manifest is the source of truth.
     */
    private bool $cacheLoaded = false;

    /**
     * Convenience for the common case: a list of module roots from
     * config('modules.paths'). Each root is forwarded to discover() in
     * the order given. The first root's modules win on name collision —
     * if you have a fork of the same module in both core and premium,
     * the core copy is the one that registers.
     *
     * @param string[] $modulesDirs
     */
    public function discoverMany(array $modulesDirs): void
    {
        foreach ($modulesDirs as $dir) {
            if (!is_string($dir) || $dir === '') continue;
            $this->discover($dir);
        }
    }

    /**
     * Require a module's provider file and wire it up. Shared between the
     * cached path (name + dir known ahead of time) and the scan path
     * (name pulled from provider->name()).
     *
     * Name-collision policy: the first registration wins. When the same
     * module name exists in two roots (e.g. someone forked a core module
     * into the premium repo to override it) we keep the earlier copy
     * and silently skip the later one. The earlier copy is the one
     * passed to discover() first — by convention, core/modules/.
     */
    private function registerFromDir(?string $knownName, string $moduleDir): void
    {
        $providerFile = $moduleDir . '/module.php';
        if (!is_file($providerFile)) return;

        /** @var ModuleProvider $provider */
        $provider = require $providerFile;
        if (!$provider instanceof ModuleProvider) {
            throw new \RuntimeException(
                "module.php at [$moduleDir] must return an instance of Core\\Module\\ModuleProvider"
            );
        }

        $name = $knownName ?? $provider->name();

        // First-write-wins. If a name has already been registered from
        // an earlier root, leave it in place. Log so the SA can see the
        // collision in error_log without it being fatal.
        if (isset($this->providers[$name])) {
            error_log(sprintf(
                '[ModuleRegistry] duplicate module "%s" — keeping %s, ignoring %s',
                $name,
                $this->paths[$name],
                $moduleDir
            ));
            return;
        }

        $this->providers[$name] = $provider;
        $this->paths[$name]     = $moduleDir;
        // Also key by folder basename so the autoloader can resolve
        // Modules\<Folder>\... → moduleDir even when name() differs
        // from the folder (e.g. folder 'importexport', name 'import_export').
        $this->folderPaths[strtolower(basename($moduleDir))] = $moduleDir;

        if ($viewsPath = $provider->viewsPath()) {
            if (is_dir($viewsPath)) {
                View::addNamespace($name, $viewsPath);
            }
        }

        $provider->register($this->container);
    }

    /**
     * Return the cached manifest or null if absent/unreadable. Fails soft —
     * a broken cache never blocks the app; scan is the fallback.
     *
     * @return array<string, string>|null  moduleName => absolute moduleDir
     */
    private function loadCache(): ?array
    {
        if (!defined('BASE_PATH')) return null;
        $path = BASE_PATH . '/' . self::DEFAULT_CACHE_FILE;
        if (!is_file($path)) return null;

        try {
            $data = require $path;
        } catch (\Throwable) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Write the current module manifest to disk. Called from the
     * `module:cache` artisan command after a deploy.
     *
     * Accepts either a single root (legacy callers) or an array of
     * roots. Roots that don't exist on disk are silently skipped — a
     * production deploy of core-only WITHOUT the premium repo mounted
     * will dump a cache that contains only core modules, which is the
     * intended behaviour.
     *
     * @param string|string[] $modulesDirs  directory or directories to scan
     * @param string          $cacheFile    absolute path to write
     * @return array<string, string>  the list that was written (name => dir)
     */
    public function dumpCache(string|array $modulesDirs, string $cacheFile): array
    {
        $dirs = is_array($modulesDirs) ? $modulesDirs : [$modulesDirs];

        // Do a fresh scan across every root to collect names. We can't
        // just look at $this->providers because in a normal request the
        // registry may have been built from a stale cache; regenerating
        // requires a fresh disk scan.
        $entries = [];
        foreach ($dirs as $modulesDir) {
            if (!is_string($modulesDir) || !is_dir($modulesDir)) continue;
            foreach (new \DirectoryIterator($modulesDir) as $entry) {
                if ($entry->isDot() || !$entry->isDir()) continue;
                $dir  = $entry->getPathname();
                $file = $dir . '/module.php';
                if (!is_file($file)) continue;

                /** @var ModuleProvider $provider */
                $provider = require $file;
                if (!$provider instanceof ModuleProvider) continue;
                $name = $provider->name();
                // First-root-wins, mirroring discover()'s collision rule.
                if (!isset($entries[$name])) {
                    $entries[$name] = $dir;
                }
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $export = var_export($entries, true);
        $header = "<?php\n// Generated by `php artisan module:cache` — do not edit by hand.\n// Safe to delete; the registry falls back to a filesystem scan.\n";
        file_put_contents($cacheFile, $header . "return $export;\n", LOCK_EX);

        return $entries;
    }

    /**
     * Include each ACTIVE module's routes file so route registrations land
     * on the shared Router. Modules that failed dependency checks are
     * skipped — their routes never reach the router, so a half-installed
     * module can't accidentally expose endpoints.
     *
     * Module routes are loaded BEFORE routes/web.php; web.php wins on
     * collision (last writer wins in the router's ordered list).
     */
    public function loadRoutes(Router $router): void
    {
        $this->resolveDependencies();
        foreach ($this->active as $provider) {
            $routesFile = $provider->routesFile();
            if ($routesFile && is_file($routesFile)) {
                // $router and $container are in scope for the required file
                $container = $this->container;
                require $routesFile;
            }
        }
    }

    /** Call boot() on every ACTIVE provider. Final phase of bootstrap. */
    public function boot(Router $router): void
    {
        $this->resolveDependencies();
        foreach ($this->active as $provider) {
            $provider->boot($router, $this->container);
        }
    }

    /**
     * Run the dependency checker once per request, populate $active +
     * $skipped, persist module_status changes, and dispatch SA
     * notifications on state transitions. Called automatically from
     * loadRoutes() / boot() / blockRegistry() — safe to call ad hoc.
     *
     * Heavy lifting (DB write, notification dispatch) is wrapped in
     * try/catch so a transient DB failure doesn't break bootstrap.
     */
    public function resolveDependencies(): void
    {
        if ($this->resolved) return;
        $this->resolved = true;

        // Read admin-disabled state from module_status BEFORE running
        // the dep checker. Admin-disable wins — those modules are
        // pulled out of the input set entirely, which causes any
        // dependents to cascade into $skipped with missing=[name].
        $adminDisabledNames = $this->fetchAdminDisabledNames();
        $this->adminDisabled = [];
        $this->unlicensed    = [];

        // Resolve the entitlement check once per request. Falls back to
        // AlwaysGrantEntitlement when the contract isn't bound — that's
        // the open-source / self-hosted default.
        $entitlement = $this->resolveEntitlementCheck();

        $candidates = [];
        foreach ($this->providers as $name => $provider) {
            if (in_array($name, $adminDisabledNames, true)) {
                $this->adminDisabled[$name] = $provider;
                continue;
            }
            // Premium modules go through the entitlement gate. A `false`
            // return removes them from the candidate set BEFORE the
            // dependency check, so any module that requires them
            // cascades into 'disabled_dependency' the same way an
            // admin-disable would. Core modules bypass the gate.
            if ($provider->tier() === 'premium' && !$entitlement->isEntitled($name)) {
                $this->unlicensed[$name] = [
                    'provider' => $provider,
                    'missing'  => ['(unlicensed)'],
                ];
                continue;
            }
            $candidates[$name] = $provider;
        }

        $result        = (new DependencyChecker())->check($candidates);
        $this->active  = $result['active'];
        $this->skipped = $result['skipped'];

        try {
            $this->persistAndNotify();
        } catch (\Throwable $e) {
            // Status persistence is observability, not critical path —
            // never let a DB hiccup take down the request.
            error_log('[ModuleRegistry] persistAndNotify failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull the EntitlementCheck binding from the container, falling
     * back to AlwaysGrantEntitlement so that an open-source install
     * with no premium-licensing wiring still boots cleanly.
     *
     * The method is structured to never raise — a missing or broken
     * binding silently degrades to "grant everything", which is the
     * right default for self-hosted installs but is something the
     * future hosted builder will want to override explicitly.
     */
    private function resolveEntitlementCheck(): EntitlementCheck
    {
        try {
            $bound = $this->container->get(EntitlementCheck::class);
            if ($bound instanceof EntitlementCheck) return $bound;
        } catch (\Throwable) {
            // Not bound — fall through to the default below.
        }
        return new AlwaysGrantEntitlement();
    }

    /**
     * Read every module currently marked `disabled_admin` in the
     * module_status table. Returns [] when the table doesn't exist
     * yet (fresh install, pre-migrate) so bootstrap stays robust.
     *
     * @return string[]
     */
    private function fetchAdminDisabledNames(): array
    {
        if (!class_exists(\Core\Database\Database::class)) return [];
        try {
            $db = \Core\Database\Database::getInstance();
        } catch (\Throwable) {
            return [];
        }
        try {
            $exists = (bool) $db->fetchColumn(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'module_status' LIMIT 1"
            );
        } catch (\Throwable) {
            return [];
        }
        if (!$exists) return [];

        try {
            $rows = $db->fetchAll(
                "SELECT module_name FROM module_status WHERE state = 'disabled_admin'"
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map(fn($r) => (string) $r['module_name'], $rows);
    }

    /**
     * Build the BlockRegistry by collecting blocks from every active
     * provider. Lazily computed and memoized for the request. Modules
     * that got skipped don't contribute — that's the whole point of
     * the gate.
     */
    public function blockRegistry(): BlockRegistry
    {
        $this->resolveDependencies();

        static $registry = null;
        if ($registry !== null) return $registry;

        $registry = new BlockRegistry();
        foreach ($this->active as $provider) {
            $blocks = $provider->blocks();
            if (!empty($blocks)) {
                $registry->registerMany($blocks);
            }
        }
        return $registry;
    }

    /** @return array<string, array{provider: ModuleProvider, missing: string[]}> */
    public function skippedModules(): array
    {
        $this->resolveDependencies();
        return $this->skipped;
    }

    /**
     * Modules an SA has explicitly disabled. Resolved per request from
     * module_status (state='disabled_admin'). Used by /admin/modules
     * to render the "Enable" button for the right rows.
     *
     * @return ModuleProvider[]  name => provider
     */
    public function adminDisabledModules(): array
    {
        $this->resolveDependencies();
        return $this->adminDisabled;
    }

    /**
     * Mark a module as admin-disabled. Writes module_status (state →
     * 'disabled_admin') + audit_log via Auth. Idempotent: re-disabling
     * an already-disabled module just refreshes updated_at. Returns
     * true on a state transition (so the caller can flash an
     * appropriate message), false if it was already disabled or
     * doesn't exist.
     *
     * Cascading effects (modules that require this one becoming
     * disabled_dependency) happen on the NEXT request when
     * resolveDependencies runs again with the new admin-disabled set.
     */
    public function disableByAdmin(string $name, ?string $note = null): bool
    {
        if (!isset($this->providers[$name])) return false;

        $db = \Core\Database\Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT state FROM module_status WHERE module_name = ?",
            [$name]
        );
        $wasAlreadyDisabled = $existing && (string) $existing['state'] === 'disabled_admin';

        $db->query(
            "INSERT INTO module_status (module_name, state, missing_deps, notice)
             VALUES (?, 'disabled_admin', NULL, ?)
             ON DUPLICATE KEY UPDATE
                state        = 'disabled_admin',
                missing_deps = NULL,
                notice       = VALUES(notice)",
            [$name, $note]
        );

        try {
            \Core\Auth\Auth::getInstance()->auditLog(
                'module.disabled_by_admin',
                'modules', null, null,
                ['module' => $name, 'note' => $note]
            );
        } catch (\Throwable $e) {
            error_log('[ModuleRegistry] disable audit failed: ' . $e->getMessage());
        }

        // Reset memoization so the very next call to resolveDependencies
        // (e.g. on a redirect render) sees the change instead of the
        // pre-disable cached result.
        $this->resolved = false;

        return !$wasAlreadyDisabled;
    }

    /**
     * Re-enable a module the SA had previously disabled. Sets state
     * back to 'active' (the dep checker on the next request will
     * promote it to 'disabled_dependency' if its requirements aren't
     * satisfied — same path as a freshly-installed module).
     *
     * Returns true on a state transition, false otherwise.
     */
    public function enableByAdmin(string $name): bool
    {
        if (!isset($this->providers[$name])) return false;

        $db = \Core\Database\Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT state FROM module_status WHERE module_name = ?",
            [$name]
        );
        $wasDisabled = $existing && (string) $existing['state'] === 'disabled_admin';
        if (!$wasDisabled) return false;

        $db->query(
            "UPDATE module_status
                SET state = 'active', notice = NULL, missing_deps = NULL
              WHERE module_name = ?",
            [$name]
        );

        try {
            \Core\Auth\Auth::getInstance()->auditLog(
                'module.enabled_by_admin',
                'modules', null, null,
                ['module' => $name]
            );
        } catch (\Throwable $e) {
            error_log('[ModuleRegistry] enable audit failed: ' . $e->getMessage());
        }

        $this->resolved = false;
        return true;
    }

    /**
     * Update module_status rows to reflect the latest dep-check result
     * and notify superadmins about NEW transitions to disabled. Called
     * once per request from resolveDependencies().
     *
     * Notification dedup: we read the previous state from module_status
     * before writing, and only fire a notification when this module's
     * state changed (or the row didn't exist yet AND the new state is
     * disabled). That keeps the bell + email quiet on steady-state runs.
     */
    private function persistAndNotify(): void
    {
        // Defer DB access until after the container is fully built —
        // the migrations command boots a registry instance before the
        // DB connection exists, and we don't need persistence in that
        // path. Database::getInstance() returns null when uninitialised.
        if (!class_exists(\Core\Database\Database::class)) return;
        try {
            $db = \Core\Database\Database::getInstance();
        } catch (\Throwable) {
            return;
        }

        // Skip silently when the table doesn't exist yet (fresh install
        // before migrations have been run, or running the migrator itself).
        try {
            $exists = (bool) $db->fetchColumn(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'module_status' LIMIT 1"
            );
        } catch (\Throwable) {
            return;
        }
        if (!$exists) return;

        // Pull existing rows so we can diff state transitions.
        $existing = [];
        foreach ($db->fetchAll("SELECT module_name, state FROM module_status") as $row) {
            $existing[$row['module_name']] = $row['state'];
        }

        $newlyDisabled = []; // [name => missing[]]

        foreach ($this->active as $name => $_) {
            $prev = $existing[$name] ?? null;
            if ($prev !== 'active') {
                $db->query(
                    "INSERT INTO module_status (module_name, state, missing_deps, notice)
                     VALUES (?, 'active', NULL, NULL)
                     ON DUPLICATE KEY UPDATE state = 'active', missing_deps = NULL, notice = NULL",
                    [$name]
                );
            }
        }

        foreach ($this->skipped as $name => $info) {
            $prev = $existing[$name] ?? null;
            $missingJson = json_encode(array_values($info['missing']));
            if ($prev !== 'disabled_dependency') {
                $db->query(
                    "INSERT INTO module_status (module_name, state, missing_deps, notice)
                     VALUES (?, 'disabled_dependency', ?, NULL)
                     ON DUPLICATE KEY UPDATE
                        state        = 'disabled_dependency',
                        missing_deps = VALUES(missing_deps),
                        notice       = NULL",
                    [$name, $missingJson]
                );
                $newlyDisabled[$name] = $info['missing'];
            }
        }

        // Premium modules whose entitlement was denied this request.
        // These are tracked separately from disabled_dependency so the
        // admin UI can show "Upgrade your plan" instead of the generic
        // "missing deps" message — and so a future re-license is a
        // clean state transition.
        foreach ($this->unlicensed as $name => $info) {
            $prev = $existing[$name] ?? null;
            if ($prev !== 'disabled_unlicensed') {
                $db->query(
                    "INSERT INTO module_status (module_name, state, missing_deps, notice)
                     VALUES (?, 'disabled_unlicensed', NULL, NULL)
                     ON DUPLICATE KEY UPDATE
                        state        = 'disabled_unlicensed',
                        missing_deps = NULL,
                        notice       = NULL",
                    [$name]
                );
                // Intentionally NOT added to $newlyDisabled — entitlement
                // denials are a billing/license concern, not a deploy
                // health concern, so we don't email superadmins on every
                // policy change.
            }
        }

        // Notify superadmins about transitions only — silent on steady state.
        if (!empty($newlyDisabled)) {
            $this->notifySuperadminsOfDisabledModules($newlyDisabled);
        }
    }

    /**
     * Fire one in-app notification per superadmin per newly-disabled
     * module, with email tagging when the SA email-on-events setting
     * is enabled.
     *
     * @param array<string, string[]> $newlyDisabled name => missing deps
     */
    private function notifySuperadminsOfDisabledModules(array $newlyDisabled): void
    {
        try {
            $db = \Core\Database\Database::getInstance();
        } catch (\Throwable) {
            return;
        }

        $admins = $db->fetchAll(
            "SELECT id, email FROM users WHERE is_superadmin = 1 AND is_active = 1"
        );
        if (empty($admins)) return;

        // Email channel only if the opt-in setting is on (defaults true).
        // NOTE: NotificationService::send() doesn't actually dispatch email
        // — it just stores the channel string in the notifications.channel
        // column for record-keeping. To get email out the door we have to
        // call MailService::send() explicitly. The 'channels' string we
        // pass below is informational; the actual email dispatch is the
        // separate MailService call further down.
        $emailEnabled = false;
        try {
            $emailEnabled = (bool) (new \Core\Services\SettingsService())
                ->get('module_disabled_email_to_sa_enabled', true, 'site');
        } catch (\Throwable) {
            // Setting layer missing in early boot? Default to in-app only.
        }
        $channels = $emailEnabled ? 'in_app,email' : 'in_app';

        $notify = new \Core\Services\NotificationService();
        $mailer = $emailEnabled ? new \Core\Services\MailService() : null;

        foreach ($newlyDisabled as $name => $missing) {
            $missingList = implode(', ', $missing);
            $title = "Module \"$name\" disabled (missing dependencies)";
            $bodyText = "The module \"$name\" was skipped at boot because it requires "
                      . "modules that aren't currently active: $missingList. "
                      . "Install or re-enable the missing modules and the next request "
                      . "will reactivate \"$name\". See /admin/modules for the full list.";

            // HTML body for the email — MailService::send wants HTML; the
            // text version is auto-derived from the strip_tags fallback in
            // PHPMailer when we don't pass one explicitly. We do pass one
            // so the plain-text alternative reads cleanly.
            $bodyHtml = '<p>The module <strong>' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5)
                      . '</strong> was skipped at boot because it requires modules that aren\'t currently active: <code>'
                      . htmlspecialchars($missingList, ENT_QUOTES | ENT_HTML5) . '</code>.</p>'
                      . '<p>Install or re-enable the missing modules and the next request will reactivate <strong>'
                      . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5) . '</strong>. '
                      . '<a href="' . htmlspecialchars((string) (config('app.url') ?? '') . '/admin/modules', ENT_QUOTES | ENT_HTML5)
                      . '">Open /admin/modules</a> for the full list.</p>';

            foreach ($admins as $admin) {
                // In-app notification (always fired)
                try {
                    $notify->send(
                        (int) $admin['id'],
                        'module.disabled_dependency',
                        $title,
                        $bodyText,
                        ['module' => $name, 'missing' => $missing],
                        $channels
                    );
                } catch (\Throwable $e) {
                    error_log("[ModuleRegistry] in-app notify SA {$admin['id']} failed: " . $e->getMessage());
                }

                // Email — only when the setting is on AND the admin has
                // an email on file. MailService is best-effort; failures
                // log but don't propagate (we don't want a mail outage to
                // break /dashboard for SAs).
                if ($mailer !== null && !empty($admin['email'])) {
                    try {
                        $mailer->send((string) $admin['email'], $title, $bodyHtml, $bodyText);
                    } catch (\Throwable $e) {
                        error_log("[ModuleRegistry] email SA {$admin['email']} failed: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /** @return string[] directories the migrator should also scan */
    public function migrationPaths(): array
    {
        $paths = [];
        foreach ($this->providers as $provider) {
            if ($p = $provider->migrationsPath()) {
                if (is_dir($p)) $paths[] = $p;
            }
        }
        return $paths;
    }

    /** @return ModuleProvider[] — useful for introspection / admin UIs */
    public function all(): array
    {
        return $this->providers;
    }

    public function get(string $name): ?ModuleProvider
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Register a PSR-4 autoloader for Modules\{Name}\...
     *
     *   Modules\Pages\Controllers\PageController
     *   → <core or premium root>/modules/pages/Controllers/PageController.php
     *
     * Module directory names are lowercased; class namespace uses StudlyCase.
     * We resolve both conventions by normalizing the segment after
     * "Modules\" to lowercase and looking up the per-module path in
     * $this->paths (populated by discover() across every root).
     *
     * Multi-root support: the closure captures $this->paths by reference
     * so that modules registered in a SECOND discover() call (from a
     * second root) autoload correctly without re-registering the loader.
     */
    private function registerAutoloader(): void
    {
        if ($this->autoloaderRegistered) return;
        $this->autoloaderRegistered = true;

        // Capture by reference so modules registered by a LATER
        // discover() call (a second root, etc.) become resolvable
        // without re-registering the loader.
        $folderPaths = &$this->folderPaths;

        spl_autoload_register(function (string $class) use (&$folderPaths): void {
            if (!str_starts_with($class, 'Modules\\')) return;

            $parts = explode('\\', $class);
            array_shift($parts); // drop 'Modules'
            if (empty($parts)) return;

            $folder = strtolower(array_shift($parts));
            if (!isset($folderPaths[$folder])) return;

            $tail = implode('/', $parts) . '.php';
            $file = $folderPaths[$folder] . '/' . $tail;

            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Roots that have been passed to discover()/discoverMany(). Useful
     * for /admin/modules to show how the registry was configured at
     * boot, and for diagnostics when a premium module is missing.
     *
     * @return string[]
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Premium modules that were skipped because EntitlementCheck
     * returned false this request. Same shape as skippedModules().
     *
     * @return array<string, array{provider: ModuleProvider, missing: string[]}>
     */
    public function unlicensedModules(): array
    {
        $this->resolveDependencies();
        return $this->unlicensed;
    }

    /**
     * Tier of a discovered module, or null if unknown. Convenience for
     * /admin/modules and the future builder.
     */
    public function tier(string $name): ?string
    {
        $p = $this->providers[$name] ?? null;
        return $p ? $p->tier() : null;
    }
}
