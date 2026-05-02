<?php
// modules/email/module.php
use Core\Module\ModuleProvider;

/**
 * Email compliance module — suppressions, one-click unsubscribe,
 * preference center, bounce/complaint webhooks.
 *
 * Wires into the framework via:
 *
 *   1. MailService::send($to, $subject, $body, $text, $category)
 *      — the optional 5th param activates the suppression check
 *      pre-send + auto-injects List-Unsubscribe headers + a body
 *      footer when category is non-transactional. Default is
 *      'transactional' so existing senders don't break.
 *
 *   2. Webhook endpoints at /webhooks/email/{ses|sendgrid|postmark|mailgun}.
 *      Hard bounces and complaints auto-suppress the address against
 *      the wildcard 'all' category — that includes transactional
 *      sends, since a dead address shouldn't keep being tried.
 *
 *   3. Public /unsubscribe/{token} for one-click + landing flow.
 *      RFC 8058 endpoint at /unsubscribe/{token}/one-click is what
 *      Gmail / Yahoo bulk-sender rules require for inbox-level
 *      unsubscribe buttons.
 *
 *   4. /account/email-preferences for an authenticated preference
 *      center — granular per-category opt-out for non-transactional
 *      categories. Transactional rows render as locked-on so the
 *      user understands they exist + why they can't be disabled.
 *
 * Set MAIL_WEBHOOK_SECRET in .env before enabling SendGrid / Postmark
 * webhooks in production. SES uses SNS-style signing (handled inline).
 * Mailgun uses MAILGUN_SIGNING_KEY for HMAC verification.
 *
 * ── Categorisation reference for senders ─────────────────────────────
 *
 * When adding a new MailService::send() / sendTemplate() call site,
 * pass the appropriate category as the trailing argument. Convention:
 *
 *   transactional   — auth (verify email, password reset, 2FA), security
 *                     (new-device alerts), billing (receipts, dunning),
 *                     account-action confirmations (booking confirmed,
 *                     order shipped), admin operational alerts (module
 *                     auto-disabled). Default. Cannot be opted out by users.
 *
 *   social          — invitations, follow alerts, comment-reply pings,
 *                     mention notifications, DM digests. Anything that
 *                     fires because of another user's action. Always
 *                     opt-out-able. Group invites use this.
 *
 *   product_updates — changelog announcements, new-feature notices,
 *                     beta-program emails. Reserved — the framework
 *                     doesn't ship a product-update sender yet.
 *
 *   marketing       — newsletters, promos, sales. Reserved — the
 *                     framework doesn't ship a newsletter sender yet,
 *                     just the siteblocks.newsletter_signup form. When
 *                     you add a sender, use this category.
 *
 * Current call sites (audited): every framework email except the two
 * group-invite paths in modules/groups/Controllers/GroupController.php
 * is transactional. Add new senders with explicit categories so the
 * compliance pipeline activates automatically.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'email'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Drop-on-any-page link to the email preference center.
            // Fits naturally into a footer or a privacy/account-settings
            // page next to the cookie consent + GDPR data links.
            new \Core\Module\BlockDescriptor(
                key:         'email.preferences_link',
                label:       'Email preferences (link)',
                description: 'Inline link to /account/email-preferences. Drop into a footer or account-settings page.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: ['label' => 'Email preferences'],
                audience:    'auth',
                settingsSchema: [
                    ['key' => 'label', 'label' => 'Link text', 'type' => 'text', 'default' => 'Email preferences'],
                ],
                render: function (array $context, array $settings): string {
                    if (\Core\Auth\Auth::getInstance()->guest()) return '';
                    $label = (string) ($settings['label'] ?? 'Email preferences');
                    return '<a href="/account/email-preferences" style="color:var(--color-primary,#4f46e5);text-decoration:none;font-size:13px">'
                         . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5)
                         . '</a>';
                }
            ),
        ];
    }

    /**
     * GDPR handlers — suppressions stay forever (legal hold) and the
     * blocks log gets purged via retention. On user erasure, the
     * mail_suppressions row's user_id FK is already SET NULL'd by
     * schema; no extra work needed here.
     *
     * mail_bounce_events — anonymise on user erasure (drop the
     * email + payload; keep the metadata for trend reporting).
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'email',
                description: 'Email suppression list and bounce events tied to your address.',
                tables: [
                    [
                        'table'             => 'mail_suppressions',
                        'user_column'       => 'email',
                        'action'            => \Modules\Gdpr\Services\GdprHandler::ACTION_KEEP,
                        'legal_hold_reason' => 'Suppression must persist after erasure to prevent re-mailing the same address. The row stays; user_id was already set to NULL on the FK cascade.',
                    ],
                ]
            ),
        ];
    }

    /**
     * Retention rules — log tables grow forever otherwise. Suppressions
     * themselves are NOT retention-able (they exist as long as the
     * address should not receive mail).
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'email.suppression_blocks.old',
                module:      'email',
                label:       'Old blocked-send log entries',
                tableName:   'mail_suppression_blocks',
                whereClause: 'blocked_at < {cutoff}',
                daysKeep:    180,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'blocked_at',
                description: 'Blocked-send log only useful for recent troubleshooting; 6 months is plenty.',
            ),
            new \Modules\Retention\Services\RetentionRule(
                key:         'email.bounce_events.old',
                module:      'email',
                label:       'Old bounce/complaint webhook events',
                tableName:   'mail_bounce_events',
                whereClause: 'received_at < {cutoff}',
                daysKeep:    180,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'received_at',
                description: 'Raw webhook payloads — kept 6 months for forensic replay; the resulting suppression rows live in mail_suppressions and are not affected.',
            ),
        ];
    }
};
