<?php
// core/Module/ModuleProvider.php
// Multi-tier module base class — see tier() and EntitlementCheck.
namespace Core\Module;

use Core\Container\Container;
use Core\Router\Router;

/**
 * Base class for a module's `module.php` entrypoint.
 *
 * A module lives at modules/{name}/ and ships a module.php like:
 *
 *   <?php
 *   use Core\Module\ModuleProvider;
 *
 *   return new class extends ModuleProvider {
 *       public function name(): string { return 'blog'; }
 *
 *       public function routesFile(): ?string {
 *           return __DIR__ . '/routes.php';
 *       }
 *
 *       public function viewsPath(): ?string {
 *           return __DIR__ . '/Views';
 *       }
 *
 *       public function migrationsPath(): ?string {
 *           return __DIR__ . '/migrations';
 *       }
 *
 *       public function register(Container $c): void {
 *           // Optional: bind module-specific services.
 *       }
 *
 *       public function boot(Router $router, Container $c): void {
 *           // Optional: programmatic setup that needs the router or container.
 *       }
 *   };
 *
 * The ModuleRegistry calls each hook at the right point during bootstrap.
 * Only `name()` is required; everything else has a sensible default (null).
 */
abstract class ModuleProvider
{
    /**
     * Unique, lowercase, snake-case identifier. Doubles as the view
     * namespace (e.g. `pages::show` resolves to modules/pages/Views/show.php).
     */
    abstract public function name(): string;

    /**
     * Path to the module's routes file, or null if it has none.
     * Registered after the router is built but before dispatch.
     */
    public function routesFile(): ?string { return null; }

    /**
     * Path to the module's Views directory, or null. When returned, the
     * ModuleRegistry registers it with View::addNamespace() under name().
     */
    public function viewsPath(): ?string { return null; }

    /**
     * Path to the module's migrations directory, or null. When returned,
     * artisan migrate picks it up alongside database/migrations/.
     */
    public function migrationsPath(): ?string { return null; }

    /**
     * Bind module-specific services into the container.
     * Called during discovery, before boot() and before routes load.
     */
    public function register(Container $c): void {}

    /**
     * Runtime wiring that needs the router or resolved dependencies.
     * Called after all modules have been registered and the routes file
     * has been pulled in — a good place for event listeners, view composers, etc.
     */
    public function boot(Router $router, Container $c): void {}

    /**
     * Artisan commands contributed by this module. Return an array of
     * Command instances OR class names — the registry resolves both.
     *
     *   public function commands(): array {
     *       return [
     *           \Modules\Blog\Console\ImportFeedCommand::class,
     *           \Modules\Blog\Console\RebuildIndexCommand::class,
     *       ];
     *   }
     */
    public function commands(): array { return []; }

    /**
     * Module names this module hard-depends on. The ModuleRegistry runs
     * a dependency check at boot before loading any routes, views, or
     * blocks — modules with unmet deps are skipped (logged + tracked in
     * `module_status`, optional SA notification dispatched).
     *
     *   public function requires(): array {
     *       return ['taxonomy', 'comments'];
     *   }
     *
     * Helper / support modules with no consumers and no required peers
     * leave this as the default empty array.
     *
     * Version constraints aren't supported in v1 — names only. The check
     * is non-fatal: a missing dep disables this module but the rest of
     * the app keeps running.
     *
     * @return string[]  module names (matching the strings other modules
     *                   return from name())
     */
    public function requires(): array { return []; }

    /**
     * Content blocks contributed by this module. Each entry is a
     * BlockDescriptor with a globally-unique key, a label, and a render
     * closure — the page composer indexes the result and can drop any
     * block onto any page layout.
     *
     *   public function blocks(): array {
     *       return [
     *           new \Core\Module\BlockDescriptor(
     *               key:         'groups.my_groups_tile',
     *               label:       'My Groups',
     *               description: 'Compact list of the viewer\'s groups.',
     *               category:    'Groups',
     *               render:      fn(array $ctx, array $s) => '...html...',
     *           ),
     *       ];
     *   }
     *
     * Helper / support modules that don't surface UI return [] (the
     * default). Returning [] keeps the module participating in the rest
     * of the framework — autoload, dependency declarations, services —
     * just without contributing anything to page composition.
     *
     * @return \Core\Module\BlockDescriptor[]
     */
    public function blocks(): array { return []; }

     /**
     * Link sources contributed by this module. Each entry is a fully-
     * qualified class name implementing \Core\Services\LinkSource. The
     * LinkSourceRegistry instantiates each lazily and surfaces its
     * items() output in the menu builder's "Internal" palette so admins
     * can pick from a single grouped catalogue rather than typing URLs
     * by hand.
     *
     *   public function linkSources(): array {
     *       return [
     *           \Modules\Forms\LinkSources\FormsLinkSource::class,
     *       ];
     *   }
     *
     * Modules with no public-facing URLs return [] (the default). One
     * module can contribute multiple sources if it has more than one
     * meaningfully-distinct URL category (e.g. a store module might
     * separate Products from Categories).
     *
     * @return string[] fully-qualified class names of LinkSource impls
     */
    public function linkSources(): array { return []; }

