<?php
// modules/ccpa/module.php
use Core\Module\ModuleProvider;

/**
 * CCPA / CPRA "Do Not Sell or Share My Personal Information" module.
 *
 * Wires into the framework via:
 *
 *   1. /do-not-sell — public opt-out form. Works for guests (collects
 *      email) and signed-in users (uses user_id). Records persist via
 *      a 1-year HttpOnly cookie + a row in ccpa_opt_outs.
 *
 *   2. Footer link — auto-injected into app/Views/partials/site_footer.php
 *      next to the menu when ccpa_enabled is on. Label + URL configurable
 *      via settings.
 *
 *   3. ccpa_opted_out() helper in core/helpers.php — gate any code path
 *      that constitutes "sale" or "sharing" under California law:
 *
 *          <?php if (!ccpa_opted_out()): ?>
 *              <script src="https://example-ad-network.com/pixel.js"></script>
 *          <?php endif; ?>
 *
 *      Honors all four signals: live Sec-GPC: 1 header, signed-in user
 *      flag, device cookie, email match.
 *
 *   4. /admin/ccpa — admin overview of opt-out records + stats.
 *
 * Settings live on /admin/settings/access (where cookie consent + similar
 * regulatory toggles already live). Keys:
 *   - ccpa_enabled              master toggle
 *   - ccpa_link_label           footer link text
 *   - ccpa_disclosure_url       link target
 *   - ccpa_honor_gpc_signal     auto-honor browser GPC header
 *
 * Note: the module ships the disclosure infrastructure. The actual
 * privacy-policy text describing what data your site "sells" or
 * "shares" lives in your CMS page (default URL: /do-not-sell, but
 * many sites point the disclosure URL at a /page/privacy-policy
 * instead and use /do-not-sell only for the opt-out form itself).
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'ccpa'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    /**
     * Site-scope settings owned by the CCPA admin page. Hidden from
     * the generic /admin/settings grid.
     */
    public function settingsKeys(): array
    {
        return [
            'ccpa_enabled',
            'ccpa_link_label',
            'ccpa_disclosure_url',
            'ccpa_honor_gpc_signal',
        ];
    }

    /**
     * GDPR handler — opt-out records are LEGAL-HOLD evidence (similar
     * to cookie_consents). On user erasure, anonymise the email +
     * user_id link but keep the count for compliance reporting.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'ccpa',
                description: 'CCPA / CPRA "Do Not Sell" opt-out records linked to your account or email.',
                tables: [
                    [
                        'table'             => 'ccpa_opt_outs',
                        'user_column'       => 'user_id',
                        'action'            => \Modules\Gdpr\Services\GdprHandler::ACTION_ANONYMIZE,
                        'anonymize_columns' => [
                            'email'      => null,
                            'ip_address' => null,
                            'user_agent' => null,
                            'notes'      => null,
                        ],
                        'legal_hold_reason' => 'Opt-out records are compliance evidence ("we honored a CCPA opt-out for user X on date Y"). Kept anonymised; identity scrubbed.',
                    ],
                ]
            ),
        ];
    }

    public function retentionRules(): array { return []; }
};
