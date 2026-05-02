<?php
// modules/loginanomaly/module.php
use Core\Module\ModuleProvider;

/**
 * Login anomaly detection module.
 *
 * Wires into the framework via:
 *
 *   1. Auth::notifyOnNewDeviceLogin — patched to call
 *      LoginAnomalyService::analyseLogin after the existing new-device
 *      email logic. Geo-aware additional check.
 *
 *   2. Public ip-api.com lookups for IP→geo, cached 30 days per IP
 *      in login_geo_cache. Free service, ~45 req/min limit. To swap
 *      to MaxMind GeoLite2 or similar, subclass GeoIpService.
 *
 *   3. /admin/security/anomalies — review surface for findings.
 *      Severity badges + ack flow.
 *
 *   4. Settings on /admin/settings/security:
 *      - login_anomaly_enabled              master toggle (default off)
 *      - login_anomaly_email_enabled        send "suspicious sign-in"
 *                                           email on warn/alert
 *      - login_anomaly_threshold_kmh        warn threshold (default 900)
 *      - login_anomaly_alert_threshold_kmh  alert threshold (default 2000)
 *
 * The integration in Auth uses class_exists, so an install without
 * this module continues to work unchanged.
 *
 * Default OFF because every login becomes an outbound HTTP call to
 * ip-api.com when enabled. Admins should opt in after thinking about
 * the rate-limit (~45 req/min per origin IP, fine for most sites).
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'loginanomaly'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    /**
     * GDPR — login_anomalies rows are anonymise-on-erasure (security
     * audit value retained, identity scrubbed). FK CASCADE on user_id
     * means rows go entirely if the user row is hard-deleted; the
     * default DataPurger only soft-scrubs, so this handler still
     * matters.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'loginanomaly',
                description: 'Detected sign-in anomalies (geo + impossible-travel) tied to your account.',
                tables: [
                    [
                        'table'             => 'login_anomalies',
                        'user_column'       => 'user_id',
                        'action'            => \Modules\Gdpr\Services\GdprHandler::ACTION_ANONYMIZE,
                        'anonymize_columns' => [
                            'ip_address' => null,
                            'user_agent' => null,
                            'city'       => null,
                            'prior_city' => null,
                        ],
                        'legal_hold_reason' => 'Anomaly history retained for site-safety audit; identifying details (IP, UA, city) scrubbed.',
                    ],
                ]
            ),
        ];
    }

    /**
     * Retention — purge old anomaly rows + expired geo cache entries.
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'loginanomaly.acknowledged_old',
                module:      'loginanomaly',
                label:       'Old acknowledged anomalies',
                tableName:   'login_anomalies',
                whereClause: 'acknowledged_at IS NOT NULL AND acknowledged_at < {cutoff}',
                daysKeep:    365,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'acknowledged_at',
                description: 'Acknowledged anomalies older than 1 year. Unacknowledged rows are NEVER purged automatically.',
            ),
            new \Modules\Retention\Services\RetentionRule(
                key:         'loginanomaly.geo_cache.expired',
                module:      'loginanomaly',
                label:       'Expired geo-IP cache',
                tableName:   'login_geo_cache',
                whereClause: 'expires_at < {cutoff}',
                daysKeep:    0,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'expires_at',
                description: 'Cached IP→geo lookups past their TTL. Refreshed on next lookup if still seen.',
            ),
        ];
    }
};