    /**
     * GDPR / DSAR handlers contributed by this module. Each handler
     * declares the module's user-bearing tables (and any custom export
     * or erase logic) so the GdprRegistry can drive both data export
     * and the user-erasure pipeline without each module re-implementing
     * either workflow.
     *
     *   public function gdprHandlers(): array {
     *       return [
     *           new \Modules\Gdpr\Services\GdprHandler(
     *               module:      'comments',
     *               description: 'Comments and reactions you posted.',
     *               tables: [
     *                   ['table' => 'comments',  'user_column' => 'user_id', 'action' => 'anonymize',
     *                    'anonymize_columns' => ['body' => '[content removed]']],
     *                   ['table' => 'reactions', 'user_column' => 'user_id', 'action' => 'erase'],
     *               ]
     *           ),
     *       ];
     *   }
     *
     * Modules that hold no PII (chrome-only modules, helpers) return []
     * (the default). Modules that hold data which legally cannot be
     * deleted (invoices for tax retention, audit_log) declare
     * `action: 'anonymize'` with a `legal_hold_reason` so admins can
     * see in /admin/gdpr/handlers why a row survives erasure.
     *
     * Custom export or erase needs (e.g. encrypted blobs, multi-table
     * joins, S3-stored attachments) can supply `customExport` and
     * `customErase` callables on the GdprHandler and skip the simple
     * tables[] declaration.
     *
     * @return \Modules\Gdpr\Services\GdprHandler[]
     */
    public function gdprHandlers(): array { return []; }

    /**
     * Data-retention rules contributed by this module. Each rule
     * declares ONE table + a date-based WHERE clause + a default
     * retention period. The retention module discovers all of these
     * on first sync and creates an editable `retention_rules` row;
     * admins can adjust days_keep, action, or disable the rule from
     * /admin/retention.
     *
     *   public function retentionRules(): array {
     *       return [
     *           new \Modules\Retention\Services\RetentionRule(
     *               key:         'forms.submissions.expired',
     *               module:      'forms',
     *               label:       'Old form submissions',
     *               tableName:   'form_submissions',
     *               whereClause: 'created_at < {cutoff}',
     *               daysKeep:    1825, // 5 years
     *               action:      'purge',
     *               dateColumn:  'created_at',
     *               description: 'Default 5y retention; many regulations require submission record-keeping for at least 3-5 years.',
     *           ),
     *       ];
     *   }
     *
     * Modules with no time-bounded data return [] (the default). The
     * `key` MUST be stable across releases — admins' edits are keyed
     * by it, so renaming the key resets their override. Format:
     * `{module}.{table}.{purpose}`.
     *
     * @return \Modules\Retention\Services\RetentionRule[]
     */
    public function retentionRules(): array { return []; }

    /**
     * Site-scope setting keys this module owns. Listed keys are HIDDEN
     * from the generic /admin/settings?scope=site grid because they're
     * meant to be edited from the module's dedicated admin page. The
     * generic grid would otherwise show the same setting in two places
     * — silently clobbering its `type` column on save and wiping it
     * entirely on delete.
     *
     * Only declare site-scope keys here. Per-user / per-group scope
     * settings don't appear in the generic grid in the first place.
     *
     * Example:
     *
     *   public function settingsKeys(): array {
     *       return [
     *           'store.reviews_enabled',
     *           'store.reviews_badge_in_listing',
     *       ];
     *   }
     *
     * @return string[]
     */
    public function settingsKeys(): array { return []; }

    /**
     * Distribution tier for this module — either 'core' or 'premium'.
     *
     * Core modules ship in the open-source claudephpframework repository
     * and are present in every install. Premium modules ship from the
     * separate claudephpframeworkpremium repository and load only when
     * (a) their files are on disk, AND (b) the EntitlementCheck contract
     * grants them. The default is 'core' so a module that forgets to
     * declare a tier is treated as open-source by definition.
     *
     * Concrete premium modules MUST override:
     *
     *   public function tier(): string { return 'premium'; }
     *
     * The framework's strictest invariant about tiers: core modules MUST
     * NOT depend on premium modules — neither via requires() nor via a
     * `Modules\Premium\...` namespace reference. The other direction is
     * fine; premium modules may freely depend on core.
     *
     * The future web-app builder uses this hook plus an EntitlementCheck
     * implementation to gate which premium modules a given tenant is
     * licensed to load.
     *
     * @return string  'core' or 'premium'
     */
    public function tier(): string { return 'core'; }
}
