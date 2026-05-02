<?php
// modules/cookieconsent/module.php
use Core\Module\ModuleProvider;

/**
 * Cookieconsent module — GDPR cookie banner + audit-trailed consent storage.
 *
 * The banner partial (Views/banner.php) is included from the master
 * layout (app/Views/layout/footer.php) and the public page view
 * (app/Views/public/page.php). It self-renders only when consent is
 * missing for the current policy version, so it's safe to include
 * unconditionally.
 *
 * Other modules / views can gate tracking scripts with the
 * consent_allowed('analytics') / 'marketing' / 'preferences' helper
 * defined in core/helpers.php — fail-closed when the module isn't
 * installed.
 *
 * The blocks() return offers a "Manage cookie preferences" reopen link
 * so admins can drop a footer-style entry point on any page composer
 * without writing PHP.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'cookieconsent'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    /**
     * Site-scope settings owned by the cookie-consent admin page.
     * Hidden from the generic /admin/settings grid.
     */
    public function settingsKeys(): array
    {
        return [
            'cookieconsent_enabled',
            'cookieconsent_policy_version',
            'cookieconsent_policy_url',
            'cookieconsent_title',
            'cookieconsent_body',
            'cookieconsent_label_necessary',
            'cookieconsent_label_preferences',
            'cookieconsent_label_analytics',
            'cookieconsent_label_marketing',
            'cookieconsent_desc_necessary',
            'cookieconsent_desc_preferences',
            'cookieconsent_desc_analytics',
            'cookieconsent_desc_marketing',
        ];
    }

    public function blocks(): array
    {
        return [
            // Drop-on-any-page link that re-opens the banner. Useful for
            // footer menus, privacy pages, "Manage cookies" entries.
            new \Core\Module\BlockDescriptor(
                key:         'cookieconsent.reopen_link',
                label:       'Manage cookie preferences (link)',
                description: 'Inline link that re-opens the cookie banner. Drop in a footer or privacy page.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: [
                    'label' => 'Manage cookie preferences',
                ],
                audience: 'any',
                settingsSchema: [
                    ['key' => 'label', 'label' => 'Link text', 'type' => 'text', 'default' => 'Manage cookie preferences'],
                ],
                render: function (array $context, array $settings): string {
                    $label = (string) ($settings['label'] ?? 'Manage cookie preferences');
                    $safe  = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5);
                    // Forces a fresh prompt by wiping the consent cookie
                    // client-side, then reloading. The server's banner
                    // gate will then see no cookie + fire the banner.
                    return '<a href="#" onclick="document.cookie=\'cookie_consent=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT\';location.reload();return false;" '
                         . 'style="color:var(--color-primary,#4f46e5);text-decoration:none;font-size:13px">'
                         . $safe . '</a>';
                }
            ),
        ];
    }
};
