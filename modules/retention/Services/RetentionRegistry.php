<?php
// modules/retention/Services/RetentionRegistry.php
namespace Modules\Retention\Services;

use Core\Module\ModuleRegistry;

/**
 * Discovers RetentionRule[] declarations from every active module +
 * adds framework-core defaults for tables shipped by install.sql which
 * aren't owned by any single module (audit_log, login_attempts,
 * sessions, password_resets, message_log, two_factor_challenges,
 * email_verifications).
 *
 * The discovery is read-only — it just produces the list of rules.
 * RetentionService consumes that list to sync the retention_rules
 * table on first run.
 */
class RetentionRegistry
{
    private ModuleRegistry $modules;
    /** @var RetentionRule[]|null */
    private ?array $cache = null;

    public function __construct(?ModuleRegistry $modules = null)
    {
        $this->modules = $modules ?? \Core\Container\Container::global()->get(ModuleRegistry::class);
    }

    /** @return RetentionRule[] */
    public function all(): array
    {
        if ($this->cache !== null) return $this->cache;

        $rules = $this->coreRules();

        foreach ($this->modules->all() as $modName => $provider) {
            if (in_array($modName, array_keys($this->modules->skippedModules()), true)) continue;
            if (in_array($modName, array_keys($this->modules->adminDisabledModules()), true)) continue;

            try {
                $declared = $provider->retentionRules();
            } catch (\Throwable $e) {
                error_log("RetentionRegistry: retentionRules() failed for {$modName}: " . $e->getMessage());
                continue;
            }

            foreach ($declared as $r) {
                if (!$r instanceof RetentionRule) continue;
                $rules[] = $r;
            }
        }

        return $this->cache = $rules;
    }

    /**
     * Framework-core retention defaults. These cover tables shipped
     * by database/install.sql that aren't owned by any single module.
     *
     * Defaults are intentionally conservative — admins should look at
     * /admin/retention and tighten where their compliance posture
     * permits (or relax where their regulatory environment requires
     * longer retention).
     *
     * @return RetentionRule[]
     */
    private function coreRules(): array
    {
        return [
            // Sessions — DB sessions table. Expired rows are useless;
            // 30 days past their last_activity is a generous keep
            // (gives admins log/forensic data without bloat).
            new RetentionRule(
                key:         'core.security.sessions.expired',
                module:      'core.security',
                label:       'Expired sessions',
                tableName:   'sessions',
                whereClause: 'last_activity < UNIX_TIMESTAMP({cutoff})',
                daysKeep:    30,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'last_activity',
                description: 'Sessions inactive for >N days. last_activity is stored as a Unix timestamp.',
            ),

            // Login attempts — keep 90 days for forensics; older rows
            // can't help with current attack patterns.
            new RetentionRule(
                key:         'core.security.login_attempts.old',
                module:      'core.security',
                label:       'Old login attempts',
                tableName:   'login_attempts',
                whereClause: 'attempted_at < {cutoff}',
                daysKeep:    90,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'attempted_at',
                description: 'Forensic data older than ~3 months has rarely-cited value; tighten if disk usage permits.',
            ),

            // Password resets — short-lived, mostly used or expired.
            new RetentionRule(
                key:         'core.security.password_resets.expired',
                module:      'core.security',
                label:       'Used/expired password reset tokens',
                tableName:   'password_resets',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    30,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'created_at',
            ),

            // Two-factor challenges — even shorter lifespan; once
            // consumed or expired, no audit value.
            new RetentionRule(
                key:         'core.security.two_factor_challenges.old',
                module:      'core.security',
                label:       'Stale 2FA challenges',
                tableName:   'two_factor_challenges',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    7,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'created_at',
            ),

            // Email verifications — stale tokens
            new RetentionRule(
                key:         'core.security.email_verifications.old',
                module:      'core.security',
                label:       'Old email verification tokens',
                tableName:   'email_verifications',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    60,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'created_at',
            ),

            // Message log — outbound emails/SMS metadata. Kept 1 year
            // for delivery dispute/spam-complaint window, then dropped.
            new RetentionRule(
                key:         'core.notifications.message_log.old',
                module:      'core.notifications',
                label:       'Old message-log entries',
                tableName:   'message_log',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    365,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'created_at',
                description: 'Outbound mail/SMS metadata. 1y covers most provider dispute / complaint windows.',
            ),

            // Notifications — once read + week, drop.
            new RetentionRule(
                key:         'core.notifications.read.old',
                module:      'core.notifications',
                label:       'Read notifications older than 90 days',
                tableName:   'notifications',
                whereClause: 'read_at IS NOT NULL AND read_at < {cutoff}',
                daysKeep:    90,
                action:      RetentionRule::ACTION_PURGE,
                dateColumn:  'read_at',
            ),

            // Audit log — anonymise the actor PII (IP, UA) on rows
            // older than 2 years, but KEEP the row. Action type +
            // timestamp + actor_user_id stay, just the request-level
            // identifying data goes. Most audit-defensible balance.
            new RetentionRule(
                key:         'core.audit.log.anonymize_old',
                module:      'core.audit',
                label:       'Anonymise old audit-log PII',
                tableName:   'audit_log',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    730, // 2 years
                action:      RetentionRule::ACTION_ANONYMIZE,
                anonymizeColumns: [
                    'ip_address' => null,
                    'user_agent' => null,
                ],
                dateColumn:  'created_at',
                description: 'Drops IP + User-Agent on audit rows >2y old; keeps the action / actor / timestamp triple as compliance evidence. SOC2 evaluators expect ~2y minimum.',
            ),

            // Cookie consent — one row per accept/reject/withdraw.
            // Once >2 years stale, anonymise IP + UA. The consent
            // record itself stays as proof the cookie banner was used.
            new RetentionRule(
                key:         'core.consent.cookie_consents.anonymize_old',
                module:      'core.consent',
                label:       'Anonymise old cookie consent rows',
                tableName:   'cookie_consents',
                whereClause: 'created_at < {cutoff}',
                daysKeep:    730,
                action:      RetentionRule::ACTION_ANONYMIZE,
                anonymizeColumns: [
                    'ip_address' => null,
                    'user_agent' => null,
                ],
                dateColumn:  'created_at',
                description: 'Same shape as audit_log retention; cookie consent records stay as compliance evidence with PII scrubbed.',
            ),
        ];
    }
}
