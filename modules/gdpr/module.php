<?php
// modules/gdpr/module.php
use Core\Module\ModuleProvider;

/**
 * GDPR / DSAR module.
 *
 * Owns three user surfaces — `/account/data` (self-service export +
 * erasure + restriction) and `/admin/gdpr` (DSAR queue + per-user
 * actions) and `/admin/gdpr/handlers` (registry inspection) — plus
 * the underlying GdprRegistry, DataExporter, DataPurger, and DsarService.
 *
 * Other modules participate by overriding ModuleProvider::gdprHandlers()
 * on their own module.php to declare which of their tables hold user
 * data. The registry walks every active module's declarations + the
 * framework-core defaults declared by GdprRegistry::coreHandlers().
 *
 * Two scheduled jobs (PurgeUserJob hourly, PurgeExpiredExportsJob
 * daily) drive the long-running parts of the lifecycle. They live
 * under Jobs/ and are wired into the scheduler via the schedule
 * runner — register them on a fresh install with:
 *
 *   php artisan schedule:create gdpr.purge_users hourly
 *   php artisan schedule:create gdpr.purge_exports daily
 *
 * (or admins can wire them up via /admin/scheduling once that surface
 * is integrated with the queue dispatcher.)
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'gdpr'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Drop-on-any-page link to the user's data page. Useful in a
            // privacy footer, a "manage my data" CTA in account chrome,
            // or a settings page sidebar.
            new \Core\Module\BlockDescriptor(
                key:         'gdpr.account_data_link',
                label:       'Your data &amp; privacy (link)',
                description: 'Inline link to /account/data. Drop into a footer or account-settings page.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: ['label' => 'Your data &amp; privacy'],
                audience:    'auth',
                settingsSchema: [
                    ['key' => 'label', 'label' => 'Link text', 'type' => 'text', 'default' => 'Your data & privacy'],
                ],
                render: function (array $context, array $settings): string {
                    if (\Core\Auth\Auth::getInstance()->guest()) return '';
                    $label = (string) ($settings['label'] ?? 'Your data & privacy');
                    return '<a href="/account/data" style="color:var(--color-primary,#4f46e5);text-decoration:none;font-size:13px">'
                         . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5)
                         . '</a>';
                }
            ),
        ];
    }

    /**
     * The gdpr module itself doesn't own any user-bearing tables (its
     * own dsar_requests / data_exports rows are purged via FK SET NULL
     * on the users FK). So this returns []. The framework-wide core
     * tables are declared centrally in GdprRegistry::coreHandlers().
     */
    public function gdprHandlers(): array { return []; }

    /**
     * Retention rules for the GDPR module's own tables. These are
     * defaults that an admin can edit at /admin/retention.
     *
     *   - data_exports rows are kept 90 days as audit evidence ("user
     *     X exported their data on date Y") even after the zip is
     *     purged at expires_at by PurgeExpiredExportsJob (7 days).
     *   - dsar_requests rows are anonymised after 7 years per typical
     *     limitation-period statute. Keeping the row preserves "we
     *     processed N DSARs in Q3 2026" reporting; the requester PII
     *     is dropped.
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'gdpr.data_exports.audit_old',
                module:      'gdpr',
                label:       'Old data-export audit rows',
                tableName:   'data_exports',
                whereClause: 'requested_at < {cutoff}',
                daysKeep:    90,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'requested_at',
                description: 'data_exports rows older than 90 days. The zip files themselves are purged at expires_at by PurgeExpiredExportsJob; this drops the audit row after another quarter.',
            ),
            new \Modules\Retention\Services\RetentionRule(
                key:         'gdpr.dsar_requests.anonymize_old',
                module:      'gdpr',
                label:       'Anonymise old DSAR requests',
                tableName:   'dsar_requests',
                whereClause: 'requested_at < {cutoff}',
                daysKeep:    2555, // 7 years
                action:      \Modules\Retention\Services\RetentionRule::ACTION_ANONYMIZE,
                anonymizeColumns: [
                    'requester_email' => '[anonymised]',
                    'requester_name'  => null,
                    'ip_address'      => null,
                    'notes'           => null,
                ],
                dateColumn:  'requested_at',
                description: 'DSAR requests anonymised after 7 years (typical limitation period). Aggregate counts stay queryable; requester PII goes.',
            ),
        ];
    }
};
