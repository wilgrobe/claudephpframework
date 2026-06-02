<?php
// modules/tooltip/module.php
use Core\Module\ModuleProvider;

/**
 * Tooltip module (core) — managed, reusable help bubbles.
 *
 * Tooltips are content rows (slug + body + placement/theme/trigger) edited in an
 * admin manager and dropped onto pages as composer blocks or fetched via a JSON
 * API. Content is stored raw (plain / markdown / html) and rendered + sanitised
 * at read time through the framework allowlist. A vanilla, dependency-free JS
 * widget auto-wires hover / click / focus triggers with smart auto-placement and
 * a single shared listener per event type.
 *
 * Core (always on): the admin manager, the two composer blocks, the public
 * content API, hover/click triggers, auto-placement.
 *
 * Submodules: rich-content (markdown/HTML bodies), per-page-overrides (per
 * page/route/role content variants), analytics (view tracking), a11y-keyboard
 * (focus/Esc ARIA wiring). `translations` (i18n per-locale bodies) is declared
 * for the roadmap and lands when paired with the i18n module.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'tooltip'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function submodules(): array
    {
        return [
            new \Core\Module\SubmoduleDescriptor(
                key: 'rich-content', label: 'Rich content',
                description: 'Markdown / sanitised-HTML tooltip bodies (off = escaped plain text only).',
            ),
            new \Core\Module\SubmoduleDescriptor(
                key: 'per-page-overrides', label: 'Per-page overrides',
                description: 'Different tooltip content per page / route / user role.',
            ),
            new \Core\Module\SubmoduleDescriptor(
                key: 'analytics', label: 'View tracking',
                description: 'Count tooltip views + a usage rollup in the admin.',
            ),
            new \Core\Module\SubmoduleDescriptor(
                key: 'a11y-keyboard', label: 'Keyboard a11y',
                description: 'ARIA-compliant focus open + Esc dismiss (hover / click work regardless).',
                costTokens: 0,
            ),
            new \Core\Module\SubmoduleDescriptor(
                key: 'translations', label: 'i18n translations',
                description: 'Per-locale tooltip content when the i18n module is installed.',
            ),
        ];
    }

    /** Page-composer blocks: inline tooltip + standalone help button. */
    public function blocks(): array
    {
        return [
            new \Core\Module\BlockDescriptor(
                key:         'tooltip.inline',
                label:       'Inline tooltip',
                description: 'A trigger word in prose that reveals a managed tooltip on hover / click.',
                category:    'Content',
                defaultSettings: ['tooltip_slug' => '', 'trigger_text' => '', 'icon' => 'ℹ'],
                settingsSchema: [
                    ['key' => 'tooltip_slug', 'label' => 'Tooltip slug', 'type' => 'text', 'default' => ''],
                    ['key' => 'trigger_text', 'label' => 'Trigger text', 'type' => 'text', 'default' => ''],
                    ['key' => 'icon',         'label' => 'Icon', 'type' => 'select', 'options' => ['none', '?', 'ℹ', '💡'], 'default' => 'ℹ'],
                ],
                render: function (array $context, array $settings): string {
                    $slug = trim((string) ($settings['tooltip_slug'] ?? ''));
                    if ($slug === '') return '';
                    $text = (string) ($settings['trigger_text'] ?? '') ?: $slug;
                    try {
                        return (new \Modules\Tooltip\Services\TooltipService())
                            ->render($slug, $text, ['icon' => (string) ($settings['icon'] ?? 'ℹ')]);
                    } catch (\Throwable) { return ''; }
                },
            ),
            new \Core\Module\BlockDescriptor(
                key:         'tooltip.help_button',
                label:       'Help button',
                description: 'A standalone ? / ℹ / 💡 help button with a managed tooltip — handy beside form fields.',
                category:    'Content',
                defaultSettings: ['tooltip_slug' => '', 'icon' => '?', 'button_label' => ''],
                settingsSchema: [
                    ['key' => 'tooltip_slug', 'label' => 'Tooltip slug', 'type' => 'text', 'default' => ''],
                    ['key' => 'icon',         'label' => 'Icon', 'type' => 'select', 'options' => ['?', 'ℹ', '💡'], 'default' => '?'],
                    ['key' => 'button_label', 'label' => 'Label (optional)', 'type' => 'text', 'default' => ''],
                ],
                render: function (array $context, array $settings): string {
                    $slug = trim((string) ($settings['tooltip_slug'] ?? ''));
                    if ($slug === '') return '';
                    try {
                        $svc = new \Modules\Tooltip\Services\TooltipService();
                        $tip = $svc->get($slug);
                        if (!$tip) return '';
                        $renderer = new \Modules\Tooltip\Services\TooltipRenderer();
                        return $renderer->renderHelpButton($tip, [
                            'icon'         => (string) ($settings['icon'] ?? '?'),
                            'button_label' => (string) ($settings['button_label'] ?? ''),
                            'content'      => $renderer->content($tip, $tip['_content'] ?? null),
                        ]);
                    } catch (\Throwable) { return ''; }
                },
            ),
        ];
    }

    // NOTE: no pages() — PageDescriptor is a builder-only construct (the
    // wizard page catalog), absent from a framework-only install. The admin
    // manager is reached via the /admin/tooltips route + the admin nav.
};
