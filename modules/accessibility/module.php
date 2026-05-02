<?php
// modules/accessibility/module.php
use Core\Module\ModuleProvider;

/**
 * Accessibility module — WCAG 2.1 AA static linting + chrome-level
 * a11y improvements.
 *
 * Ships:
 *   - A11yLintService: scans app/Views/ + every modules/{name}/Views/
 *     for the high-value template-level WCAG violations
 *     (img-without-alt, input-without-label, empty-anchor,
 *     button-without-text, multiple-h1, removed-focus-outline).
 *   - php artisan a11y:lint — CI gate. Exits non-zero on any error
 *     finding. Supports --json / --errors-only / --root=PATH flags.
 *   - /admin/a11y — admin dashboard with the live results.
 *   - skip-to-content link + focus-visible CSS injected into the
 *     guest public page (the authenticated chrome already has both).
 *
 * EAA (European Accessibility Act) went into force June 2025 for
 * companies over the small-business threshold serving EU customers.
 * This module ships the foundational WCAG 2.1 AA hooks; pair with a
 * runtime tool like axe-core or Lighthouse for interaction-level
 * coverage that templates alone can't capture.
 *
 * Future additions (each a self-contained follow-up):
 *   - Required alt-text validator on file-upload service
 *   - ARIA live-region helpers for AJAX-updated regions
 *   - Color-contrast lint against the theme tokens
 *   - Heading-skip detection (h1→h3 with no h2)
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'accessibility'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }

    /**
     * Site-scope settings owned by /admin/a11y. Hidden from the
     * generic /admin/settings grid.
     */
    public function settingsKeys(): array
    {
        return [
            'accessibility_skip_link_enabled',
            'accessibility_focus_styles_enabled',
        ];
    }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function commands(): array
    {
        return [
            \Modules\Accessibility\Console\A11yLintCommand::class,
        ];
    }

    public function gdprHandlers(): array { return []; }
};
