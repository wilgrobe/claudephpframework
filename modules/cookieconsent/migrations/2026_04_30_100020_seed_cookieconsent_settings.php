<?php
// modules/cookieconsent/migrations/2026_04_30_100020_seed_cookieconsent_settings.php
use Core\Database\Migration;

/**
 * Seed default cookie-consent settings at scope='site'. Admin can edit
 * these from /admin/cookie-consent. Idempotent — re-running won't
 * clobber values an admin has already changed.
 *
 * cookieconsent_enabled        — master on/off. Off = banner never renders.
 * cookieconsent_policy_version — bump this to re-prompt all visitors.
 * cookieconsent_policy_url     — link from the banner to the full policy page.
 * cookieconsent_*_label        — admin-overridable copy.
 */
return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            ['cookieconsent_enabled',           'true',                                        'boolean'],
            ['cookieconsent_policy_version',    '1',                                           'string'],
            ['cookieconsent_policy_url',        '/page/cookie-policy',                         'string'],
            ['cookieconsent_title',             'We value your privacy',                       'string'],
            ['cookieconsent_body',              'We use cookies to keep the site running, remember your preferences, measure traffic, and personalise content. You can accept all, reject non-essential, or pick which categories to allow.', 'string'],
            ['cookieconsent_label_necessary',   'Strictly necessary',                          'string'],
            ['cookieconsent_label_preferences', 'Preferences',                                 'string'],
            ['cookieconsent_label_analytics',   'Analytics',                                   'string'],
            ['cookieconsent_label_marketing',   'Marketing',                                   'string'],
            ['cookieconsent_desc_necessary',    'Required for the site to function: login session, CSRF protection, shopping cart contents. Always on; cannot be disabled.', 'string'],
            ['cookieconsent_desc_preferences',  'Remember your settings such as theme, language, and layout.', 'string'],
            ['cookieconsent_desc_analytics',    'Help us understand which pages are popular and how the site is used. Aggregated, never used to identify you personally.', 'string'],
            ['cookieconsent_desc_marketing',    'Used to show ads relevant to your interests on this site and across the web.', 'string'],
        ];

        foreach ($defaults as $row) {
            $key   = $row[0];
            $value = $row[1];
            $type  = $row[2];

            // Skip when the admin has already set this key — re-running the
            // migration must never overwrite an edited value. The unique
            // index on (scope, scope_key, `key`) treats two NULL scope_keys
            // as distinct in MySQL, so we cannot rely on INSERT IGNORE
            // alone — explicit existence check matches modules/settings'
            // own seed pattern.
            $existing = $this->db->fetchOne(
                "SELECT id FROM settings WHERE scope = 'site' AND scope_key IS NULL AND `key` = ?",
                [$key]
            );
            if ($existing) continue;

            $this->db->insert('settings', [
                'scope'     => 'site',
                'scope_key' => null,
                'key'       => $key,
                'value'     => $value,
                'type'      => $type,
                'is_public' => 0,
            ]);
        }
    }

    public function down(): void
    {
        // Don't drop. Removing settings rows would silently revert the
        // banner copy on the next deploy if the module is re-enabled.
    }
};
