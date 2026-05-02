<?php
// modules/policies/module.php
use Core\Module\ModuleProvider;

/**
 * Policies module — versioned ToS / Privacy / AUP / custom policies
 * with required-acceptance enforcement and a per-acceptance audit row.
 *
 * Hooks into the framework via:
 *
 *   1. RequirePolicyAcceptance::isBlocked() — called from public/index.php
 *      after the maintenance gate. Redirects authenticated users with
 *      unaccepted required policies to /policies/accept (the blocking
 *      modal). Allow-list covers logout, /policies/*, /account/policies,
 *      /account/data, auth, API, uploads, assets.
 *
 *   2. /policies/{slug}, /policies/accept, /account/policies routes —
 *      defined in routes.php. Bypass the global acceptance gate so
 *      users CAN actually reach the accept form.
 *
 *   3. /admin/policies/* routes — admin UI for managing kinds,
 *      assigning source pages, bumping versions, and viewing
 *      acceptance reports.
 *
 *   4. gdprHandlers() — policy_acceptances rows are anonymised on
 *      user erasure (NULL the user_id) but the row stays so the
 *      acceptance count for compliance reporting remains accurate.
 *      Authoritative legal posture is "we have evidence X people
 *      accepted this version" — anonymised rows still satisfy that.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'policies'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Footer-style link to the user's policy history. Useful in
            // a privacy-themed page composer or a user-settings sidebar.
            new \Core\Module\BlockDescriptor(
                key:         'policies.account_link',
                label:       'My policy acceptances (link)',
                description: 'Inline link to /account/policies. Drop into a privacy footer or user-settings page.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: ['label' => 'My policy acceptances'],
                audience:    'auth',
                settingsSchema: [
                    ['key' => 'label', 'label' => 'Link text', 'type' => 'text', 'default' => 'My policy acceptances'],
                ],
                render: function (array $context, array $settings): string {
                    if (\Core\Auth\Auth::getInstance()->guest()) return '';
                    $label = (string) ($settings['label'] ?? 'My policy acceptances');
                    return '<a href="/account/policies" style="color:var(--color-primary,#4f46e5);text-decoration:none;font-size:13px">'
                         . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5)
                         . '</a>';
                }
            ),
        ];
    }

    /**
     * GDPR handlers — anonymise (don't erase) policy_acceptances on
     * user erasure. The row counts as "compliance evidence that N
     * users accepted version V"; deleting it would weaken our
     * statistical claim. Anonymisation drops the user_id while
     * preserving the row.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'policies',
                description: 'Records of which policy versions you accepted, and when.',
                tables: [
                    [
                        'table'             => 'policy_acceptances',
                        'user_column'       => 'user_id',
                        'action'            => \Modules\Gdpr\Services\GdprHandler::ACTION_ANONYMIZE,
                        'anonymize_columns' => [
                            'ip_address' => null,
                            'user_agent' => null,
                        ],
                        'legal_hold_reason' => 'Anonymised in place to preserve aggregate compliance evidence (count of acceptances per version) without retaining the user identifier or PII.',
                    ],
                ]
            ),
        ];
    }
};
