<?php
// modules/gdpr/Services/GdprRegistry.php
namespace Modules\Gdpr\Services;

use Core\Module\ModuleRegistry;

/**
 * Discovers GdprHandler[] declarations from every active module's
 * `gdprHandlers()` hook. Plus injects framework-core handlers for the
 * tables shipped by install.sql (users, sessions, audit_log, etc.) which
 * aren't owned by any single module.
 *
 * Result is cached per-request — the discovery walks the active module
 * list once and the same set is reused across an export/purge run.
 */
class GdprRegistry
{
    /** @var GdprHandler[]|null */
    private ?array $cache = null;

    private ModuleRegistry $modules;

    public function __construct(?ModuleRegistry $modules = null)
    {
        // Optional inject — falls back to the global container if the
        // caller didn't pass one.
        $this->modules = $modules ?? \Core\Container\Container::global()->get(ModuleRegistry::class);
    }

    /** @return GdprHandler[] */
    public function all(): array
    {
        if ($this->cache !== null) return $this->cache;

        $handlers = $this->coreHandlers();

        foreach ($this->modules->all() as $modName => $provider) {
            // Skip modules that didn't survive dependency resolution or
            // were disabled by an admin — they shouldn't act on user
            // data while inactive.
            if (in_array($modName, array_keys($this->modules->skippedModules()), true)) continue;
            if (in_array($modName, array_keys($this->modules->adminDisabledModules()), true)) continue;

            try {
                $declared = $provider->gdprHandlers();
            } catch (\Throwable $e) {
                // A bad declaration on one module shouldn't block the
                // export/purge for the rest. Log and continue.
                error_log("GdprRegistry: gdprHandlers() failed for module {$modName}: " . $e->getMessage());
                continue;
            }

            foreach ($declared as $h) {
                if (!$h instanceof GdprHandler) continue;
                // Auto-set the module name if the declaration didn't
                // bother (the registry knows it from the provider).
                if ($h->module === '') $h->module = $modName;
                $handlers[] = $h;
            }
        }

        return $this->cache = $handlers;
    }

    /**
     * Handlers for the framework's own tables — declared centrally
     * because they aren't owned by any single module's module.php.
     *
     * The action choices here are the GDPR-defensible defaults:
     *   - audit_log:           anonymize the actor identifier, keep the row
     *                          (it's the framework's record of what was done)
     *   - sessions, password_resets, two_factor_*, login_attempts, email_verifications:
     *                          erase outright
     *   - users:               special-case — kept for FK integrity until
     *                          the very end of the purge, then row deleted
     *                          last by DataPurger. Not declared as a table
     *                          here.
     *   - notifications, message_log: erase
     *   - user_oauth, user_groups, user_roles: erase
     *
     * @return GdprHandler[]
     */
    private function coreHandlers(): array
    {
        return [
            new GdprHandler(
                module: 'core.identity',
                description: 'Your account profile (name, email, avatar, bio).',
                tables: [
                    // users handled separately by DataPurger so FK ordering works.
                    ['table' => 'user_oauth',           'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                    ['table' => 'user_roles',           'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                    ['table' => 'user_groups',          'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                ],
            ),

            new GdprHandler(
                module: 'core.security',
                description: 'Sessions, login attempts, password resets, 2FA challenges, email verifications.',
                tables: [
                    ['table' => 'sessions',                'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                    ['table' => 'password_resets',         'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE, 'export' => false],
                    ['table' => 'two_factor_challenges',   'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE, 'export' => false],
                    ['table' => 'email_verifications',     'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE, 'export' => false],
                    ['table' => 'login_attempts',          'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                    ['table' => 'api_keys',                'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                ],
            ),

            new GdprHandler(
                module: 'core.notifications',
                description: 'Notifications and messaging-log entries addressed to you.',
                tables: [
                    ['table' => 'notifications', 'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                    ['table' => 'message_log',   'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
                ],
            ),

            new GdprHandler(
                module: 'core.audit',
                description: 'Audit log entries you appear in. The row stays for the framework\'s own legal defense; PII is scrubbed.',
                tables: [
                    [
                        'table'       => 'audit_log',
                        'user_column' => 'actor_user_id',
                        'action'      => GdprHandler::ACTION_ANONYMIZE,
                        'anonymize_columns' => [
                            'ip_address' => null,
                            'user_agent' => null,
                        ],
                        'legal_hold_reason' => 'Audit log entries are retained for breach-investigation and SOX-style controls. Actor identity is anonymised but the row stays.',
                    ],
                    [
                        'table'       => 'audit_log',
                        'user_column' => 'emulated_user_id',
                        'action'      => GdprHandler::ACTION_ANONYMIZE,
                        'legal_hold_reason' => 'Same as above.',
                        'export'      => false,
                    ],
                ],
            ),

            new GdprHandler(
                module: 'core.consent',
                description: 'Cookie consent records associated with your account or anonymous session.',
                tables: [
                    [
                        'table'       => 'cookie_consents',
                        'user_column' => 'user_id',
                        'action'      => GdprHandler::ACTION_ANONYMIZE,
                        'legal_hold_reason' => 'Consent records prove the lawful basis for the period the user was active. Kept anonymised, not deleted.',
                    ],
                ],
            ),
        ];
    }
}
