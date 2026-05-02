<?php
// core/Services/ThemeService.php
namespace Core\Services;

/**
 * Single resolver for the site theme tokens (CSS variables).
 *
 * Handles three modes of rendering:
 *   - 'light' (default)              — admin's saved light values fall back
 *                                      to header.php's hardcoded :root
 *   - 'dark'                         — admin's saved dark values fall back
 *                                      to default_dark (shipped per token)
 *   - mixed render in renderOverrideStyle() — emits both light and dark
 *     blocks so the page handles both OS-pref dark mode (via media query)
 *     AND a future manual user toggle (via body.theme-dark) at once
 *
 * Settings keys: legacy seven flat snake_case keys for the brand colors,
 * namespaced dotted keys for everything else. Dark variant lives at
 * `<key>.dark` (so `theme.color.bg.page` has a dark sibling at
 * `theme.color.bg.page.dark`).
 */
class ThemeService
{
    /**
     * Each color token carries both `default` (light) and `default_dark`
     * (dark) shipped values. Admins can override each independently.
     * Length / unitless tokens (radius, layout, font_size) are mode-
     * agnostic — they don't have a dark variant since "8px" is the same
     * in either mode.
     */
    public const TOKEN_DEFINITIONS = [
        // ── Brand (legacy flat keys) — same in dark; brand colors stay constant ──
        'color_primary'      => ['css' => 'color-primary',      'default' => '#4f46e5', 'default_dark' => '#6366f1', 'validator' => 'color', 'group' => 'brand', 'label' => 'Primary',       'legacy' => true],
        'color_primary_dark' => ['css' => 'color-primary-dark', 'default' => '#3730a3', 'default_dark' => '#4f46e5', 'validator' => 'color', 'group' => 'brand', 'label' => 'Primary (dark)','legacy' => true],
        'color_secondary'    => ['css' => 'color-secondary',    'default' => '#0ea5e9', 'default_dark' => '#38bdf8', 'validator' => 'color', 'group' => 'brand', 'label' => 'Secondary',     'legacy' => true],
        'color_success'      => ['css' => 'color-success',      'default' => '#10b981', 'default_dark' => '#34d399', 'validator' => 'color', 'group' => 'brand', 'label' => 'Success',       'legacy' => true],
        'color_danger'       => ['css' => 'color-danger',       'default' => '#ef4444', 'default_dark' => '#f87171', 'validator' => 'color', 'group' => 'brand', 'label' => 'Danger',        'legacy' => true],
        'color_warning'      => ['css' => 'color-warning',      'default' => '#f59e0b', 'default_dark' => '#fbbf24', 'validator' => 'color', 'group' => 'brand', 'label' => 'Warning',       'legacy' => true],
        'color_info'         => ['css' => 'color-info',         'default' => '#3b82f6', 'default_dark' => '#60a5fa', 'validator' => 'color', 'group' => 'brand', 'label' => 'Info',          'legacy' => true],

        // ── Surfaces — flip from light to dark ──
        'theme.color.bg.page'    => ['css' => 'bg-page',    'default' => '#f9fafb', 'default_dark' => '#0b1220',           'validator' => 'color', 'group' => 'surfaces', 'label' => 'Page background'],
        'theme.color.bg.panel'   => ['css' => 'bg-panel',   'default' => '#ffffff', 'default_dark' => '#111827',           'validator' => 'color', 'group' => 'surfaces', 'label' => 'Panel / card background'],
        'theme.color.bg.block'   => ['css' => 'bg-block',   'default' => '#ffffff', 'default_dark' => '#1f2937',           'validator' => 'color', 'group' => 'surfaces', 'label' => 'Block background (page composer)'],
        'theme.color.bg.cell'    => ['css' => 'bg-cell',    'default' => '#f9fafb', 'default_dark' => '#1f2937',           'validator' => 'color', 'group' => 'surfaces', 'label' => 'Cell background (page composer)'],
        'theme.color.bg.overlay' => ['css' => 'bg-overlay', 'default' => 'rgba(17,24,39,.5)', 'default_dark' => 'rgba(0,0,0,.6)', 'validator' => 'color', 'group' => 'surfaces', 'label' => 'Modal / overlay backdrop'],

        // ── Text — invert ──
        'theme.color.text.default' => ['css' => 'text-default', 'default' => '#111827', 'default_dark' => '#f9fafb', 'validator' => 'color', 'group' => 'text', 'label' => 'Body text'],
        'theme.color.text.muted'   => ['css' => 'text-muted',   'default' => '#6b7280', 'default_dark' => '#9ca3af', 'validator' => 'color', 'group' => 'text', 'label' => 'Muted / secondary text'],
        'theme.color.text.subtle'  => ['css' => 'text-subtle',  'default' => '#9ca3af', 'default_dark' => '#6b7280', 'validator' => 'color', 'group' => 'text', 'label' => 'Subtle / tertiary text'],
        'theme.color.text.inverse' => ['css' => 'text-inverse', 'default' => '#ffffff', 'default_dark' => '#111827', 'validator' => 'color', 'group' => 'text', 'label' => 'Text on dark backgrounds'],

        // ── Borders ──
        'theme.color.border.default' => ['css' => 'border-default', 'default' => '#e5e7eb', 'default_dark' => '#374151', 'validator' => 'color', 'group' => 'borders', 'label' => 'Default border'],
        'theme.color.border.strong'  => ['css' => 'border-strong',  'default' => '#d1d5db', 'default_dark' => '#4b5563', 'validator' => 'color', 'group' => 'borders', 'label' => 'Strong border'],
        'theme.color.border.subtle'  => ['css' => 'border-subtle',  'default' => '#f3f4f6', 'default_dark' => '#1f2937', 'validator' => 'color', 'group' => 'borders', 'label' => 'Subtle border'],

        // ── Accents ──
        'theme.color.accent.subtle'   => ['css' => 'accent-subtle',   'default' => '#eef2ff', 'default_dark' => '#312e81', 'validator' => 'color', 'group' => 'accents', 'label' => 'Accent tint (hover, selection)'],
        'theme.color.accent.contrast' => ['css' => 'accent-contrast', 'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'accents', 'label' => 'Text on primary backgrounds'],

        // ── Chrome (sidebar + footer always-dark surfaces) ──
        // Defaults match the legacy hardcoded indigo palette so nothing
        // visibly shifts for sites that don't customise. light + dark
        // defaults are the SAME on purpose: chrome surfaces are designed
        // to stay dark in both modes (matches sidebar/footer's visual
        // role as a fixed dark band). Admins can split them per mode if
        // they want.
        'theme.color.chrome.sidebar_bg'   => ['css' => 'chrome-sidebar-bg',   'default' => '#1e1b4b', 'default_dark' => '#1e1b4b', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Sidebar background'],
        'theme.color.chrome.sidebar_text' => ['css' => 'chrome-sidebar-text', 'default' => '#c7d2fe', 'default_dark' => '#c7d2fe', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Sidebar text'],
        'theme.color.chrome.footer_bg'    => ['css' => 'chrome-footer-bg',    'default' => '#1e1b4b', 'default_dark' => '#1e1b4b', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Footer background'],
        'theme.color.chrome.footer_text'  => ['css' => 'chrome-footer-text',  'default' => '#c7d2fe', 'default_dark' => '#c7d2fe', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Footer text'],

        // ── Radii (mode-agnostic) ──
        'theme.radius.sm'   => ['css' => 'radius-sm',   'default' => '4px',   'validator' => 'length', 'group' => 'radius', 'label' => 'Small radius'],
        'theme.radius.md'   => ['css' => 'radius-md',   'default' => '8px',   'validator' => 'length', 'group' => 'radius', 'label' => 'Medium radius (default)'],
        'theme.radius.lg'   => ['css' => 'radius-lg',   'default' => '12px',  'validator' => 'length', 'group' => 'radius', 'label' => 'Large radius'],
        'theme.radius.full' => ['css' => 'radius-full', 'default' => '999px', 'validator' => 'length', 'group' => 'radius', 'label' => 'Pill radius'],

        // ── Border width ──
        'theme.border.width.default' => ['css' => 'border-width-default', 'default' => '1px', 'validator' => 'length', 'group' => 'border_width', 'label' => 'Default border width'],

        // ── Layout ──
        'theme.layout.max_width.full'   => ['css' => 'max-width-full',   'default' => '1400px', 'validator' => 'length', 'group' => 'layout', 'label' => 'Full-width max'],
        'theme.layout.max_width.medium' => ['css' => 'max-width-medium', 'default' => '960px',  'validator' => 'length', 'group' => 'layout', 'label' => 'Medium max-width'],
        'theme.layout.max_width.narrow' => ['css' => 'max-width-narrow', 'default' => '640px',  'validator' => 'length', 'group' => 'layout', 'label' => 'Narrow max-width (forms, prose)'],
        'theme.layout.gutter'           => ['css' => 'gutter',           'default' => '1rem',   'validator' => 'length', 'group' => 'layout', 'label' => 'Outer gutter / page padding'],

        // ── Typography sizes ──
        'theme.font.size.h1'    => ['css' => 'font-size-h1',    'default' => '1.75rem', 'validator' => 'length', 'group' => 'font_size', 'label' => 'Heading 1'],
        'theme.font.size.h2'    => ['css' => 'font-size-h2',    'default' => '1.5rem',  'validator' => 'length', 'group' => 'font_size', 'label' => 'Heading 2'],
        'theme.font.size.h3'    => ['css' => 'font-size-h3',    'default' => '1.25rem', 'validator' => 'length', 'group' => 'font_size', 'label' => 'Heading 3'],
        'theme.font.size.body'  => ['css' => 'font-size-body',  'default' => '14px',    'validator' => 'length', 'group' => 'font_size', 'label' => 'Body'],
        'theme.font.size.small' => ['css' => 'font-size-small', 'default' => '13px',    'validator' => 'length', 'group' => 'font_size', 'label' => 'Small'],
        'theme.font.size.tiny'  => ['css' => 'font-size-tiny',  'default' => '11px',    'validator' => 'length', 'group' => 'font_size', 'label' => 'Tiny / metadata'],

        // Font family slots. Each one resolves to a CSS font-family value;
        // the admin picks from FONT_LIBRARY via a <datalist> or types a
        // custom string. ThemeService::renderFontLinks() emits the matching
        // Google Fonts <link> tags for any of these that resolve to a
        // FONT_LIBRARY entry; non-matching custom values get no auto-link
        // and rely on the admin's custom_links textarea (or the framework
        // system fallbacks built into the family string itself).
        'theme.font.family.heading' => ['css' => 'font-family-heading', 'default' => "'Inter', system-ui, sans-serif",       'validator' => 'font_family', 'group' => 'font_family', 'label' => 'Headings'],
        'theme.font.family.body'    => ['css' => 'font-family-body',    'default' => "'Inter', system-ui, sans-serif",       'validator' => 'font_family', 'group' => 'font_family', 'label' => 'Body / paragraph'],
        'theme.font.family.mono'    => ['css' => 'font-family-mono',    'default' => 'ui-monospace, Menlo, Consolas, monospace', 'validator' => 'font_family', 'group' => 'font_family', 'label' => 'Code / monospace'],
        'theme.font.family.button'  => ['css' => 'font-family-button',  'default' => "'Inter', system-ui, sans-serif",       'validator' => 'font_family', 'group' => 'font_family', 'label' => 'Buttons'],
    ];

    /**
     * Implicit dark-mode overrides for the framework gray-* ramp declared
     * in app/Views/layout/header.php. These aren't theme tokens admins
     * can customise — they're the baseline grayscale used by the existing
     * chrome (sidebar, body, btn, card) which Batch F migrated only
     * partially. Until a Batch F2 sweep migrates the chrome to bg-* /
     * text-* / border-* tokens directly, flipping the gray ramp in dark
     * mode is the cheapest way to make existing chrome look right.
     */
    public const IMPLICIT_DARK_OVERRIDES = [
        'color-gray-50'  => '#0b1220',
        'color-gray-100' => '#1f2937',
        'color-gray-200' => '#374151',
        'color-gray-300' => '#4b5563',
        'color-gray-500' => '#9ca3af',
        'color-gray-700' => '#d1d5db',
        'color-gray-900' => '#f9fafb',
    ];

    /**
     * Light counterparts of IMPLICIT_DARK_OVERRIDES. Emitted under
     * body.theme-light so an explicit "Light" preference can override
     * the @media (prefers-color-scheme: dark) :root rule on dark-OS
     * devices. Without this, picking Light on a dark-OS machine would
     * leave the gray ramp at its dark-mode values - body.theme-light
     * only re-asserts named tokens, not the implicit ramp.
     *
     * Values match the hardcoded :root block in app/Views/layout/header.php.
     */
    public const IMPLICIT_LIGHT_OVERRIDES = [
        'color-gray-50'  => '#f9fafb',
        'color-gray-100' => '#f3f4f6',
        'color-gray-200' => '#e5e7eb',
        'color-gray-300' => '#d1d5db',
        'color-gray-500' => '#6b7280',
        'color-gray-700' => '#374151',
        'color-gray-900' => '#111827',
    ];

    public const GROUP_ORDER = [
        'brand'         => 'Brand colors',
        'surfaces'      => 'Surface colors',
        'text'          => 'Text colors',
        'borders'       => 'Border colors',
        'accents'       => 'Accents',
        'chrome'        => 'Chrome (sidebar + footer)',
        'radius'        => 'Corner radii',
        'border_width'  => 'Border widths',
        'layout'        => 'Layout dimensions',
        'font_size'     => 'Font sizes',
        'font_family'   => 'Fonts',
    ];

    /**
     * Curated allowlist of Google Fonts shipped with the framework.
     *
     * Each entry holds the friendly label, the full CSS font-family value
     * (with system fallbacks at the tail in case Google Fonts is blocked),
     * a category for the admin grouping, and the pre-built <link> URL
     * with sensible weight ranges.
     *
     * Admins not satisfied with the allowlist can paste any value into
     * the per-slot text input (the validator accepts arbitrary
     * font-family strings minus injection chars), and supply matching
     * <link> tags via the per-site `theme.font.custom_links` textarea
     * which is emitted verbatim. Self-hosted faces work the same way -
     * paste the @font-face block as a <style> in custom_links.
     */
    public const FONT_LIBRARY = [
        // Sans-serif
        'inter'           => ['label' => 'Inter',           'family' => "'Inter', system-ui, sans-serif",        'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'],
        'roboto'          => ['label' => 'Roboto',          'family' => "'Roboto', system-ui, sans-serif",       'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'],
        'open-sans'       => ['label' => 'Open Sans',       'family' => "'Open Sans', system-ui, sans-serif",    'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap'],
        'lato'            => ['label' => 'Lato',            'family' => "'Lato', system-ui, sans-serif",         'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap'],
        'source-sans-3'   => ['label' => 'Source Sans 3',   'family' => "'Source Sans 3', system-ui, sans-serif",'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700&display=swap'],
        'poppins'         => ['label' => 'Poppins',         'family' => "'Poppins', system-ui, sans-serif",      'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap'],
        'montserrat'      => ['label' => 'Montserrat',      'family' => "'Montserrat', system-ui, sans-serif",   'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap'],
        'nunito'          => ['label' => 'Nunito',          'family' => "'Nunito', system-ui, sans-serif",       'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap'],
        'work-sans'       => ['label' => 'Work Sans',       'family' => "'Work Sans', system-ui, sans-serif",    'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Work+Sans:wght@400;500;600;700&display=swap'],
        'ibm-plex-sans'   => ['label' => 'IBM Plex Sans',   'family' => "'IBM Plex Sans', system-ui, sans-serif",'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap'],
        'manrope'         => ['label' => 'Manrope',         'family' => "'Manrope', system-ui, sans-serif",      'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap'],
        'space-grotesk'   => ['label' => 'Space Grotesk',   'family' => "'Space Grotesk', system-ui, sans-serif",'category' => 'Sans-serif', 'link' => 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap'],
        // Serif
        'lora'            => ['label' => 'Lora',            'family' => "'Lora', Georgia, serif",                'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=Lora:wght@400;500;600;700&display=swap'],
        'merriweather'    => ['label' => 'Merriweather',    'family' => "'Merriweather', Georgia, serif",        'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&display=swap'],
        'playfair'        => ['label' => 'Playfair Display','family' => "'Playfair Display', Georgia, serif",    'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&display=swap'],
        'source-serif-4'  => ['label' => 'Source Serif 4',  'family' => "'Source Serif 4', Georgia, serif",      'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;500;600;700&display=swap'],
        'crimson-pro'     => ['label' => 'Crimson Pro',     'family' => "'Crimson Pro', Georgia, serif",         'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=Crimson+Pro:wght@400;500;600;700&display=swap'],
        'eb-garamond'     => ['label' => 'EB Garamond',     'family' => "'EB Garamond', Georgia, serif",         'category' => 'Serif',      'link' => 'https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600;700&display=swap'],
        // Monospace
        'fira-code'       => ['label' => 'Fira Code',       'family' => "'Fira Code', ui-monospace, monospace",  'category' => 'Monospace',  'link' => 'https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;700&display=swap'],
        'jetbrains-mono'  => ['label' => 'JetBrains Mono',  'family' => "'JetBrains Mono', ui-monospace, monospace",'category' => 'Monospace','link' => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap'],
    ];

    private SettingsService $settings;
    /** Memo for resolved token maps, keyed by mode + serialised context.
     *  renderFontLinks + renderOverrideStyle both ask for the light palette;
     *  without this memo each call re-iterates 44 settings reads. With it,
     *  the second call is a hash hit. */
    private array $tokenMemo = [];

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public function definitionsByGroup(string $group): array
    {
        $out = [];
        foreach (self::TOKEN_DEFINITIONS as $key => $def) {
            if (($def['group'] ?? '') === $group) $out[$key] = $def;
        }
        return $out;
    }

    public function allKeys(): array
    {
        return array_keys(self::TOKEN_DEFINITIONS);
    }

    /**
     * @return array<int, string> color token keys only (have default_dark)
     */
    public function colorKeys(): array
    {
        $out = [];
        foreach (self::TOKEN_DEFINITIONS as $key => $def) {
            if (($def['validator'] ?? '') === 'color') $out[] = $key;
        }
        return $out;
    }

    /**
     * Resolve tokens for the requested mode.
     *
     * Light mode: returns ONLY admin-customised tokens (empty/invalid drop
     * to header.php's hardcoded :root fallback). This is the legacy v1 +
     * v2 behavior so the light render stays unchanged when admin hasn't
     * touched anything.
     *
     * Dark mode: returns EVERY color token, using either the admin's saved
     * `<key>.dark` value or the shipped `default_dark`. Always emits the
     * full dark palette so OS-preference dark mode flips correctly even
     * when the admin hasn't customised dark.
     *
     * @param string     $mode     'light' | 'dark'
     * @param array|null $context  reserved for future group/user cascade
     * @return array<string, string> CSS variable name (no leading --) => value
     */
    public function resolveTokens(string $mode = 'light', ?array $context = null): array
    {
        $memoKey = $mode . '|' . ($context === null ? '' : md5(serialize($context)));
        if (isset($this->tokenMemo[$memoKey])) {
            return $this->tokenMemo[$memoKey];
        }
        $out = [];
        if ($mode === 'dark') {
            // Always emit a full dark palette. Color tokens use `<key>.dark`
            // (or default_dark); length/unitless tokens are mode-agnostic
            // so we don't include them in the dark block.
            foreach (self::TOKEN_DEFINITIONS as $settingKey => $def) {
                if (($def['validator'] ?? '') !== 'color') continue;
                $raw = (string) $this->settings->get($settingKey . '.dark', '', 'site');
                $raw = trim($raw);
                $value = ($raw !== '' && $this->validate($raw, 'color')) ? $raw : (string) $def['default_dark'];
                $out[(string) $def['css']] = $value;
            }
            // Implicit gray-ramp overrides for chrome that still uses the
            // baseline gray-* vars (compatibility shim until Batch F2).
            foreach (self::IMPLICIT_DARK_OVERRIDES as $css => $value) {
                $out[$css] = $value;
            }
            $this->tokenMemo[$memoKey] = $out;
            return $out;
        }

        // Light mode: emit the FULL palette (defaults + admin overrides).
        // Symmetric with 'dark'. Originally light was emit-only-overrides
        // (Batches A + B) on the assumption the hardcoded :root in
        // header.php carried defaults - but it only carries the gray-*
        // ramp + the seven brand colors, NOT the new bg-* / text-* /
        // border-* / radius-* / font-size-* tokens. So for light mode
        // those would have resolved to undefined without admin
        // customisation. Always emitting defaults makes both modes
        // self-consistent and lets var(--bg-page) etc. always resolve.
        foreach (self::TOKEN_DEFINITIONS as $settingKey => $def) {
            $raw = (string) $this->settings->get($settingKey, '', 'site');
            $raw = trim($raw);
            $value = ($raw !== '' && $this->validate($raw, (string) $def['validator']))
                ? $raw
                : (string) $def['default'];
            $out[(string) $def['css']] = $value;
        }
        // Implicit gray-ramp overrides for body.theme-light. These exist
        // so an explicit Light preference on a dark-OS device can override
        // the @media (prefers-color-scheme: dark) :root rule that sets
        // the gray ramp to dark values - without these, picking Light on
        // dark-OS leaves topbar nav text at the dark-mode #d1d5db value
        // and renders as light-gray on white = unreadable.
        foreach (self::IMPLICIT_LIGHT_OVERRIDES as $css => $value) {
            $out[$css] = $value;
        }
        $this->tokenMemo[$memoKey] = $out;
        return $out;
    }

    /**
     * Render BOTH a light :root override block (only when admin set things)
     * AND a dark block scoped to two selectors:
     *   @media (prefers-color-scheme: dark) :root  — OS preference
     *   body.theme-dark                            — manual toggle (Batch D)
     *
     * Same vars emitted under both selectors. Light fallbacks live in
     * header.php's hardcoded :root; dark always emits a full palette so
     * the swap is complete.
     */
    public function renderOverrideStyle(?array $context = null): string
    {
        $out = '';

        // ── Light palette ──
        // :root { ... }              — baseline for everyone
        // body.theme-light { ... }   — re-asserts light when user explicitly
        //                              chose light on a dark-OS device, so
        //                              they win over @media (prefers-color-scheme: dark)
        $light = $this->resolveTokens('light', $context);
        $lightVars = '';
        foreach ($light as $name => $value) {
            $lightVars .= "    --" . $this->cssEscape($name)
                        . ": " . $this->cssEscape($value) . ";\n";
        }
        if ($lightVars !== '') {
            // Emit a --font alias so existing chrome that uses var(--font)
            // (header.php hardcoded font ramp) flips to the admin's body
            // font choice without a chrome-wide migration. Fonts are
            // mode-agnostic so this only needs to land in the light block;
            // dark inherits through cascade.
            $alias = "    --font: var(--font-family-body);\n";
            $out .= "<style>\n/* Theme - light (baseline + body.theme-light override) */\n"
                  . ":root {\n" . $lightVars . $alias . "}\n"
                  . "body.theme-light {\n" . $lightVars . $alias . "}\n"
                  . "</style>\n";
        }

        // ── Dark palette ──
        // @media (prefers-color-scheme: dark) :root { ... }   — OS preference
        // body.theme-dark { ... }                              — manual toggle
        $dark = $this->resolveTokens('dark', $context);
        $darkVars = '';
        foreach ($dark as $name => $value) {
            $darkVars .= "    --" . $this->cssEscape($name)
                       . ": " . $this->cssEscape($value) . ";\n";
        }
        if ($darkVars !== '') {
            $out .= "<style>\n/* Theme - dark (OS preference + body.theme-dark) */\n"
                  . "@media (prefers-color-scheme: dark) {\n  :root {\n" . $darkVars . "  }\n}\n"
                  . "body.theme-dark {\n" . $darkVars . "}\n"
                  . "</style>\n";
        }

        return $out;
    }

    /**
     * CSS-context escape for emission inside <style>. HTML entities are
     * NOT decoded inside <style>, so htmlspecialchars produces literal
     * "&apos;" / "&quot;" tokens that break CSS parsers. Instead, strip
     * the only chars that can escape the style context or close a rule
     * early - "<", ">", and "}" - and trust the per-token validators
     * (which already reject ";" and "{") for everything else. Quotes and
     * parens stay intact because both are legitimate CSS.
     */
    private function cssEscape(string $v): string
    {
        return str_replace(['<', '>', '}'], '', $v);
    }

    public function validate(string $value, string $type): bool
    {
        $value = trim($value);
        return match ($type) {
            'color'       => $this->isValidCssColor($value),
            'length'      => $this->isValidCssLength($value),
            'unitless'    => $this->isValidUnitless($value),
            'font_family' => $this->isValidFontFamily($value),
            default       => false,
        };
    }

    private function isValidCssColor(string $v): bool
    {
        $v = strtolower($v);
        if (preg_match('/^#[0-9a-f]{3,8}$/', $v)) return true;
        if (preg_match('/^(rgba?|hsla?)\(\s*[0-9.,%\s\/]+\)$/', $v)) return true;
        if (preg_match('/^[a-z]{3,30}$/', $v)) return true;
        return false;
    }

    private function isValidCssLength(string $v): bool
    {
        if (strlen($v) > 12) return false;
        return (bool) preg_match('/^\d{1,5}(\.\d{1,3})?(px|rem|em|%)$/i', $v);
    }

    private function isValidUnitless(string $v): bool
    {
        return (bool) preg_match('/^\d{1,6}$/', $v);
    }

    /**
     * Permissive font-family validator. Accepts the tokens that legitimate
     * font-family values use (letters, digits, single + double quotes,
     * commas, hyphens, spaces, dots) and rejects everything that could
     * smuggle a CSS injection - semicolons, braces, parens, slashes,
     * backslashes, less/greater-than. Length capped at 200 chars.
     */
    private function isValidFontFamily(string $v): bool
    {
        if ($v === '' || strlen($v) > 200) return false;
        return (bool) preg_match("/^[A-Za-z0-9\s,'\".\-]+$/", $v);
    }

    /**
     * Emit <link> tags for the curated Google Fonts in active use, plus
     * any extra <link> URLs the admin pasted into the custom_links
     * textarea. Returns a single HTML string ready for the <head>.
     *
     * The "in active use" rule: walk the four font-family slots, look up
     * each resolved family string in FONT_LIBRARY by exact match, dedupe
     * the matched entries by key, emit their links. Custom values that
     * don't match a library entry simply get no auto-link.
     */
    public function renderFontLinks(?array $context = null): string
    {
        $tokens = $this->resolveTokens('light', $context);
        $usedKeys = [];
        foreach (['font-family-heading','font-family-body','font-family-mono','font-family-button'] as $slot) {
            $family = $tokens[$slot] ?? null;
            if ($family === null) continue;
            foreach (self::FONT_LIBRARY as $key => $entry) {
                if ((string) $entry['family'] === $family) {
                    $usedKeys[$key] = true;
                    break;
                }
            }
        }

        $out = '';
        if (!empty($usedKeys)) {
            // One preconnect for the whole bundle, then the actual link tags.
            $out .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
            $out .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
            foreach (array_keys($usedKeys) as $key) {
                $href = (string) self::FONT_LIBRARY[$key]['link'];
                $href = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5);
                $out .= '<link rel="stylesheet" href="' . $href . '">' . "\n";
            }
        }

        // Admin-pasted custom <link> URLs. Stored as a newline-separated
        // text blob; we emit one <link> per non-empty trimmed line that
        // Admin-pasted custom <link> URLs. Stored as a newline-separated
        // text blob; we emit one <link> per non-empty trimmed line that
        // looks like an http(s) URL. Anything else is dropped silently.
        $blob = (string) $this->settings->get('theme.font.custom_links', '', 'site');
        if ($blob !== '') {
            foreach (preg_split('/\r?\n/', $blob) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (!preg_match('#^https?://[^\s\"<>]+$#i', $line)) continue;
                if (strlen($line) > 500) continue;
                $line = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5);
                $out .= '<link rel="stylesheet" href="' . $line . '">' . "\n";
            }
        }

        return $out;
    }
}
