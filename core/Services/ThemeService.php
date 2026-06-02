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
        // ── Brand / Surfaces / Text / Borders / Accents ──
        // Theming v2 §14: the v1 flat keys (color_primary/_dark/_secondary,
        // color_success/warning/danger/info, theme.color.bg/text/border/accent.*)
        // are RETIRED. Their component CSS vars (--color-primary, --bg-page, …)
        // are now emitted by renderGenericLayerVars() from the v2 sources below
        // (theme.palette.primary.* + theme.palette.default.*), so these defs
        // were fully redundant. The default palette's TOKEN_DEFINITIONS defaults
        // (theme.palette.default.* + primary/secondary, further down) carry the
        // shipped look. Chrome keeps its theme.color.chrome.* namespace — it's
        // its own v2 role, not a v1↔v2 duality.

        // ── Chrome (sidebar + footer always-dark surfaces) ──
        // Defaults match the legacy hardcoded indigo palette so nothing
        // visibly shifts for sites that don't customize. light + dark
        // defaults are the SAME on purpose: chrome surfaces are designed
        // to stay dark in both modes (matches sidebar/footer's visual
        // role as a fixed dark band). Admins can split them per mode if
        // they want.
        'theme.color.chrome.sidebar_bg'   => ['css' => 'chrome-sidebar-bg',   'default' => '#1e1b4b', 'default_dark' => '#1e1b4b', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Sidebar background'],
        'theme.color.chrome.sidebar_text' => ['css' => 'chrome-sidebar-text', 'default' => '#c7d2fe', 'default_dark' => '#c7d2fe', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Sidebar text'],
        'theme.color.chrome.footer_bg'    => ['css' => 'chrome-footer-bg',    'default' => '#1e1b4b', 'default_dark' => '#1e1b4b', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Footer background'],
        'theme.color.chrome.footer_text'  => ['css' => 'chrome-footer-text',  'default' => '#c7d2fe', 'default_dark' => '#c7d2fe', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Footer text'],
        // Phase 43.13 — header bar (admin topbar + public site-header)
        // gets its own tokens so it can be styled independently of the
        // sidebar/footer pair. Defaults match the existing light topbar
        // look (white bg + near-black text) so installs that haven't
        // customized see zero change. BrandColorDeriver overrides these
        // when a brand color is picked, producing a brand-tinted dark
        // header to match the rest of the chrome.
        'theme.color.chrome.header_bg'    => ['css' => 'chrome-header-bg',    'default' => '#ffffff', 'default_dark' => '#111827', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Header background'],
        'theme.color.chrome.header_text'  => ['css' => 'chrome-header-text',  'default' => '#111827', 'default_dark' => '#f9fafb', 'validator' => 'color', 'group' => 'chrome', 'label' => 'Header text'],

        // ── Radius ──
        // radius.sm/md/full removed (no consumer). Only --radius-lg is
        // referenced (in BrandingRenderer's `border-radius:var(--radius-lg)`).
        'theme.radius.lg'   => ['css' => 'radius-lg',   'default' => '12px',  'validator' => 'length', 'group' => 'radius', 'label' => 'Large radius'],

        // Removed groups (no consumers):
        //   border_width (border-width-default)
        //   layout (max-width-full/medium/narrow + gutter)
        //   font_size (font-size-h1/h2/h3/body/small/tiny)
        // Plus 3 of the 4 font_family tokens: only body has a consumer
        // (aliased to --font in renderOverrideStyle for the chrome's
        // hardcoded `var(--font)` ramp).
        'theme.font.family.body' => ['css' => 'font-family-body', 'default' => "'Inter', system-ui, sans-serif", 'validator' => 'font_family', 'group' => 'font_family', 'label' => 'Body / paragraph'],

        // ════════════════════════════════════════════════════════════════
        // Theming v2 — palette-slot source tokens (docs/theming-v2-spec.md).
        // These are the SOURCE palettes. The generic "active" layer
        // (renderGenericLayerVars) maps the legacy consumed var names
        // (--bg-page, --color-primary, --accent-subtle, --surface-*, …) onto
        // these, so existing components keep working unchanged. Phase 1 is
        // additive — the legacy tokens above still emit but are overridden
        // by the generic layer; Phase 3 removes them + migrates settings.
        // Default values reproduce the current coral/teal look exactly.
        // ════════════════════════════════════════════════════════════════

        // ── default palette (neutral base: 2 surfaces + text + borders + accent + line + semantics) ──
        'theme.palette.default.bg'              => ['css' => 'default-bg',              'default' => '#faf7f2', 'default_dark' => '#1c1917', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Page background'],
        'theme.palette.default.surface'         => ['css' => 'default-surface',         'default' => '#ffffff', 'default_dark' => '#292524', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Panel / card surface'],
        'theme.palette.default.text'            => ['css' => 'default-text',            'default' => '#1c1917', 'default_dark' => '#f5f5f4', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Body text'],
        'theme.palette.default.text-muted'      => ['css' => 'default-text-muted',      'default' => '#605a52', 'default_dark' => '#a8a29e', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Muted text'],
        'theme.palette.default.text-subtle'     => ['css' => 'default-text-subtle',     'default' => '#857d75', 'default_dark' => '#78716c', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Subtle text'],
        'theme.palette.default.border'          => ['css' => 'default-border',          'default' => '#e7e2da', 'default_dark' => '#44403c', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Border'],
        'theme.palette.default.border-strong'   => ['css' => 'default-border-strong',   'default' => '#d6d0c6', 'default_dark' => '#57534e', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Border (strong)'],
        'theme.palette.default.border-subtle'   => ['css' => 'default-border-subtle',   'default' => '#f2efe9', 'default_dark' => '#292524', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Border (subtle)'],
        'theme.palette.default.accent'          => ['css' => 'default-accent',          'default' => '#c2410c', 'default_dark' => '#d4521e', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Accent (links / focus)'],
        'theme.palette.default.accent-contrast' => ['css' => 'default-accent-contrast', 'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Text on accent'],
        'theme.palette.default.accent-tint'     => ['css' => 'default-accent-tint',     'default' => '#fbeae2', 'default_dark' => '#3a241c', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Accent tint (hover / selection)'],
        'theme.palette.default.line'            => ['css' => 'default-line',            'default' => '#78716c24', 'default_dark' => '#e7e2da1a', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Hairline divider'],
        'theme.palette.default.success'         => ['css' => 'default-success',         'default' => '#15803d', 'default_dark' => '#4ade80', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Success'],
        'theme.palette.default.warning'         => ['css' => 'default-warning',         'default' => '#b45309', 'default_dark' => '#fbbf24', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Warning'],
        'theme.palette.default.danger'          => ['css' => 'default-danger',          'default' => '#dc2626', 'default_dark' => '#f87171', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Danger'],
        'theme.palette.default.info'            => ['css' => 'default-info',            'default' => '#0d9488', 'default_dark' => '#2dd4bf', 'validator' => 'color', 'group' => 'pal_default', 'label' => 'Info'],

        // ── hero role (its own surface, with gradient + CTA pair) ──
        'theme.palette.hero.bg'         => ['css' => 'hero-bg',         'default' => '#c2410c', 'default_dark' => '#d4521e', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero background / stop 1'],
        'theme.palette.hero.grad'       => ['css' => 'hero-grad',       'default' => '#9a3412', 'default_dark' => '#b8431a', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero gradient stop 2 (blank = solid)'],
        'theme.palette.hero.grad-dir'   => ['css' => 'hero-grad-dir',   'default' => '135deg',  'validator' => 'angle', 'group' => 'pal_hero', 'label' => 'Hero gradient direction'],
        'theme.palette.hero.text'       => ['css' => 'hero-text',       'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero text'],
        'theme.palette.hero.text-muted' => ['css' => 'hero-text-muted', 'default' => '#ffffffe6', 'default_dark' => '#ffffffe6', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero subheading'],
        'theme.palette.hero.cta-bg'     => ['css' => 'hero-cta-bg',     'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero CTA button bg'],
        'theme.palette.hero.cta-text'   => ['css' => 'hero-cta-text',   'default' => '#c2410c', 'default_dark' => '#d4521e', 'validator' => 'color', 'group' => 'pal_hero', 'label' => 'Hero CTA button label'],

        // ── ordinal accent palettes (pickable per section): primary…quinary ──
        'theme.palette.primary.bg'          => ['css' => 'primary-bg',          'default' => '#c2410c', 'default_dark' => '#d4521e', 'validator' => 'color', 'group' => 'pal_primary', 'label' => 'Primary background / stop 1'],
        'theme.palette.primary.grad'        => ['css' => 'primary-grad',        'default' => '#9a3412', 'default_dark' => '#b8431a', 'validator' => 'color', 'group' => 'pal_primary', 'label' => 'Primary gradient stop 2'],
        'theme.palette.primary.grad-dir'    => ['css' => 'primary-grad-dir',    'default' => '135deg',  'validator' => 'angle', 'group' => 'pal_primary', 'label' => 'Primary gradient direction'],
        'theme.palette.primary.text'        => ['css' => 'primary-text',        'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'pal_primary', 'label' => 'Primary text'],
        'theme.palette.primary.text-muted'  => ['css' => 'primary-text-muted',  'default' => '#ffffffe6', 'default_dark' => '#ffffffe6', 'validator' => 'color', 'group' => 'pal_primary', 'label' => 'Primary text muted'],
        'theme.palette.primary.text-subtle' => ['css' => 'primary-text-subtle', 'default' => '#ffffffb3', 'default_dark' => '#ffffffb3', 'validator' => 'color', 'group' => 'pal_primary', 'label' => 'Primary text subtle'],

        'theme.palette.secondary.bg'          => ['css' => 'secondary-bg',          'default' => '#0d9488', 'default_dark' => '#0f766e', 'validator' => 'color', 'group' => 'pal_secondary', 'label' => 'Secondary background / stop 1'],
        'theme.palette.secondary.grad'        => ['css' => 'secondary-grad',        'default' => '#0f766e', 'default_dark' => '#115e59', 'validator' => 'color', 'group' => 'pal_secondary', 'label' => 'Secondary gradient stop 2'],
        'theme.palette.secondary.grad-dir'    => ['css' => 'secondary-grad-dir',    'default' => '135deg',  'validator' => 'angle', 'group' => 'pal_secondary', 'label' => 'Secondary gradient direction'],
        'theme.palette.secondary.text'        => ['css' => 'secondary-text',        'default' => '#ffffff', 'default_dark' => '#ffffff', 'validator' => 'color', 'group' => 'pal_secondary', 'label' => 'Secondary text'],
        'theme.palette.secondary.text-muted'  => ['css' => 'secondary-text-muted',  'default' => '#ffffffe6', 'default_dark' => '#ffffffe6', 'validator' => 'color', 'group' => 'pal_secondary', 'label' => 'Secondary text muted'],
        'theme.palette.secondary.text-subtle' => ['css' => 'secondary-text-subtle', 'default' => '#ffffffb3', 'default_dark' => '#ffffffb3', 'validator' => 'color', 'group' => 'pal_secondary', 'label' => 'Secondary text subtle'],

        'theme.palette.tertiary.bg'          => ['css' => 'tertiary-bg',          'default' => '#fbeae2', 'default_dark' => '#3a241c', 'validator' => 'color', 'group' => 'pal_tertiary', 'label' => 'Tertiary background / stop 1'],
        'theme.palette.tertiary.grad'        => ['css' => 'tertiary-grad',        'default' => '#fdf4ee', 'default_dark' => '#2a201b', 'validator' => 'color', 'group' => 'pal_tertiary', 'label' => 'Tertiary gradient stop 2'],
        'theme.palette.tertiary.grad-dir'    => ['css' => 'tertiary-grad-dir',    'default' => '160deg',  'validator' => 'angle', 'group' => 'pal_tertiary', 'label' => 'Tertiary gradient direction'],
        'theme.palette.tertiary.text'        => ['css' => 'tertiary-text',        'default' => '#1c1917', 'default_dark' => '#f5f5f4', 'validator' => 'color', 'group' => 'pal_tertiary', 'label' => 'Tertiary text'],
        'theme.palette.tertiary.text-muted'  => ['css' => 'tertiary-text-muted',  'default' => '#605a52', 'default_dark' => '#a8a29e', 'validator' => 'color', 'group' => 'pal_tertiary', 'label' => 'Tertiary text muted'],
        'theme.palette.tertiary.text-subtle' => ['css' => 'tertiary-text-subtle', 'default' => '#857d75', 'default_dark' => '#78716c', 'validator' => 'color', 'group' => 'pal_tertiary', 'label' => 'Tertiary text subtle'],

        'theme.palette.quaternary.bg'          => ['css' => 'quaternary-bg',          'default' => '#e2f3f1', 'default_dark' => '#173d3a', 'validator' => 'color', 'group' => 'pal_quaternary', 'label' => 'Quaternary background / stop 1'],
        'theme.palette.quaternary.grad'        => ['css' => 'quaternary-grad',        'default' => '#f0faf8', 'default_dark' => '#16302e', 'validator' => 'color', 'group' => 'pal_quaternary', 'label' => 'Quaternary gradient stop 2'],
        'theme.palette.quaternary.grad-dir'    => ['css' => 'quaternary-grad-dir',    'default' => '160deg',  'validator' => 'angle', 'group' => 'pal_quaternary', 'label' => 'Quaternary gradient direction'],
        'theme.palette.quaternary.text'        => ['css' => 'quaternary-text',        'default' => '#1c1917', 'default_dark' => '#f5f5f4', 'validator' => 'color', 'group' => 'pal_quaternary', 'label' => 'Quaternary text'],
        'theme.palette.quaternary.text-muted'  => ['css' => 'quaternary-text-muted',  'default' => '#605a52', 'default_dark' => '#a8a29e', 'validator' => 'color', 'group' => 'pal_quaternary', 'label' => 'Quaternary text muted'],
        'theme.palette.quaternary.text-subtle' => ['css' => 'quaternary-text-subtle', 'default' => '#857d75', 'default_dark' => '#78716c', 'validator' => 'color', 'group' => 'pal_quaternary', 'label' => 'Quaternary text subtle'],

        'theme.palette.quinary.bg'          => ['css' => 'quinary-bg',          'default' => '#f5f1ea', 'default_dark' => '#262220', 'validator' => 'color', 'group' => 'pal_quinary', 'label' => 'Quinary background / stop 1'],
        'theme.palette.quinary.grad'        => ['css' => 'quinary-grad',        'default' => '#ece7df', 'default_dark' => '#211e1b', 'validator' => 'color', 'group' => 'pal_quinary', 'label' => 'Quinary gradient stop 2'],
        'theme.palette.quinary.grad-dir'    => ['css' => 'quinary-grad-dir',    'default' => '160deg',  'validator' => 'angle', 'group' => 'pal_quinary', 'label' => 'Quinary gradient direction'],
        'theme.palette.quinary.text'        => ['css' => 'quinary-text',        'default' => '#1c1917', 'default_dark' => '#f5f5f4', 'validator' => 'color', 'group' => 'pal_quinary', 'label' => 'Quinary text'],
        'theme.palette.quinary.text-muted'  => ['css' => 'quinary-text-muted',  'default' => '#605a52', 'default_dark' => '#a8a29e', 'validator' => 'color', 'group' => 'pal_quinary', 'label' => 'Quinary text muted'],
        'theme.palette.quinary.text-subtle' => ['css' => 'quinary-text-subtle', 'default' => '#857d75', 'default_dark' => '#78716c', 'validator' => 'color', 'group' => 'pal_quinary', 'label' => 'Quinary text subtle'],
    ];

    /**
     * Theming v2 — v2-internal fallback (theming v2 §14: the v1 keys are
     * retired; this REPLACES the old v1-compat bridge). A v2 source token falls
     * back to another v2 token when its own setting is unset, so the brand
     * (theme.palette.primary.*) propagates to the hero + the default accent
     * without the user setting each. Everything else (default surfaces / text /
     * borders / semantics + the bold/secondary ordinals) resolves from its own
     * migrated setting or its shipped TOKEN_DEFINITIONS default — no fallback
     * needed. The const name is kept (callers reference self::LEGACY_FALLBACK);
     * its contents are now purely v2→v2.
     */
    public const LEGACY_FALLBACK = [
        'theme.palette.hero.bg'        => 'theme.palette.primary.bg',
        'theme.palette.hero.grad'      => 'theme.palette.primary.grad',
        'theme.palette.hero.cta-text'  => 'theme.palette.primary.bg',
        'theme.palette.default.accent' => 'theme.palette.primary.bg',
    ];

    /**
     * Implicit dark-mode overrides for the framework gray-* ramp declared
     * in app/Views/layout/header.php. These aren't theme tokens admins
     * can customize — they're the baseline grayscale used by the existing
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
        // Theming v2 §14: the v1 Brand / Surfaces / Text / Borders / Accents
        // groups are gone (their flat keys were retired). The default palette
        // (theme.palette.default.* — surfaces, text, borders, accents + the
        // semantic colours) is now the editable base, followed by chrome, the
        // hero role, and the 5 ordinal accent palettes.
        'pal_default'    => 'Default palette (surfaces · text · borders · accents)',
        'chrome'         => 'Chrome (sidebar + footer)',
        'radius'         => 'Corner radii',
        'font_family'    => 'Fonts',
        'pal_hero'       => 'Hero (gradient + CTA)',
        'pal_primary'    => 'Palette · Primary',
        'pal_secondary'  => 'Palette · Secondary',
        'pal_tertiary'   => 'Palette · Tertiary',
        'pal_quaternary' => 'Palette · Quaternary',
        'pal_quinary'    => 'Palette · Quinary',
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
        // Display / decorative — Phase 43.30
        'archivo-black'   => ['label' => 'Archivo Black',   'family' => "'Archivo Black', Impact, sans-serif",   'category' => 'Display',    'link' => 'https://fonts.googleapis.com/css2?family=Archivo+Black&display=swap'],
        'bebas-neue'      => ['label' => 'Bebas Neue',      'family' => "'Bebas Neue', Impact, sans-serif",      'category' => 'Display',    'link' => 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap'],
        'pacifico'        => ['label' => 'Pacifico',        'family' => "'Pacifico', cursive",                   'category' => 'Display',    'link' => 'https://fonts.googleapis.com/css2?family=Pacifico&display=swap'],
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
     * Light mode: returns ONLY admin-customized tokens (empty/invalid drop
     * to header.php's hardcoded :root fallback). This is the legacy v1 +
     * v2 behavior so the light render stays unchanged when admin hasn't
     * touched anything.
     *
     * Dark mode: returns EVERY color token, using either the admin's saved
     * `<key>.dark` value or the shipped `default_dark`. Always emits the
     * full dark palette so OS-preference dark mode flips correctly even
     * when the admin hasn't customized dark.
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
        // Tenant ordinal-tint derivation (tertiary/quaternary/quinary) from the
        // tenant's brand. Empty on apex / framework-only installs, so it only
        // overrides the 6 ordinal-tint defaults for tenant renders.
        $ordinalDerived = $this->resolveOrdinalDerived($mode);
        if ($mode === 'dark') {
            // Always emit a full dark palette. Color tokens use `<key>.dark`
            // (or default_dark); length/unitless tokens are mode-agnostic
            // so we don't include them in the dark block.
            foreach (self::TOKEN_DEFINITIONS as $settingKey => $def) {
                if (($def['validator'] ?? '') !== 'color') continue;
                $raw = trim((string) $this->settings->get($settingKey . '.dark', '', 'site'));
                if ($raw === '' && isset(self::LEGACY_FALLBACK[$settingKey])) {
                    $raw = trim((string) $this->settings->get(self::LEGACY_FALLBACK[$settingKey] . '.dark', '', 'site'));
                }
                $css = (string) $def['css'];
                $value = ($raw !== '' && $this->validate($raw, 'color'))
                    ? $raw
                    : ($ordinalDerived[$css] ?? (string) $def['default_dark']);
                $out[$css] = $value;
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
        // customization. Always emitting defaults makes both modes
        // self-consistent and lets var(--bg-page) etc. always resolve.
        foreach (self::TOKEN_DEFINITIONS as $settingKey => $def) {
            $raw = trim((string) $this->settings->get($settingKey, '', 'site'));
            if ($raw === '' && isset(self::LEGACY_FALLBACK[$settingKey])) {
                $raw = trim((string) $this->settings->get(self::LEGACY_FALLBACK[$settingKey], '', 'site'));
            }
            $css = (string) $def['css'];
            $value = ($raw !== '' && $this->validate($raw, (string) $def['validator']))
                ? $raw
                : ($ordinalDerived[$css] ?? (string) $def['default']);
            $out[$css] = $value;
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
     * Derive the tenant's ordinal LIGHT-TINT palettes (tertiary / quaternary /
     * quinary) from its brand so a tenant section set to one of those ordinals
     * harmonises with the tenant's own colors instead of the apex's hand-tuned
     * coral / mint / cream defaults.
     *
     * Gated on tenant context: the APEX (and framework-only / CLI installs,
     * where the builder tenancy layer is absent or no tenant is current) gets
     * an empty map → the static TOKEN_DEFINITIONS defaults stand. The bold
     * ordinals (primary, secondary) are NOT here — they already track the brand
     * via the color_primary / color_secondary LEGACY_FALLBACK. An explicit
     * per-ordinal-tint setting still wins (this only fills the unset fallback).
     *
     * @return array<string,string> css-var-name => hex (6 ordinal-tint keys), or []
     */
    private function resolveOrdinalDerived(string $mode): array
    {
        if (!class_exists(\App\Tenancy\Tenant::class) || \App\Tenancy\Tenant::current() === null) {
            return [];
        }
        $dark   = $mode === 'dark';
        $defKey = $dark ? 'default_dark' : 'default';
        // The brand HUE is mode-independent (a teal brand is teal in both
        // modes; deriveOrdinalTints supplies the dark/light lightness band). So
        // in dark mode prefer an explicit color_primary.dark, then fall back to
        // the light color_primary the tenant actually set, then the default.
        $brandFor = function (string $base) use ($dark, $defKey): string {
            $v = $dark ? trim((string) $this->settings->get($base . '.dark', '', 'site')) : '';
            if ($v === '') $v = trim((string) $this->settings->get($base, '', 'site'));
            if ($v === '') $v = (string) self::TOKEN_DEFINITIONS[$base][$defKey];
            return $v;
        };
        return \App\Theme\BrandColorDeriver::deriveOrdinalTints(
            $brandFor('theme.palette.primary.bg'),
            $brandFor('theme.palette.secondary.bg'),
            $dark
        );
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
        // Theming v2 generic "active" layer — maps the legacy consumed var
        // names onto the v2 source palettes. Appended AFTER the token vars so
        // it overrides the legacy literals (Phase 1 keeps both). The values
        // are var() references that flip per mode, so the same block is added
        // to light + dark.
        $lightVars .= $this->renderGenericLayerVars();
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
        $darkVars .= $this->renderGenericLayerVars();
        if ($darkVars !== '') {
            $out .= "<style>\n/* Theme - dark (OS preference + body.theme-dark) */\n"
                  . "@media (prefers-color-scheme: dark) {\n  :root {\n" . $darkVars . "  }\n}\n"
                  . "body.theme-dark {\n" . $darkVars . "}\n"
                  . "</style>\n";
        }

        return $out;
    }

    /**
     * Theming v2 — the generic "active" layer (docs/theming-v2-spec.md §2).
     *
     * Maps the variable names components already consume onto the v2 source
     * palettes. Because these are `var(--…)` references (not literals), the
     * same block is emitted under both the light and dark selectors and each
     * reference resolves to the active-mode value automatically — so a
     * section that later remaps, say, `--bg-page` to `var(--tertiary-bg)` is
     * correct in both modes with no dark-specific rule.
     *
     * Phase 1: appended after the legacy token vars so it overrides them
     * (the legacy `theme.color.*` tokens still emit but are now inert).
     * Phase 3 removes the legacy tokens + migrates settings to theme.palette.*.
     */
    private function renderGenericLayerVars(): string
    {
        static $map = [
            // neutral surfaces / text / borders / accent / line ← default palette
            'bg-page'        => 'default-bg',
            'bg-panel'       => 'default-surface',
            'text-default'   => 'default-text',
            'text-muted'     => 'default-text-muted',
            'text-subtle'    => 'default-text-subtle',
            'border-default' => 'default-border',
            'border-strong'  => 'default-border-strong',
            'border-subtle'  => 'default-border-subtle',
            'accent-subtle'  => 'default-accent-tint',
            'accent-contrast'=> 'default-accent-contrast',
            // brand color names ← primary/secondary ordinals + semantics ← default
            'color-primary'      => 'primary-bg',
            'color-primary-dark' => 'primary-grad',
            'color-secondary'    => 'secondary-bg',
            'color-success'      => 'default-success',
            'color-danger'       => 'default-danger',
            'color-warning'      => 'default-warning',
            'color-info'         => 'default-info',
            // (legacy --surface-* helpers retired in Phase 4 — sections use
            //  data-palette now; no view references var(--surface-*) anymore.)
        ];
        $out = '';
        foreach ($map as $generic => $source) {
            $out .= "    --{$generic}: var(--{$source});\n";
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
        // Phase 43.196b H2 — defense-in-depth. Pre-fix this stripped
        // ONLY `<>}` and relied on per-token validators rejecting `;`
        // and `{`. If any validator has a gap (or a new token type is
        // added without rejecting those chars), the cssEscape layer
        // was a no-op. Now also strip `;` (statement separator —
        // breaks out of any declaration) + CR/LF/TAB + `/*` (comment
        // opener can escape into adjacent rules). Keep `"`/`(`/`)`
        // since `url(...)` + quoted strings are legit in many token
        // types. The cleaner long-term shape is one allowlist
        // validator per token; this is the cheap belt-and-suspenders.
        return str_replace(['<', '>', '}', '{', ';', "\r", "\n", "\t", '/*'], '', $v);
    }

    public function validate(string $value, string $type): bool
    {
        $value = trim($value);
        return match ($type) {
            'color'       => $this->isValidCssColor($value),
            'length'      => $this->isValidCssLength($value),
            'unitless'    => $this->isValidUnitless($value),
            'font_family' => $this->isValidFontFamily($value),
            'font_style'  => $this->isValidFontStyle($value),
            'angle'       => $this->isValidAngle($value),
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
     * Gradient-direction validator (theming v2 `*-grad-dir` tokens). Accepts
     * an angle like `135deg` / `.5turn` / `0.25rad` OR a `to <side>` keyword
     * phrase like `to bottom right`. Mode-agnostic (no dark variant).
     */
    private function isValidAngle(string $v): bool
    {
        $v = strtolower(trim($v));
        if (strlen($v) > 24) return false;
        if (preg_match('/^-?\d{1,3}(\.\d{1,3})?(deg|grad|rad|turn)$/', $v)) return true;
        if (preg_match('/^to (top|bottom|left|right)( (top|bottom|left|right))?$/', $v)) return true;
        return false;
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
     * Phase 43.33 — restricted-keyword validator for font-style tokens.
     * Accepts only the three CSS keywords we want to expose; rejects
     * anything else (including the `oblique <angle>` form, which has
     * inconsistent rendering support across web fonts).
     */
    private function isValidFontStyle(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['normal', 'italic', 'oblique'], true);
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
