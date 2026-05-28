<?php
// modules/contact/module.php
use Core\Module\ModuleProvider;

/**
 * Contact module — default contact form for every site.
 *
 * Closes the long-standing "scrape-bait contact page" problem where
 * the seeded contact page exposed emails / phones / social handles
 * directly to spammers. Every framework install now ships with a
 * working contact form: POST /contact stores the message + notifies
 * a configurable recipient list via email.
 *
 * Admin queue at /admin/contact-messages; settings at
 * /admin/settings/contact (slotted into the settings hub nav).
 *
 * Anti-spam defaults: hidden honeypot field + 3-second min-time-on-page
 * + per-IP rate limit via the core RateLimiter.
 */
return new class extends ModuleProvider {
    public function name(): string { return 'contact'; }

    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function pages(): array
    {
        // Surface /contact in PageDiscoveryService so the wizard's
        // left pane shows it as a public landing page module-shipped
        // by this module.
        return [
            new \Core\Module\PageDescriptor(
                slug:        '/contact',
                label:       'Contact form',
                description: 'Default contact form with admin queue + recipient notifications.',
                public:      true,
                editable:    false, // controller-rendered; supports top/bottom slot injection
                icon:        '✉',
                module:      'contact',
            ),
        ];
    }
};
