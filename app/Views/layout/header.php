<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Global CSRF token so JS helpers (csrfPost in app.js) can always find
         one, regardless of whether the current page happens to render a form
         with csrf_field(). Without this, AJAX POSTs silently 419 on pages
         where no form is present — which is exactly how the notification × /
         Mark Read actions fail on the dashboard and notifications index. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <?= \Core\SEO\SeoManager::metaTags([
        'title'       => ($seoTitle ?? $pageTitle ?? 'Dashboard') . ' — ' . setting('site_name', 'App'),
        'description' => $seoDescription ?? setting('site_tagline', ''),
        'keywords'    => $seoKeywords ?? null,
        'image'       => $seoOgImage ?? null,
        'canonical'   => $canonical ?? null,
    ]) ?>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
    <?php
    // Dynamic font <link>s from ThemeService.
    // Emits preconnect + the curated Google Fonts links for each slot in
    // active use, plus any admin-pasted custom <link> URLs from the
    // theme.font.custom_links setting. When no admin overrides exist,
    // the four font slots all default to Inter so this still emits the
    // Inter link that used to be hardcoded here.
    echo app(\Core\Services\ThemeService::class)->renderFontLinks();
    ?>
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root {
        --color-primary: #4f46e5;
        --color-primary-dark: #3730a3;
        --color-secondary: #0ea5e9;
        --color-success: #10b981;
        --color-danger: #ef4444;
        --color-warning: #f59e0b;
        --color-info: #3b82f6;
        --color-gray-50: #f9fafb;
        --color-gray-100: #f3f4f6;
        --color-gray-200: #e5e7eb;
        --color-gray-300: #d1d5db;
        --color-gray-500: #6b7280;
        --color-gray-700: #374151;
        --color-gray-900: #111827;
        --font: 'Inter', system-ui, sans-serif;
        --radius: 8px;
        --shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,.1);
        --sidebar-width: 240px;
    }
    body {
        margin: 0;
        font-family: var(--font);
        background: var(--bg-page);
        color: var(--text-default);
        font-size: 14px;
        line-height: 1.6;
    }

    /* Superadmin / emulation banners */
    .banner-superadmin {
        background: #7c3aed; color: #fff;
        padding: .5rem 1rem; text-align: center; font-size: 13px; font-weight: 600;
        display: flex; align-items: center; justify-content: center; gap: 1rem;
    }
    .banner-emulating {
        background: #dc2626; color: #fff;
        padding: .5rem 1rem; text-align: center; font-size: 13px; font-weight: 600;
        display: flex; align-items: center; justify-content: center; gap: 1rem;
        animation: pulse 2s infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.85} }
    .banner-emulating form, .banner-superadmin form { display:inline; }
    .banner-emulating button, .banner-superadmin button {
        background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4);
        color: #fff; padding: .2rem .7rem; border-radius: 4px; cursor: pointer; font-size: 12px;
    }

    /* Layout */
    .layout { display: flex; min-height: 100vh; }
    .sidebar {
        width: var(--sidebar-width); background: var(--chrome-sidebar-bg);
        color: var(--chrome-sidebar-text); display: flex; flex-direction: column;
        position: sticky; top: 0; height: 100vh; overflow-y: auto; flex-shrink: 0;
    }
    .sidebar-logo {
        padding: 1.25rem 1.5rem; font-size: 1.1rem; font-weight: 700;
        color: #fff; border-bottom: 1px solid rgba(255,255,255,.08);
        text-decoration: none; display: block;
    }
    .sidebar-nav { padding: .75rem 0; flex: 1; }
    .sidebar-nav a, .sidebar-nav button {
        display: flex; align-items: center; gap: .6rem;
        padding: .55rem 1.5rem; color: var(--chrome-sidebar-text); text-decoration: none;
        font-size: 13.5px; border: none; background: none; width: 100%; text-align: left;
        cursor: pointer; border-radius: 0; transition: background .15s, color .15s;
    }
    .sidebar-nav a:hover, .sidebar-nav button:hover { background: rgba(255,255,255,.07); color: #fff; }
    .sidebar-nav a.active { background: var(--color-primary); color: #fff; }
    .sidebar-section { padding: .5rem 1.5rem .25rem; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .08em; color: #6366f1; margin-top: .5rem; }
    .sidebar-nav .submenu { padding-left: 1rem; display: none; }
    .sidebar-nav .has-submenu.open .submenu { display: block; }
    .sidebar-nav .submenu a { padding-left: 2.2rem; font-size: 13px; color: var(--chrome-sidebar-text); opacity: .85; }

    /* Main area */
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .topbar {
        background: var(--bg-panel); border-bottom: 1px solid var(--border-default);
        padding: .75rem 1.5rem; display: flex; align-items: center;
        justify-content: space-between; gap: 1rem; position: sticky; top: 0; z-index: 10;
        box-shadow: var(--shadow);
    }
    .topbar-left { display: flex; align-items: center; gap: 1rem; }
    .topbar-right { display: flex; align-items: center; gap: .75rem; }
    .page-title { font-size: 1rem; font-weight: 600; color: var(--text-default); margin: 0; }

    /* Content */
    .content { padding: 1.5rem; flex: 1; }

    /* Cards */
    .card {
        background: var(--bg-panel); border-radius: var(--radius); box-shadow: var(--shadow);
        border: 1px solid var(--border-default);
    }
    .card-header {
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-default);
        display: flex; align-items: center; justify-content: space-between;
    }
    .card-header h2 { margin: 0; font-size: .95rem; font-weight: 600; }
    .card-body { padding: 1.25rem; }

    /* Alert / Flash */
    .alert {
        padding: .85rem 1rem; border-radius: var(--radius); margin-bottom: 1rem;
        font-size: 13.5px; display: flex; align-items: center; gap: .5rem;
    }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .alert-warning  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .alert-info    { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

    /* Forms */
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; font-weight: 500; margin-bottom: .35rem; font-size: 13.5px; }
    .form-control {
        width: 100%; padding: .55rem .75rem; border: 1px solid var(--border-strong);
        border-radius: 6px; font-size: 14px; font-family: var(--font);
        transition: border-color .15s, box-shadow .15s; background: var(--bg-panel);
        color: var(--text-default);
    }
    .form-control:focus {
        outline: none; border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,.15);
    }
    .form-control.is-invalid { border-color: var(--color-danger); }
    .form-error { color: var(--color-danger); font-size: 12px; margin-top: .25rem; display: block; }
    textarea.form-control { resize: vertical; min-height: 100px; }
    select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; padding-right: 2rem; }

    /* Buttons */
    .btn {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .5rem 1rem; border-radius: 6px; font-weight: 500; font-size: 13.5px;
        cursor: pointer; text-decoration: none; border: 1px solid transparent;
        transition: all .15s; font-family: var(--font); line-height: 1.4;
    }
    .btn-primary   { background: var(--color-primary); color: var(--accent-contrast); border-color: var(--color-primary); }
    .btn-primary:hover { background: var(--color-primary-dark); border-color: var(--color-primary-dark); }
    .btn-secondary { background: var(--bg-panel); color: var(--color-gray-700); border-color: var(--border-strong); }
    .btn-secondary:hover { background: var(--bg-page); }
    .btn-danger    { background: var(--color-danger); color: var(--accent-contrast); border-color: var(--color-danger); }
    .btn-danger:hover { background: #dc2626; }
    .btn-success   { background: var(--color-success); color: var(--accent-contrast); border-color: var(--color-success); }
    .btn-sm { padding: .3rem .65rem; font-size: 12.5px; }
    .btn-xs { padding: .2rem .5rem; font-size: 11.5px; }

    /* Tables */
    .table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .table th, .table td { padding: .65rem 1rem; text-align: left; border-bottom: 1px solid var(--border-subtle); }
    .table th { font-weight: 600; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; background: var(--bg-page); }
    .table tbody tr:hover { background: var(--bg-page); }
    .table-responsive { overflow-x: auto; }

    /* Badges */
    .badge {
        display: inline-block; padding: .2rem .55rem; border-radius: 999px;
        font-size: 11px; font-weight: 600; line-height: 1;
    }
    .badge-primary { background: #e0e7ff; color: #3730a3; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-danger  { background: #fee2e2; color: #991b1b; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-gray    { background: var(--border-subtle); color: var(--color-gray-700); }

    /* Grid */
    .grid { display: grid; gap: 1rem; }
    .grid-2 { grid-template-columns: repeat(2, 1fr); }
    .grid-3 { grid-template-columns: repeat(3, 1fr); }
    .grid-4 { grid-template-columns: repeat(4, 1fr); }

    /* Stats card */
    .stat-card {
        background: var(--bg-panel); border-radius: var(--radius); padding: 1.25rem;
        border: 1px solid var(--border-default); box-shadow: var(--shadow);
    }
    .stat-card .stat-label { font-size: 12px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }
    .stat-card .stat-value { font-size: 2rem; font-weight: 700; color: var(--text-default); line-height: 1.2; margin-top: .25rem; }

    /* Toggle switch */
    .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute; inset: 0; background: var(--border-strong);
        border-radius: 999px; cursor: pointer; transition: background .2s;
    }
    .toggle-slider::before {
        content: ''; position: absolute; width: 18px; height: 18px;
        left: 3px; top: 3px; background: var(--bg-panel); border-radius: 50%;
        transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    .toggle-switch input:checked + .toggle-slider { background: var(--color-primary); }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

    /* Avatar */
    .avatar {
        width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
        background: var(--color-primary); color: var(--accent-contrast);
        display: inline-flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 13px;
    }

    /* User dropdown */
    .user-menu { position: relative; }
    .user-menu-toggle { background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: .5rem; padding: .3rem .5rem; border-radius: 6px; color: inherit; font-family: inherit; font-size: inherit; }
    .user-menu-toggle:hover { background: var(--border-subtle); }
    .dropdown-menu {
        position: absolute; right: 0; top: 100%; margin-top: .35rem;
        background: var(--bg-panel); border: 1px solid var(--border-default); border-radius: var(--radius);
        box-shadow: var(--shadow-md); min-width: 180px; z-index: 100; display: none;
    }
    .dropdown-menu.open { display: block; }
    .dropdown-menu a, .dropdown-menu button {
        display: block; width: 100%; text-align: left; padding: .55rem 1rem;
        font-size: 13.5px; color: var(--color-gray-700); text-decoration: none;
        background: none; border: none; cursor: pointer; font-family: var(--font);
    }
    .dropdown-menu a:hover, .dropdown-menu button:hover { background: var(--bg-page); }
    .dropdown-divider { border-top: 1px solid var(--border-default); margin: .25rem 0; }

    /* Notifications bell */
    .notif-bell { position: relative; }
    .notif-count {
        position: absolute; top: -4px; right: -4px;
        background: var(--color-danger); color: var(--accent-contrast); border-radius: 999px;
        font-size: 10px; font-weight: 700; padding: 1px 5px; line-height: 1.4;
    }

    /* Pagination */
    .pagination { display: flex; gap: .35rem; align-items: center; flex-wrap: wrap; }
    .pagination a, .pagination span {
        padding: .35rem .65rem; border-radius: 6px; font-size: 13px;
        border: 1px solid var(--border-default); text-decoration: none; color: var(--color-gray-700);
    }
    .pagination a:hover { background: var(--bg-page); }
    .pagination .current { background: var(--color-primary); color: var(--accent-contrast); border-color: var(--color-primary); }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { display: none; }
        .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
    }

    /* ── Topbar layout variant ─────────────────────────────────────────
       Chosen via /admin/settings/appearance. When active, the sidebar is
       hidden and the main navigation runs horizontally across the top.
       The page-title in the topbar is also hidden since a logo + nav
       takes that space; pages are responsible for their own h1. */
    .layout--topbar .sidebar    { display: none; }
    .layout--topbar .main       { width: 100%; }
    .layout--topbar .page-title { display: none; }

    .topbar-nav {
        display: flex; align-items: center; gap: .15rem .35rem;
        flex-wrap: wrap; flex: 1; min-width: 0;
    }
    .topbar-logo {
        font-weight: 700; font-size: 1.05rem; color: var(--text-default);
        text-decoration: none; padding: 0 1rem 0 0;
        border-right: 1px solid var(--border-default); margin-right: .5rem;
        white-space: nowrap;
    }
    .topbar-nav > a, .topbar-nav .topbar-dd-toggle {
        color: var(--color-gray-700); text-decoration: none;
        font-size: 13.5px; padding: .4rem .7rem; border-radius: 6px;
        background: none; border: none; cursor: pointer; white-space: nowrap;
    }
    .topbar-nav > a:hover, .topbar-nav .topbar-dd-toggle:hover {
        color: var(--color-primary); background: var(--accent-subtle);
    }
    .topbar-dd           { position: relative; }
    .topbar-dd-menu {
        display: none; position: absolute; top: 100%; left: 0;
        background: var(--bg-panel); border: 1px solid var(--border-default);
        border-radius: 6px; box-shadow: var(--shadow-md);
        min-width: 180px; padding: .35rem; z-index: 20;
    }
    .topbar-dd.open .topbar-dd-menu { display: block; }
    .topbar-dd-menu a {
        display: block; padding: .4rem .65rem; border-radius: 4px;
        color: var(--color-gray-700); text-decoration: none; font-size: 13.5px;
    }
    .topbar-dd-menu a:hover { background: var(--accent-subtle); color: var(--color-primary); }

    /* Topbar admin dropdown — sectioned variant. The dropdown gets a
       little wider and a max-height so a long admin nav can scroll
       inside the dropdown rather than blowing past the viewport. */
    .topbar-dd-menu--sections {
        min-width: 220px; max-height: 70vh; overflow-y: auto; padding: .25rem;
    }
    .topbar-dd-section {
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: var(--text-muted);
        padding: .55rem .65rem .25rem;
    }
    .topbar-dd-section:not(:first-child) {
        border-top: 1px solid var(--border-subtle); margin-top: .15rem;
    }

    /* Sidebar admin accordions. Built on <details>/<summary> so keyboard
       and screen-reader semantics come for free; we just restyle the
       chrome.

       The native disclosure marker is hidden (Webkit + standards both
       covered) and replaced with a ▸ that rotates to ▾ when [open]. */
    .sidebar-accordion { margin: 0; padding: 0; }
    .sidebar-accordion > summary {
        display: flex; align-items: center; gap: .5rem;
        padding: .45rem 1.5rem; color: var(--chrome-sidebar-text);
        font-size: 12px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; cursor: pointer; user-select: none;
        list-style: none; transition: background .15s, color .15s;
    }
    .sidebar-accordion > summary::-webkit-details-marker { display: none; }
    .sidebar-accordion > summary::after {
        content: '▸'; margin-left: auto; font-size: 10px;
        color: #6366f1; transition: transform .15s;
    }
    .sidebar-accordion[open] > summary::after { transform: rotate(90deg); }
    .sidebar-accordion > summary:hover { background: rgba(255,255,255,.05); color: #fff; }
    .sidebar-accordion-item {
        display: block; padding: .4rem 1.5rem .4rem 2.4rem !important;
        color: var(--chrome-sidebar-text) !important; text-decoration: none;
        font-size: 13px !important; font-weight: 400; text-transform: none !important;
        letter-spacing: 0 !important;
    }
    .sidebar-accordion-item:hover { background: rgba(255,255,255,.07); color: #fff !important; }

    /* On narrow screens keep behavior sane even in topbar mode. Already
       under the 768px sidebar-hide rule above, so topbar is effectively
       the only option for phones. */
    @media (max-width: 640px) {
        .topbar-logo { border-right: none; padding-right: .5rem; }
    }
    </style>

    <?php
    // Theme overrides: ThemeService resolves the saved tokens (currently
    // the seven brand colors; Batch B will add bg/text/border/radius/font)
    // and emits a <style> :root block that overrides the baseline above.
    // Empty/missing settings fall through; values that fail the CSS-color
    // regex are dropped silently (defends against ; injection that
    // htmlspecialchars alone wouldn't catch).
    //
    // ThemeService::resolveTokens() takes an optional $context arg for
    // future group/user scope cascading - kept as nullopt for v1 so this
    // call site doesn't need updating when scope theming lands.
    echo app(\Core\Services\ThemeService::class)->renderOverrideStyle();
    ?>

    <?php
    // Analytics tracking snippet — renders the configured provider's
    // script tag (Plausible / GA / Umami), or nothing when
    // ANALYTICS_PROVIDER=none. Emitted last so custom theme styles
    // above still load first.
    echo \Core\Services\AnalyticsService::snippet();
    ?>
</head>
<?php
// Resolve effective theme preference for THIS request:
//   - logged-in user: users.theme_preference
//   - guest:          theme_pref cookie
//   - default:        'system' (no body class; OS preference fires the
//                      @media (prefers-color-scheme: dark) rule)
$__themeAuth = \Core\Auth\Auth::getInstance();
$__themeUser = $__themeAuth->user();
$__themePref = (string) ($__themeUser['theme_preference'] ?? '');
if ($__themePref === '' || !in_array($__themePref, ['system','light','dark'], true)) {
    $__themePref = (string) ($_COOKIE['theme_pref'] ?? 'system');
    if (!in_array($__themePref, ['system','light','dark'], true)) {
        $__themePref = 'system';
    }
}
$__themeClass = match ($__themePref) {
    'dark'  => 'theme-dark',
    'light' => 'theme-light',
    default => '',
};
?>
<body<?= $__themeClass ? ' class="' . $__themeClass . '"' : '' ?>>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php $auth = $__themeAuth; ?>

<?php if ($auth->isEmulating()): ?>
<div class="banner-emulating">
    ⚠️ EMULATING USER: <?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '') . ' &lt;' . ($user['email'] ?? '') . '&gt;') ?>
    — All actions are being logged
    <form method="POST" action="/admin/emulate/stop"><?= csrf_field() ?>
        <button type="submit">Stop Emulating</button>
    </form>
</div>
<?php elseif ($auth->isSuperadminModeOn()): ?>
<div class="banner-superadmin">
    🛡️ SUPERADMIN MODE ACTIVE
    <form method="POST" action="/admin/superadmin/toggle-mode"><?= csrf_field() ?>
        <input type="hidden" name="enable" value="0">
        <button type="submit">Disable</button>
    </form>
</div>
<?php endif; ?>

<?php if ($auth->check() && empty($auth->user()['email_verified_at'])): ?>
<!-- Unverified-email banner — shown on every authenticated page until the
     user clicks the link in their inbox. The resend POST is rate-limited
     server-side to one per 60 seconds. -->
<div style="background:#fef3c7;color:#92400e;border-bottom:1px solid #fde68a;padding:.6rem 1rem;font-size:13.5px;display:flex;justify-content:center;align-items:center;gap:.75rem;flex-wrap:wrap">
    <span>📬 Your email address is not verified. Check your inbox for the verification link.</span>
    <form method="POST" action="/auth/resend-verification" style="margin:0">
        <?= csrf_field() ?>
        <button type="submit" style="background:#d97706;color:#fff;border:none;padding:.3rem .75rem;border-radius:4px;font-size:12.5px;font-weight:600;cursor:pointer">
            Resend email
        </button>
    </form>
</div>
<?php endif; ?>

<?php
// Layout orientation: 'sidebar' (default) or 'topbar'. In topbar mode
// the sidebar is hidden via CSS and the topbar renders its own nav row.
// We keep the sidebar markup in both modes — easier to reason about, and
// hiding via CSS means no duplicated link lists.
$__layout_orient = (string) setting('layout_orientation', 'sidebar');
if (!in_array($__layout_orient, ['sidebar', 'topbar'], true)) $__layout_orient = 'sidebar';
?>
<div class="layout layout--<?= e($__layout_orient) ?>">
    <!-- Sidebar -->
    <aside class="sidebar">
        <a href="/dashboard" class="sidebar-logo">🚀 <?= e(setting('site_name', 'App')) ?></a>
        <nav class="sidebar-nav">
            <?php foreach (menu('header') as $menuItem): ?>
                <?php if (!empty($menuItem['children'])): ?>
                    <div class="has-submenu" onclick="this.classList.toggle('open')">
                        <?php if ($menuItem['url']): ?>
                            <a href="<?= e($menuItem['url']) ?>"><?= e($menuItem['label']) ?> ▾</a>
                        <?php else: ?>
                            <button><?= e($menuItem['label']) ?> ▾</button>
                        <?php endif; ?>
                        <div class="submenu">
                            <?php foreach ($menuItem['children'] as $menuChild): ?>
                                <a href="<?= e($menuChild['url'] ?? '#') ?>"><?= e($menuChild['label']) ?></a>
                            <?php endforeach; unset($menuChild); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= e($menuItem['url'] ?? '#') ?>"><?= e($menuItem['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; unset($menuItem); ?>

            <?php if ($auth->check()): ?>
            <?php if (module_active('content')): ?><a href="/content">My Content</a><?php endif; ?>
            <?php if (module_active('profile')): ?><a href="/profile/2fa">🛡️ Two-Factor Auth</a><?php endif; ?>
            <?php endif; ?>

            <?php
            // ── Admin nav definition ────────────────────────────────────
            // One source of truth, rendered twice below: as collapsible
            // accordions in the sidebar, and as a single dropdown with
            // section headers in the topbar. Each item is
            // [label, url, module-name-or-null]. When module-name is set,
            // the link only renders if module_active(...) returns true,
            // so disabling a module makes its admin entries vanish from
            // both nav surfaces.
            $adminNav = [
                ['key' => 'users',       'icon' => '👥', 'label' => 'Users & Access', 'items' => [
                    ['Users',           '/admin/users',     null],
                    ['Roles',           '/admin/roles',     null],
                    ['Groups',          '/admin/groups',    'groups'],
                    ['Active Sessions', '/admin/sessions',  null],
                ]],
                ['key' => 'content',     'icon' => '📝', 'label' => 'Content', 'items' => [
                    ['Pages',          '/admin/pages', 'pages'],
                    ['Knowledge Base', '/admin/kb',    'knowledge_base'],
                    ['FAQ',            '/admin/faqs',  'faq'],
                ]],
                ['key' => 'commerce',    'icon' => '🛒', 'label' => 'Commerce', 'items' => [
                    ['Store products',     '/admin/store/products',      'store'],
                    ['Store orders',       '/admin/store/orders',        'store'],
                    ['Shipping zones',     '/admin/store/shipping',      'store'],
                    ['Tax rules',          '/admin/store/tax',           'store'],
                    ['Store settings',     '/admin/store/settings',      'store'],
                    ['Subscription plans', '/admin/subscription-plans',  'subscriptions'],
                    ['Subscriptions',      '/admin/subscriptions',       'subscriptions'],
                    ['Coupons',            '/admin/coupons',             'coupons'],
                    ['Invoices',           '/admin/invoices',            'invoicing'],
                ]],
                ['key' => 'engagement',  'icon' => '💬', 'label' => 'Engagement', 'items' => [
                    ['Polls',         '/admin/polls',          'polls'],
                    ['Events',        '/admin/events',         'events'],
                    ['Reviews',       '/admin/reviews',        'reviews'],
                    ['Comments',      '/admin/comments',       'comments'],
                    ['Activity feed', '/admin/activity',       'activity_feed'],
                ]],
                ['key' => 'operations',  'icon' => '🛠️', 'label' => 'Operations', 'items' => [
                    ['Helpdesk',                '/admin/helpdesk',                   'helpdesk'],
                    ['Moderation reports',      '/admin/moderation/reports',         'moderation'],
                    ['Report notifications',    '/admin/moderation/notify-settings', 'moderation'],
                    ['Scheduling',              '/admin/scheduling/resources',       'scheduling'],
                    ['Audit log',               '/admin/audit-log',                  'audit_log_viewer'],
                    ['Import / export',         '/admin/import',                     'import_export'],
                ]],
                ['key' => 'configuration', 'icon' => '⚙️', 'label' => 'Configuration', 'items' => [
                    ['Menus',         '/admin/menus',         'menus'],
                    ['Forms',         '/admin/forms',         'forms'],
                    ['Taxonomy',      '/admin/taxonomy/sets', 'taxonomy'],
                    ['Hierarchies',   '/admin/hierarchies',   'hierarchies'],
                    ['Feature flags', '/admin/feature-flags', 'feature_flags'],
                    ['Translations',  '/admin/i18n',          'i18n'],
                ]],
            ];

            // Filter each section's items by module_active and drop any
            // section that ends up with zero visible items so admins
            // don't see empty "Engagement" headers when every
            // engagement module is disabled.
            $adminNavRendered = [];
            foreach ($adminNav as $section) {
                $visible = array_values(array_filter($section['items'], function ($i) {
                    return $i[2] === null || module_active($i[2]);
                }));
                if (!empty($visible)) {
                    $section['items'] = $visible;
                    $adminNavRendered[] = $section;
                }
            }
            ?>

            <?php if ($auth->check() && $auth->hasRole(['super-admin','admin'])): ?>
            <div class="sidebar-section">Admin</div>
            <?php foreach ($adminNavRendered as $section): ?>
            <details class="sidebar-accordion" open>
                <summary><?= $section['icon'] ?> <?= e($section['label']) ?></summary>
                <?php foreach ($section['items'] as $item): ?>
                <a href="<?= e($item[1]) ?>" class="sidebar-accordion-item"><?= e($item[0]) ?></a>
                <?php endforeach; ?>
            </details>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($auth->isSuperAdmin()): ?>
            <div class="sidebar-section">Superadmin</div>
            <a href="/admin/superadmin">SA Dashboard</a>
            <a href="/admin/superadmin/users">All Users</a>
            <a href="/admin/superadmin/audit-log">Audit Log</a>
            <a href="/admin/superadmin/message-log">Message Log</a>
            <a href="/admin/settings">Site Settings</a>
            <?php if (module_active('integrations')): ?><a href="/admin/integrations">Integrations</a><?php endif; ?>
            <a href="/admin/modules">Modules</a>
            <a href="/admin/system-layouts">System Layouts</a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Main -->
    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <?php if ($__layout_orient === 'topbar'): ?>
                    <a href="/dashboard" class="topbar-logo">🚀 <?= e(setting('site_name', 'App')) ?></a>
                    <nav class="topbar-nav" aria-label="Primary">
                        <?php foreach (menu('header') as $__tb_item): ?>
                            <a href="<?= e($__tb_item['url'] ?? '#') ?>"><?= e($__tb_item['label']) ?></a>
                        <?php endforeach; unset($__tb_item); ?>

                        <?php if ($auth->check()): ?>
                            <?php if (module_active('content')): ?><a href="/content">My Content</a><?php endif; ?>
                        <?php endif; ?>

                        <?php if ($auth->check() && $auth->hasRole(['super-admin','admin']) && !empty($adminNavRendered)): ?>
                        <!-- Admin dropdown. Clicking the toggle flips .open on the
                             parent .topbar-dd; the existing body-level click handler
                             in footer.php closes dropdowns when the user clicks
                             outside.

                             Pulls from the same $adminNavRendered array the
                             sidebar consumes so adding a category in one place
                             updates both surfaces. Renders as a single tall
                             dropdown with section headers (no nested submenus —
                             the topbar dropdown UX is awkward for two-level
                             interaction; the section headers provide the
                             grouping cue without requiring a second hover). -->
                        <div class="topbar-dd">
                            <button type="button" class="topbar-dd-toggle" onclick="this.parentElement.classList.toggle('open')">Admin ▾</button>
                            <div class="topbar-dd-menu topbar-dd-menu--sections">
                                <?php foreach ($adminNavRendered as $__tb_section): ?>
                                <div class="topbar-dd-section"><?= $__tb_section['icon'] ?> <?= e($__tb_section['label']) ?></div>
                                <?php foreach ($__tb_section['items'] as $__tb_item): ?>
                                <a href="<?= e($__tb_item[1]) ?>"><?= e($__tb_item[0]) ?></a>
                                <?php endforeach; ?>
                                <?php endforeach; unset($__tb_section, $__tb_item); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($auth->isSuperAdmin()): ?>
                        <div class="topbar-dd">
                            <button type="button" class="topbar-dd-toggle" onclick="this.parentElement.classList.toggle('open')">Superadmin ▾</button>
                            <div class="topbar-dd-menu">
                                <a href="/admin/superadmin">SA Dashboard</a>
                                <a href="/admin/superadmin/users">All Users</a>
                                <a href="/admin/superadmin/audit-log">Audit Log</a>
                                <a href="/admin/superadmin/message-log">Message Log</a>
                                <a href="/admin/settings">Site Settings</a>
                                <?php if (module_active('integrations')): ?><a href="/admin/integrations">Integrations</a><?php endif; ?>
                                <a href="/admin/modules">Modules</a>
                                <a href="/admin/system-layouts">System Layouts</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </nav>
                <?php else: ?>
                    <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
                <?php endif; ?>
            </div>
            <div class="topbar-right">
                <!-- Inline search -->
                <form method="GET" action="/search" style="display:flex;align-items:center">
                    <input type="text" name="q" placeholder="Search…" aria-label="Search"
                           class="form-control" style="width:180px;padding:.35rem .65rem;font-size:13px">
                </form>
                <?php if ($auth->check()): ?>
                <!-- Superadmin mode toggle -->
                <?php if ($auth->isSuperAdmin() && !$auth->isEmulating()): ?>
                <form method="POST" action="/admin/superadmin/toggle-mode" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="enable" value="<?= $auth->isSuperadminModeOn() ? 0 : 1 ?>">
                    <label class="toggle-switch" title="Superadmin Mode">
                        <input type="checkbox" onchange="this.form.submit()" <?= $auth->isSuperadminModeOn() ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </form>
                <?php endif; ?>

                <!-- Notifications — entire bell+badge group skipped when
                     the notifications module is disabled, so a 404 link
                     and an orphaned unread count don't show up. -->
                <?php if (module_active('notifications')): ?>
                <div class="notif-bell">
                    <a href="/notifications" style="font-size:1.2rem; text-decoration:none;">🔔</a>
                    <?php
                    $notifSvc = new \Core\Services\NotificationService();
                    $unread   = count($notifSvc->getUnread($auth->id(), 99));
                    if ($unread): ?>
                    <span class="notif-count"><?= $unread ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Messaging inbox icon — separate badge from the bell
                     so users don't have to fish through general
                     notifications to see they have unread DMs. Counts
                     unread CONVERSATIONS, not individual messages, so a
                     long back-and-forth shows up as one. -->
                <?php if (module_active('messaging')): ?>
                <div class="notif-bell">
                    <a href="/messages" style="font-size:1.2rem; text-decoration:none;" title="Messages">✉</a>
                    <?php
                    $msgSvc       = new \Modules\Messaging\Services\MessagingService();
                    $msgUnread    = $msgSvc->unreadConversationCount((int) $auth->id());
                    if ($msgUnread): ?>
                    <span class="notif-count"><?= $msgUnread ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- User menu -->
                <div class="user-menu">
                    <button class="user-menu-toggle" onclick="this.nextElementSibling.classList.toggle('open')">
                        <?php $u = $auth->user(); ?>
                        <?php if (!empty($u['avatar'])): ?>
                            <img src="<?= e($u['avatar']) ?>" alt="" class="avatar">
                        <?php else: ?>
                            <span class="avatar"><?= e(strtoupper(substr($u['first_name'] ?? 'U', 0, 1))) ?></span>
                        <?php endif; ?>
                        <span><?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?></span>
                        ▾
                    </button>
                    <div class="dropdown-menu">
                        <a href="/profile">My Profile</a>
                        <?php if (module_active('profile')): ?><a href="/profile/2fa">🛡️ Two-Factor Auth</a><?php endif; ?>
                        <?php if ((bool) setting('account_sessions_enabled', true)): ?>
                        <a href="/account/sessions">📱 Active Sessions</a>
                        <?php endif; ?>
                        <?php if (module_active('groups')): ?><a href="/groups">My Groups</a><?php endif; ?>
                        <div class="dropdown-divider"></div>

                        <?php /* Theme preference toggle. Three-state form
                                  posts to /profile/theme; the controller
                                  writes both DB + cookie and redirects
                                  back to the referrer. */ ?>
                        <form method="POST" action="/profile/theme"
                              style="padding:.4rem .75rem;display:flex;flex-direction:column;gap:.35rem">
                            <?= csrf_field() ?>
                            <span style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                                Theme
                            </span>
                            <div style="display:flex;gap:.25rem;font-size:12.5px">
                                <?php foreach (['system' => '🖥 Auto', 'light' => '☀ Light', 'dark' => '🌙 Dark'] as $__themeOpt => $__themeLbl): ?>
                                <label style="flex:1;display:flex;align-items:center;justify-content:center;gap:.25rem;padding:.3rem .35rem;border:1px solid var(--border-default);border-radius:6px;cursor:pointer;<?= $__themePref === $__themeOpt ? 'background:var(--accent-subtle);border-color:var(--color-primary);color:var(--color-primary);font-weight:600' : '' ?>">
                                    <input type="radio" name="theme_preference"
                                           value="<?= e($__themeOpt) ?>"
                                           <?= $__themePref === $__themeOpt ? 'checked' : '' ?>
                                           onchange="this.form.submit()"
                                           style="display:none">
                                    <?= $__themeLbl ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </form>
                        <div class="dropdown-divider"></div>

                        <form method="POST" action="/logout"><?= csrf_field() ?>
                            <button type="submit">Sign Out</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <main class="content" id="main-content">
            <?php foreach (['success','error','warning','info'] as $flashType): ?>
                <?php $msg = \Core\Session::flash($flashType); ?>
                <?php if ($msg): ?>
                <div class="alert alert-<?= $flashType ?>">
                    <?= e($msg) ?>
                </div>
                <?php endif; ?>
            <?php endforeach; unset($flashType, $msg); ?>
