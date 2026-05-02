<?php
// modules/security/module.php
use Core\Module\ModuleProvider;

/**
 * Security module — landing place for security-hardening features that
 * sit alongside the existing auth + sessions infrastructure.
 *
 * Currently ships:
 *   - PasswordBreachService — HIBP "Have I Been Pwned" k-anonymity
 *     check, invoked from AuthController::register, AuthController::resetPassword,
 *     and UserController::store / update. Never blocks on network
 *     failure (fail-open). Cached per SHA-1 prefix for 24h to keep
 *     popular-password queries off the network.
 *
 * Settings live under scope='site' on the existing /admin/settings/security
 * page (the Settings module's SECURITY_KEYS array is extended to
 * include them):
 *
 *   password_breach_check_enabled   master on/off (default: on)
 *   password_breach_check_block     true = block, false = warn (default: block)
 *
 * The integration in the controllers uses class_exists guards, so an
 * install that hasn't run this module's migration continues to work
 * unchanged — the breach check just doesn't run.
 *
 * Future homes for this module (each a self-contained follow-up):
 *   - Sliding session inactivity timeout (configurable + middleware)
 *   - Admin IP allowlist (CIDR list + middleware on /admin/*)
 *   - Login anomaly detection (geo, impossible travel)
 *   - HIBP breach-corpus refresh sweep (re-check stored hashes against
 *     freshly-published breach data on a schedule)
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'security'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function gdprHandlers(): array { return []; }

    /**
     * Retention rule for the HIBP cache. The retention sweeper purges
     * expired rows every day so the cache table doesn't grow without
     * bound. Strictly speaking PasswordBreachService::purgeExpired()
     * does the same on-demand, but routing it through retention gives
     * us scheduled execution without a separate cron entry.
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'security.password_breach_cache.expired',
                module:      'security',
                label:       'Expired HIBP breach-cache rows',
                tableName:   'password_breach_cache',
                whereClause: 'expires_at < {cutoff}',
                daysKeep:    0, // any row past expires_at is purgeable
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'expires_at',
                description: 'HIBP /range/ cache rows. Once expires_at is in the past, the cached body is stale and a fresh fetch will refresh it on next check; old rows can go.',
            ),
        ];
    }
};
