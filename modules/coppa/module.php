<?php
// modules/coppa/module.php
use Core\Module\ModuleProvider;

/**
 * COPPA / GDPR Art. 8 / UK Children's Code age-gate module.
 *
 * Wires into the framework via:
 *
 *   1. AuthController::register — patched (in app/Controllers/) to call
 *      CoppaService::passesAgeGate($dob) when the toggle is on. Below
 *      minimum: rejects + audit-logs `coppa.registration_blocked`.
 *
 *   2. AuthController::showRegister — passes coppa_enabled +
 *      coppa_min_age into the registration view, which renders a
 *      `<input type="date" name="date_of_birth" aria-label="Date of birth">` field when on.
 *
 *   3. users.date_of_birth column — added by the migration. Populated
 *      on successful registration when the gate is on. Stored so any
 *      future age-restricted feature has a value to check against.
 *
 *   4. Settings on /admin/settings/access:
 *      - coppa_enabled        master toggle (default off)
 *      - coppa_minimum_age    block-below threshold (default 13)
 *      - coppa_block_message  user-visible rejection text
 *
 *   5. /admin/coppa — review surface for recent rejections (audit-log
 *      query). Useful for spotting patterns / abuse.
 *
 * Privacy posture:
 *   - On REJECTION: no DOB stored. Audit row has IP, UA, configured
 *     minimum age, and a 16-char SHA-256 prefix of the lowercased
 *     email. Enough for pattern detection, not enough to identify
 *     the child.
 *   - On SUCCESS: DOB stored on users.date_of_birth so the operator
 *     has the value for any downstream age-restricted feature
 *     (purchase limits, content unlocks, etc.). Treated as PII for
 *     GDPR purposes (anonymised on user erasure via the core handler
 *     pattern; users table is anonymised holistically by DataPurger).
 *
 * Default OFF because most sites don't market to under-13s and would
 * gain nothing from collecting birthdate. Opt in via /admin/settings/access.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'coppa'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    /**
     * GDPR — date_of_birth lives on the users table, which is already
     * scrubbed by DataPurger directly. No additional handler needed
     * here for the storage side. Audit-log rows for blocked
     * registrations are already chained + anonymised by the
     * audit-log retention rule.
     */
    public function gdprHandlers(): array { return []; }

    public function retentionRules(): array { return []; }
};
