<?php
// modules/retention/module.php
use Core\Module\ModuleProvider;

/**
 * Retention module — time-based purge / anonymise sweeper.
 *
 * Each module's `retentionRules()` declaration contributes one or more
 * RetentionRule value objects. RetentionRegistry walks every active
 * module + adds framework-core defaults for tables shipped by
 * install.sql which aren't owned by any single module.
 *
 * RetentionService::sync() reads the registry and inserts rows into
 * retention_rules on first run. Subsequent syncs leave existing rows
 * alone — admin overrides at /admin/retention always win.
 *
 * RetentionSweepJob runs daily and applies every enabled rule. Wire
 * via:
 *
 *   php artisan schedule:create retention.sweep daily
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'retention'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    /**
     * GDPR handlers — retention_runs is operational telemetry. On
     * user erasure (an admin running the purge), the row's
     * triggered_by FK already SET NULLs via the schema constraint;
     * nothing else to do. Declare nothing here.
     */
    public function gdprHandlers(): array { return []; }

    /**
     * Retention rules contributed by THIS module — keep retention_runs
     * for 1 year (the row is operational evidence the sweep runs;
     * indefinite retention would defeat the purpose of the module).
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'retention.runs.old',
                module:      'retention',
                label:       'Old retention-run history',
                tableName:   'retention_runs',
                whereClause: 'started_at < {cutoff}',
                daysKeep:    365,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'started_at',
                description: 'The sweeper\'s own run history. 1y is enough to prove "we ran the sweep daily" for any audit window.',
            ),
        ];
    }
};
