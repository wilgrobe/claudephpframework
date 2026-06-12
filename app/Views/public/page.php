<?php
// app/Views/public/page.php
//
// Two render modes share the same body content (hero + composer-or-body):
//
//   • $user is authenticated → render inside the full app layout
//     (app/Views/layout/header.php + footer.php). Authed users keep the
//     bell, search, user dropdown, sidebar/topbar nav while viewing
//     /{slug} pages so they don't lose chrome navigating between
//     surfaces. SEO meta tags from the public shell are skipped because
//     authed users aren't indexed (search bots are guests).
//
//   • $user is guest → render the standalone "marketing" shell with a
//     minimal top bar (logo + FAQ + Sign In). This is the path search
//     engines see, so we put the SEO meta tags here.
//
// Layout tries to behave nicely either way: page-hero + page-body
// classes have inline styles in the guest path; the auth path uses the
// app layout's standard content padding so the page slots in like any
// other authed page.

// Compute the composer envelope once — both branches reuse it.
$__composer = null;
try {
    $__composer = (new \Core\Services\PageLayoutService())
        ->getForPage((int) $page['id']);
} catch (\Throwable $e) {
    // Service or table missing on a fresh install before layout
    // migrations ran. Falling back to body rendering keeps the public
    // site usable.
    $__composer = null;
}

