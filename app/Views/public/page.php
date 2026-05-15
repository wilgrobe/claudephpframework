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
    .page-body a  { color: var(--color-primary); }
    .page-body ul, .page-body ol { margin: 0 0 1rem; padding-left: 1.5rem; }
    </style>

    <div class="page-hero">
        <h1><?= e($page['title']) ?></h1>
    </div>

    <?php $__renderBody(); ?>

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
    ?>
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: var(--font-family-body, 'Inter', system-ui, sans-serif); background: var(--bg-page, var(--color-gray-50)); color: var(--text-default, var(--color-gray-900)); }
    .site-header {
        background: var(--bg-panel, #fff); border-bottom: 1px solid var(--border-default, var(--color-gray-200)); padding: .85rem 1.5rem;
        display: flex; align-items: center; justify-content: space-between;
    }
    .site-logo { font-weight: 700; font-size: 1.1rem; text-decoration: none; color: var(--text-default, var(--color-gray-900)); }
    .nav-links { display: flex; gap: 1.25rem; align-items: center; }
    .nav-links a { text-decoration: none; color: var(--text-default, var(--color-gray-700)); font-size: 14px; }
    .nav-links a:hover { color: var(--color-primary, var(--color-primary)); }
    .btn-login { background: var(--color-primary, var(--color-primary)); color: var(--accent-contrast, #fff) !important; padding: .4rem .9rem; border-radius: var(--radius-md, 6px); font-size: 13.5px; font-weight: 500; }

    .page-hero { background: var(--bg-panel, #fff); border-bottom: 1px solid var(--border-default, var(--color-gray-200)); padding: 3rem 1.5rem; text-align: center; }
    .page-hero h1 { font-family: var(--font-family-heading, inherit); font-size: 2rem; font-weight: 700; margin: 0 0 .5rem; color: var(--text-default, var(--color-gray-900)); }
    .page-hero .meta { color: var(--text-muted, var(--color-gray-500)); font-size: 13.5px; }

    .page-body { max-width: <?= ($page['layout'] ?? 'default') === 'wide' ? '1100px' : '760px' ?>; margin: 2.5rem auto; padding: 0 1.5rem; line-height: 1.8; font-size: 15px; color: var(--text-default, var(--color-gray-900)); }
    .page-body h2 { font-family: var(--font-family-heading, inherit); font-size: 1.4rem; margin-top: 2rem; }
    .page-body h3 { font-family: var(--font-family-heading, inherit); font-size: 1.15rem; margin-top: 1.5rem; }
    .page-body p { margin: 0 0 1rem; }
    .page-body a { color: var(--color-primary, var(--color-primary)); }
    .page-body ul, .page-body ol { margin: 0 0 1rem; padding-left: 1.5rem; }

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
        $__logoRel = setting('builder.branding.logo_url', '');
        if ($__logoRel !== '') {
            $__logoUrl = (new \Core\Services\FileUploadService())->url($__logoRel);
            echo '<img src="' . htmlspecialchars($__logoUrl, ENT_QUOTES) . '" alt="' . htmlspecialchars((string) setting('site_name', 'App'), ENT_QUOTES) . '" style="max-height:36px;vertical-align:middle">';
        } else {
            echo '🚀 ' . htmlspecialchars((string) setting('site_name', 'App'), ENT_QUOTES);
        }
    ?></a>
    <nav class="nav-links">
        <a href="/faq">FAQ</a>
        <a href="/login" class="btn-login">Sign In</a>
    </nav>
</header>

<main id="main-content">
<div class="page-hero">
    <h1><?= e($page['title']) ?></h1>
</div>

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