$__renderBody = function () use ($page, $user, $__composer) {
    // Pre-rendered section-based layout (webappbuilder Phase 4). When a
    // page's section composer has saved output to pages.rendered_html,
    // serve that verbatim — the renderer already produced safe HTML and
    // the embedded styles. The pages.rendered_html column is added by
    // webappbuilder; the ?? null coalesce keeps this safe on framework
    // installs that don't have the column yet.
    $__rendered = $page['rendered_html'] ?? null;
    if (is_string($__rendered) && $__rendered !== '') {
        echo $__rendered;
        return;
    }
    if ($__composer) {
        $composer = $__composer;
        $composerContext = ['page' => $page, 'viewer' => $user];
        include BASE_PATH . '/app/Views/partials/page_composer.php';
    } else {
        echo '<article class="page-body">';
        echo \Core\Validation\Validator::sanitizeHtml($page['body'] ?? '');
        echo '</article>';
    }
};
?>
<?php
// Theme-editor preview path: when the iframe sets ?_theme_preview=1, force
// the standalone marketing shell even for authed viewers. Otherwise the
// framework wraps the page in admin chrome (sidebar, topbar) which uses
// a different token surface than what tenant theme presets drive.
$__forcePublicShell = isset($_GET['_theme_preview']) && $_GET['_theme_preview'] === '1';
?>
<?php if ($user && !$__forcePublicShell): /* ── Authenticated: full app layout ─────────────────────── */ ?>
    <?php $pageTitle = $page['title'] ?? 'Page'; ?>
    <?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

    <style>
    /* Scoped hero/body styles — only included on authed renders so the
       guest standalone shell keeps using its own definitions below. */
    .page-hero { background: var(--bg-panel, #fff); border-bottom: 1px solid var(--color-gray-200); padding: 2rem 1.5rem; text-align: center; margin-bottom: 1rem; border-radius: 8px; }
    .page-hero h1 { font-size: 1.6rem; font-weight: 700; margin: 0; }
    .page-body { max-width: <?= ($page['layout'] ?? 'default') === 'wide' ? '1100px' : '760px' ?>; margin: 1.5rem auto; padding: 0 1.5rem; line-height: 1.8; font-size: 15px; }
    .page-body h2 { font-size: 1.3rem; margin-top: 1.75rem; }
    .page-body h3 { font-size: 1.1rem; margin-top: 1.25rem; }
    .page-body p  { margin: 0 0 1rem; }
    .page-body a, .builder-block a {
        color: var(--color-primary); text-decoration: underline;
        text-decoration-color: color-mix(in srgb, var(--color-primary) 38%, transparent);
        text-decoration-thickness: 1px; text-underline-offset: 2px;
        transition: color .12s, text-decoration-color .12s;
    }
    .page-body a:hover, .builder-block a:hover {
        color: var(--color-primary-dark); text-decoration-color: currentColor; text-decoration-thickness: 2px;
    }
    .page-body a:visited, .builder-block a:visited { color: var(--color-secondary); }
    .page-body ul, .page-body ol { margin: 0 0 1rem; padding-left: 1.5rem; }
    </style>

    <?php
    // Per-page main wrapper spacing (added 2026-06-03). Wraps the
    // hero + body so authed shell's <main class="content" id="main-
    // content"> chrome stays untouched.
    $__pmt = (int) ($page['main_margin_top_px']    ?? 0);
    $__pmb = (int) ($page['main_margin_bottom_px'] ?? 0);
    $__ppx = (int) ($page['main_padding_x_px']     ?? 0);
    $__ppy = (int) ($page['main_padding_y_px']     ?? 0);
    // Always emit the user's 6 properties (including 0s) so the
    // framework's .content { padding:1.5rem } default can't bleed
    // through. The user's per-page setting is authoritative —
    // including "set everything to 0" which means truly zero.
    $__mainStyleParts = [
        'margin-top:'    . $__pmt . 'px',
        'margin-bottom:' . $__pmb . 'px',
        'padding-top:'    . $__ppy . 'px',
        'padding-bottom:' . $__ppy . 'px',
        'padding-left:'  . $__ppx . 'px',
        'padding-right:' . $__ppx . 'px',
    ];
    $__mainStyle = ' style="' . implode(';', $__mainStyleParts) . '"';
    ?>
    <style>
    /* Zero out the framework chrome's outer padding so the per-page
       .page-main wrapper below has full control. */
    main.content#main-content { padding: 0; }
    </style>
    <div class="page-main"<?= $__mainStyle ?>>
    <?php if (empty($page['hide_title'])): ?>
    <div class="page-hero">
        <h1><?= e($page['title']) ?></h1>
    </div>
    <?php endif; ?>

    <?php $__renderBody(); ?>
    </div>

    <?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
<?php else: /* ── Guest: standalone public shell with SEO meta + marketing chrome ── */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // Tenant-uploaded favicon (webappbuilder Phase 5). Falls through silently
    // when not set — browsers retain whatever default they were using.
    $__faviconRel = setting('builder.branding.favicon_url', '');
    if ($__faviconRel !== '') {
        $__faviconUrl = (new \Core\Services\FileUploadService())->url($__faviconRel);
        echo '<link rel="icon" href="' . htmlspecialchars($__faviconUrl, ENT_QUOTES) . '">';
    }
    ?>
    <?php
    // Canonical is the root for the page that's set as the guest home, and
    // /{slug} for everything else. Prevents the home page from being indexed
    // under two distinct URLs.
    $__home_slug = setting('guest_home_page_slug', '');
    $__canonical_path = ($__home_slug !== '' && $__home_slug === $page['slug']) ? '/' : '/' . $page['slug'];
    ?>
    <?= \Core\SEO\SeoManager::metaTags([
        'title'       => ($page['seo_title'] ?: $page['title']) . ' — ' . setting('site_name', 'App'),
        'description' => $page['seo_description'] ?? '',
        'keywords'    => $page['seo_keywords'] ?? '',
        'canonical'   => config('app.url') . $__canonical_path,
    ]) ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php echo \Core\Services\AnalyticsService::snippet(); ?>
    <?php
    // Theme override block — emits :root vars from framework defaults +
    // admin overrides. Without this, the inline guest-shell CSS below
    // would have nothing to bind to and the theme system would be
    // admin-chrome-only. The output also covers light + dark mode.
    echo (new \Core\Services\ThemeService(new \Core\Services\SettingsService()))->renderOverrideStyle();
    // Tenant custom font + custom CSS (webappbuilder Phase 5e/5f).
    // No-op on framework installs that don't ship the App\Theme\BrandingRenderer
    // class, so this hook stays passive for vanilla framework users.
    if (class_exists(\App\Theme\BrandingRenderer::class)) {
        echo \App\Theme\BrandingRenderer::renderHead();
    }
    // Phase 43.32 — per-page font overrides win over the site-wide
    // BrandingRenderer output via cascade order (this <style> comes
    // after it). Each slot has an optional URL (loaded via <link>)
    // and a family name (overwrites the matching --font-family-* var
    // on :root). NULL/empty slots leave the inherited site-level
    // family in place. Column-guarded so framework installs that
    // pre-date Phase 43.32 silently no-op.
    $__pageFontSlots = [
        'body'    => '--font-family-body',
        'heading' => '--font-family-heading',
        'mono'    => '--font-family-mono',
    ];
    $__faceLinks = [];
    $__rootVars  = [];
    foreach ($__pageFontSlots as $__slot => $__cssVar) {
        $__url = (string) ($page['font_url_'    . $__slot] ?? '');
        $__fam = (string) ($page['font_family_' . $__slot] ?? '');
        if ($__fam === '') continue;
        // Strip ONLY dangerous chars; preserve quotes + commas so a
        // multi-token stack like `'Lora', Georgia, serif` survives intact.
        // Single-token user input (e.g. just "Inter") is also valid CSS.
        $__safeFam = trim((string) preg_replace('/[{}<>\\\\]/', '', $__fam));
        if ($__safeFam === '') continue;
        $__rootVars[] = $__cssVar . ':' . $__safeFam . ';';
        if ($__url !== '' && preg_match('~^https?://~i', $__url)) {
            $__faceLinks[] = '<link rel="stylesheet" href="' . htmlspecialchars($__url, ENT_QUOTES) . '">';
        }
    }
    if (!empty($__rootVars) || !empty($__faceLinks)) {
        echo implode('', $__faceLinks);
        if (!empty($__rootVars)) echo '<style>:root{' . implode('', $__rootVars) . '}</style>';
    }
    // Phase 43.164 — per-page color overrides + custom CSS. Emitted
    // AFTER ThemeService + BrandingRenderer so the page-scoped values
    // win via cascade order. Token → CSS-var map matches the wizard
    // step 7 overrides modal's allowlist. Column-guarded for tenants
    // pre-43.164.
    $__pgColorMap = [
        'theme.palette.primary.bg'   => '--color-primary',
        'bg_page'         => '--bg-page',
        'bg_panel'        => '--bg-panel',
        'text_default'    => '--text-default',
        'text_muted'      => '--text-muted',
        'border_default'  => '--border-default',
    ];
    $__pgColorsRaw = (string) ($page['color_overrides'] ?? '');
    if ($__pgColorsRaw !== '') {
        $__pgColors = json_decode($__pgColorsRaw, true);
        if (is_array($__pgColors) && !empty($__pgColors)) {
            $__pgVars = [];
            // Dark-mode safety: when a per-page override sets a LIGHT
            // bg-panel or bg-page (a common case — tenants pick a yellow
            // hero tint), dark-mode --text-default flips to white over
            // the still-light panel = invisible. Detect light overrides
            // and force matching dark text-default + text-muted only on
            // dark mode so light mode renders exactly as configured.
            $__pgLightPanel = false;
            $__lumCheck = function (string $hex): bool {
                if ($hex === '' || $hex[0] !== '#') return false;
                $hex = substr($hex, 1);
                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }
                if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return false;
                $r = hexdec(substr($hex, 0, 2)) / 255;
                $g = hexdec(substr($hex, 2, 2)) / 255;
                $b = hexdec(substr($hex, 4, 2)) / 255;
                $l = static fn($c) => $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
                $lum = 0.2126 * $l($r) + 0.7152 * $l($g) + 0.0722 * $l($b);
                return $lum > 0.6;
            };
            foreach ($__pgColorMap as $__tk => $__cssVar) {
                $__v = (string) ($__pgColors[$__tk] ?? '');
                if ($__v !== '' && preg_match('/^#[0-9a-f]{6}$/i', $__v)) {
                    $__pgVars[] = $__cssVar . ':' . strtolower($__v) . ';';
                    if (($__tk === 'bg_panel' || $__tk === 'bg_page') && $__lumCheck(strtolower($__v))) {
                        $__pgLightPanel = true;
                    }
                }
            }
            if (!empty($__pgVars)) echo '<style>:root{' . implode('', $__pgVars) . '}</style>';
            if ($__pgLightPanel) {
                // Override text colors only within .page-hero (the only
                // surface that consumes --bg-panel from per-page overrides
                // — builder-blocks have their own bg/text via inline style).
                // Without this, dark mode would render white text on the
                // tenant's light hero panel bg. Body class theme-light
                // explicitly opts out so don't apply.
                //
                // The panel is LIGHT (luminance-checked), so the ink must stay
                // dark in BOTH modes — it can't flip with the theme. But the
                // *value* is settings-driven: we pin it to the theme's
                // configured LIGHT default-text / -muted (resolveTokens('light'))
                // rather than a hardcoded hex, so a tenant that customised its
                // text colour sees that colour on the light hero in dark mode.
                $__hl   = (new \Core\Services\ThemeService(new \Core\Services\SettingsService()))->resolveTokens('light');
                $__ink  = strtolower((string) ($__hl['default-text'] ?? '#1c1917'));
                $__inkM = strtolower((string) ($__hl['default-text-muted'] ?? '#605a52'));
                if (!preg_match('/^#[0-9a-f]{6}$/', $__ink))  $__ink  = '#1c1917';
                if (!preg_match('/^#[0-9a-f]{6}$/', $__inkM)) $__inkM = '#605a52';
                echo '<style>'
                    . 'body.theme-dark .page-hero{color:' . $__ink . ';}'
                    . 'body.theme-dark .page-hero h1,body.theme-dark .page-hero .meta,body.theme-dark .page-hero p{color:' . $__ink . ';}'
                    . 'body.theme-dark .page-hero .meta{color:' . $__inkM . ';}'
                    . '@media (prefers-color-scheme: dark){body:not(.theme-light) .page-hero{color:' . $__ink . ';}body:not(.theme-light) .page-hero h1,body:not(.theme-light) .page-hero .meta,body:not(.theme-light) .page-hero p{color:' . $__ink . ';}body:not(.theme-light) .page-hero .meta{color:' . $__inkM . ';}}'
                    . '</style>';
            }
        }
    }
    // Phase 43.206 — per-page style overrides emitted as :root CSS vars
    // alongside colors. Keys map to the same theme.style.* vars Phase 43.10
    // shipped so the cascade is seamless. Column-guarded (`?? ''`) so
    // tenants pre-43.206 stay functional.
    $__pgStyleMap = [
        'card_padding'      => '--style-spacing-card-padding',
        'card_radius'       => '--style-border-card-radius',
        'card_border_width' => '--style-border-card-width',
        'element_gap'       => '--style-spacing-element-gap',
    ];
    $__pgStyleRaw = (string) ($page['style_overrides'] ?? '');
    if ($__pgStyleRaw !== '') {
        $__pgStyle = json_decode($__pgStyleRaw, true);
        if (is_array($__pgStyle) && !empty($__pgStyle)) {
            $__pgStyleVars = [];
            foreach ($__pgStyleMap as $__tk => $__cssVar) {
                $__v = trim((string) ($__pgStyle[$__tk] ?? ''));
                // Same validator as the renderer + controller — positive
                // number + px/rem/em/% unit only.
                if ($__v !== '' && preg_match('/^\d{1,4}(\.\d{1,2})?(px|rem|em|%)$/', $__v)) {
                    $__pgStyleVars[] = $__cssVar . ':' . $__v . ';';
                }
            }
            if (!empty($__pgStyleVars)) echo '<style>:root{' . implode('', $__pgStyleVars) . '}</style>';
        }
    }
    $__pgCss = (string) ($page['custom_css'] ?? '');
    if (trim($__pgCss) !== '') {
        // Already sanitized at save time (strip script/style/iframe/
        // on*/javascript:). Emit as-is. Capped at 16 KB at save.
        echo '<style data-page-custom>' . $__pgCss . '</style>';
    }
    ?>
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    /* (theming v2 Phase 4: the --surface-* helper vars were retired — marketing
       sections use data-palette + the ordinal palettes now.) */
    /* Phase 43.11 — public-page chrome consumes --style-* tokens from
       ThemeService::renderOverrideStyle above. Fallbacks preserve the
       pre-existing baseline for installs without admin overrides. */
    body { margin: 0; font-family: var(--font-family-body, 'Inter', system-ui, sans-serif); background: var(--bg-page, var(--color-gray-50)); color: var(--text-default, var(--color-gray-900)); font-size: var(--style-font-body-size, 14px); font-weight: var(--style-font-body-weight, 400); font-style: var(--style-font-body-italic, normal); }
    /* Phase 43.13 — site-header (public-page chrome) picks up the same
       --chrome-header-* tokens as the admin topbar so brand-color picks
       coordinate the entire chrome cluster (header + sidebar + footer). */
    /* Phase 43.14 — site-header chrome border + padding + nav gap tie
       to the --style-* page-style tokens so the chrome rhythm tracks
       the rest of the design system. Fallbacks preserve the pre-existing
       baseline for installs without style overrides. */
    .site-header {
        background: var(--chrome-header-bg, var(--bg-panel, #fff));
        border-bottom: var(--style-border-card-width, 1px) solid var(--border-default, var(--color-gray-200));
        padding: calc(var(--style-spacing-card-padding, 14px) * 0.85) var(--style-spacing-section-padding, 1.5rem);
        color: var(--chrome-header-text, var(--text-default, var(--color-gray-900)));
        display: flex; align-items: center; justify-content: space-between;
    }
    .site-logo { font-weight: var(--style-font-heading-weight, 700); font-size: 1.1rem; text-decoration: none; color: var(--chrome-header-text, var(--text-default, var(--color-gray-900))); }
    .nav-links { display: flex; gap: var(--style-spacing-element-gap, 1.25rem); align-items: center; }
    .nav-links a { text-decoration: none; color: var(--chrome-header-text, var(--text-default, var(--color-gray-700))); font-size: 14px; }
    .nav-links a:hover { color: var(--color-primary, var(--color-primary)); }
    /* Dropdown holders (menu kind=holder with children) */
    .nav-dropdown { position: relative; }
    .nav-dropdown > a, .nav-dropdown-btn {
        background: transparent; border: 0; padding: 0; cursor: pointer;
        font: inherit; font-size: 14px;
        color: var(--chrome-header-text, var(--text-default, var(--color-gray-700)));
        text-decoration: none;
    }
    .nav-dropdown:hover > a, .nav-dropdown-btn:hover { color: var(--color-primary); }
    .nav-dropdown-menu {
        display: none; position: absolute; top: 100%; right: 0; left: auto;
        min-width: 200px; background: var(--bg-panel, #fff);
        border: var(--style-border-card-width, 1px) solid var(--border-default, var(--color-gray-200));
        border-radius: var(--style-border-card-radius, 6px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        padding: .35rem 0; margin-top: .5rem; z-index: 100;
    }
    .nav-dropdown:hover .nav-dropdown-menu,
    .nav-dropdown.open .nav-dropdown-menu { display: block; }
    .nav-dropdown-menu a {
        display: block; padding: .55rem 1rem; font-size: 13.5px; white-space: nowrap;
    }
    .nav-dropdown-menu a:hover { background: var(--accent-subtle, var(--color-gray-50)); }
    .btn-login { background: var(--color-primary, var(--color-primary)); color: var(--accent-contrast, #fff) !important; padding: .4rem .9rem; border-radius: var(--style-border-button-radius, 6px); font-size: 13.5px; font-weight: 500; }

    .page-hero { background: var(--bg-panel, #fff); border-bottom: var(--style-border-card-width, 1px) solid var(--border-default, var(--color-gray-200)); padding: var(--style-spacing-section-padding, 3rem) 1.5rem; text-align: center; }
    .page-hero h1 { font-family: var(--font-family-heading, inherit); font-size: var(--style-font-h1-size, 2rem); font-weight: var(--style-font-heading-weight, 700); font-style: var(--style-font-heading-italic, normal); margin: 0 0 .5rem; color: var(--text-default, var(--color-gray-900)); }
    .page-hero .meta { color: var(--text-muted, var(--color-gray-500)); font-size: 13.5px; }

    .page-body { max-width: <?= ($page['layout'] ?? 'default') === 'wide' ? '1100px' : '760px' ?>; margin: 2.5rem auto; padding: 0 1.5rem; line-height: 1.8; font-size: var(--style-font-body-size, 15px); color: var(--text-default, var(--color-gray-900)); }
    .page-body h2 { font-family: var(--font-family-heading, inherit); font-size: var(--style-font-h2-size, 1.4rem); font-weight: var(--style-font-heading-weight, 700); font-style: var(--style-font-heading-italic, normal); margin-top: 2rem; }
    .page-body h3 { font-family: var(--font-family-heading, inherit); font-size: calc(var(--style-font-h2-size, 1.15rem) * 0.9); font-weight: var(--style-font-heading-weight, 700); font-style: var(--style-font-heading-italic, normal); margin-top: 1.5rem; }
    .page-body p { margin: 0 0 1rem; }
    /* Content links (2026-06-01) — coral for new, teal for visited, so
       there's a warm/cool read on what you've already explored. Hover
       reveals a thicker underline with breathing room. Applies to the
       page body AND rendered composer blocks (markdown etc.). */
    .page-body a, .builder-block a {
        color: var(--color-primary);
        text-decoration: underline;
        text-decoration-color: color-mix(in srgb, var(--color-primary) 38%, transparent);
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
        transition: color .12s, text-decoration-color .12s;
    }
    .page-body a:hover, .builder-block a:hover {
        color: var(--color-primary-dark);
        text-decoration-color: currentColor;
        text-decoration-thickness: 2px;
    }
    .page-body a:visited, .builder-block a:visited { color: var(--color-secondary); }
    .page-body a:visited:hover, .builder-block a:visited:hover {
        color: color-mix(in srgb, var(--color-secondary) 78%, #000);
    }
    .page-body ul, .page-body ol { margin: 0 0 1rem; padding-left: 1.5rem; }
    /* Phase 43.33 — code/pre/kbd inside the page body use the mono slot's
       weight + italic tokens. The family already comes from app.css's
       chrome rule above. */
    .page-body code, .page-body pre, .page-body kbd { font-family: var(--font-family-mono, ui-monospace, monospace); font-weight: var(--style-font-mono-weight, 400); font-style: var(--style-font-mono-italic, normal); }

    /* .site-footer styles now live in app/Views/partials/site_footer.php so
       the authenticated layout and this public page share one appearance. */
    .site-footer { margin-top: 4rem; }

    /* WCAG 2.1 — skip-to-content + focus-visible. Mirrors the
       authenticated chrome's app.css; inlined here so the standalone
       guest shell doesn't need an extra stylesheet load. */
    .skip-link {
        position: absolute; top: -40px; left: 0;
        background: var(--color-primary, var(--color-primary)); color: var(--accent-contrast, #fff); padding: .5rem 1rem;
        z-index: 9999; font-size: 13.5px; font-weight: 600;
        border-radius: 0 0 6px 0; transition: top .15s;
        text-decoration: none;
    }
    .skip-link:focus { top: 0; }
    :focus-visible { outline: 2px solid var(--color-primary, var(--color-primary)); outline-offset: 2px; }
    a:focus-visible, button:focus-visible, input:focus-visible,
    select:focus-visible, textarea:focus-visible {
        outline: 2px solid var(--color-primary, var(--color-primary)); outline-offset: 2px; border-radius: 4px;
    }
    </style>
</head>
<body>
<?php if (setting('accessibility_skip_link_enabled', true)): ?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php endif; ?>
<header class="site-header">
    <a href="/" class="site-logo"><?php
        // Brand lockup: the logo image is an icon MARK shown next to the site
        // name (so it reads "[mark] SiteName"). Falls back to the 🚀 glyph +
        // name when no logo is set.
        $__logoRel  = setting('builder.branding.logo_url', '');
        $__siteName = htmlspecialchars((string) setting('site_name', 'App'), ENT_QUOTES);
        if ($__logoRel !== '') {
            $__logoUrl = (new \Core\Services\FileUploadService())->url($__logoRel);
            echo '<img src="' . htmlspecialchars($__logoUrl, ENT_QUOTES) . '" alt="' . $__siteName . '" style="max-height:32px;width:auto;vertical-align:middle;border-radius:6px"> ' . $__siteName;
        } else {
            echo '🚀 ' . $__siteName;
        }
    ?></a>
    <nav class="nav-links">
        <?php
        // Render the configured header menu (menus.location='header') for
        // guest pages, mirroring layout/header.php's authed-shell pattern.
        // menu() applies per-item visibility internally so 'logged_in'
        // items naturally hide for guests. Falls back to a hardcoded FAQ
        // link when no menu items resolve so an unconfigured install
        // still has something usable in the header.
        $__pubHeaderItems = function_exists('menu') ? menu('header') : [];
        if (empty($__pubHeaderItems)) {
            echo '<a href="/faq">FAQ</a>';
        } else {
            foreach ($__pubHeaderItems as $__pubItem) {
                $__pubLabel = htmlspecialchars((string)($__pubItem['label'] ?? ''), ENT_QUOTES);
                if (!empty($__pubItem['children'])) {
                    echo '<div class="nav-dropdown" onclick="this.classList.toggle(\'open\')">';
                    if (!empty($__pubItem['url'])) {
                        echo '<a href="' . htmlspecialchars((string)$__pubItem['url'], ENT_QUOTES) . '">' . $__pubLabel . ' ▾</a>';
                    } else {
                        echo '<button type="button" class="nav-dropdown-btn">' . $__pubLabel . ' ▾</button>';
                    }
                    echo '<div class="nav-dropdown-menu">';
                    foreach ($__pubItem['children'] as $__pubChild) {
                        echo '<a href="' . htmlspecialchars((string)($__pubChild['url'] ?? '#'), ENT_QUOTES) . '">'
                           . htmlspecialchars((string)($__pubChild['label'] ?? ''), ENT_QUOTES) . '</a>';
                    }
                    echo '</div></div>';
                } else {
                    echo '<a href="' . htmlspecialchars((string)($__pubItem['url'] ?? '#'), ENT_QUOTES) . '">' . $__pubLabel . '</a>';
                }
            }
        }
        ?>
        <?php if (\Core\Auth\Auth::getInstance()->guest()): ?>
        <a href="/login" class="btn-login">Sign In</a>
        <?php endif; ?>
    </nav>
</header>

<?php
// Per-page main wrapper spacing — guest shell variant (added 2026-06-03).
// Always emits all 6 properties (including 0s) so the user's settings
// are authoritative — including "all zeros" which means truly zero.
$__pmtG = (int) ($page['main_margin_top_px']    ?? 0);
$__pmbG = (int) ($page['main_margin_bottom_px'] ?? 0);
$__ppxG = (int) ($page['main_padding_x_px']     ?? 0);
$__ppyG = (int) ($page['main_padding_y_px']     ?? 0);
$__mainStylePartsG = [
    'margin-top:'    . $__pmtG . 'px',
    'margin-bottom:' . $__pmbG . 'px',
    'padding-top:'    . $__ppyG . 'px',
    'padding-bottom:' . $__ppyG . 'px',
    'padding-left:'  . $__ppxG . 'px',
    'padding-right:' . $__ppxG . 'px',
];
$__mainStyleG = ' style="' . implode(';', $__mainStylePartsG) . '"';
?>
<main id="main-content"<?= $__mainStyleG ?>>
<?php if (empty($page['hide_title'])): ?>
<div class="page-hero">
    <h1><?= e($page['title']) ?></h1>
</div>
<?php endif; ?>

<?php $__renderBody(); ?>
</main>

<?php include BASE_PATH . '/app/Views/partials/site_footer.php'; ?>

<?php
// GDPR cookie-consent banner — guest path. The authed branch above
// renders this via layout/footer.php; this include covers the
// standalone shell so the banner appears on public marketing pages too.
// Gated on cookieconsent.banner-ui (off when external CMP handles UI).
$__cc = BASE_PATH . '/modules/cookieconsent/Views/banner.php';
if (file_exists($__cc)
    && (!class_exists(\Core\Module\SubmoduleRegistry::class)
        || \Core\Module\SubmoduleRegistry::featureEnabled('cookieconsent', 'banner-ui'))) {
    include $__cc;
}
?>
</body>
</html>
<?php endif; ?>
