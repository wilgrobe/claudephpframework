<?php
/**
 * Shared left-nav for the settings hub. Every panel view includes this
 * partial via:
 *
 *   <?php $activePanel = 'general'; include __DIR__ . '/_nav.php'; ?>
 *
 * Sets up the 2-column shell (nav + main) — panel views render their
 * form inside `<main class="settings-main">` which closes at the end
 * of the panel view (just before the layout/footer include).
 *
 * Adding a new panel: add a row to $panels here AND add a controller
 * method + route + view. Each entry is
 *   [key, label, icon, description].
 *
 * The icon column is a single emoji — keeps the nav lightweight without
 * pulling an icon font.
 */
$panels = [
    ['general',      'General',                '⚙️',  'Site identity, locale, maintenance'],
    ['layout',       'Layout',                 '📐',  'Header, sidebar, footer, menus'],
    ['appearance',   'Appearance',             '🎨',  'Colors, fonts, theme tokens'],
    ['members',      'Members',                '👥',  'Registration, COPPA, group policy'],
    ['security',     'Security',               '🔒',  'Sessions, 2FA, breach checks'],
    ['privacy',      'Privacy & Compliance',   '🛡️',  'Cookie consent, CCPA, GDPR, retention'],
    ['content',      'Content',                '📝',  'Comments, reviews, posts, polls, forms'],
    ['commerce',     'Commerce',               '🛒',  'Store features, currency, payments'],
    ['integrations', 'Integrations',           '🔌',  'Mail, analytics, Sentry, modules'],
    ['other',        'Other / Unmanaged',      '🗂️',  'Free-form ad-hoc keys'],
];
$activePanel = $activePanel ?? '';
?>
<style>
.settings-shell {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 1.5rem;
    align-items: flex-start;
    /* Full width — no max-width cap. Long key/value rows on the Other
       panel and wide forms on Layout get all the horizontal space the
       viewport offers. */
}
.settings-nav {
    position: sticky;
    top: 1rem;
    background: var(--bg-panel);
    border: 1px solid var(--border-default);
    border-radius: 8px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.settings-nav-header {
    padding: .85rem 1rem;
    border-bottom: 1px solid var(--border-default);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
}
.settings-nav a {
    display: flex;
    align-items: flex-start;
    gap: .55rem;
    padding: .65rem .85rem;
    color: var(--color-gray-700);
    text-decoration: none;
    font-size: 13.5px;
    border-bottom: 1px solid var(--border-subtle);
    transition: background .12s, color .12s;
}
.settings-nav a:last-child { border-bottom: 0; }
.settings-nav a:hover { background: var(--bg-page); }
.settings-nav a.is-active {
    background: var(--accent-subtle);
    color: var(--color-primary);
    font-weight: 600;
    border-left: 3px solid var(--color-primary);
    padding-left: calc(.85rem - 3px);
}
.settings-nav-icon { font-size: 1rem; line-height: 1.2; flex-shrink: 0; }
.settings-nav-text { min-width: 0; }
.settings-nav-text small {
    display: block;
    font-weight: 400;
    color: var(--text-muted);
    font-size: 11.5px;
    margin-top: .15rem;
    line-height: 1.4;
}
.settings-main {
    min-width: 0; /* prevents grid blow-out on long form rows */
}
@media (max-width: 768px) {
    .settings-shell { grid-template-columns: 1fr; }
    .settings-nav { position: static; }
}
</style>

<div class="settings-shell">
    <aside class="settings-nav" aria-label="Settings panels">
        <div class="settings-nav-header">Settings</div>
        <?php foreach ($panels as [$key, $label, $icon, $desc]): ?>
        <a href="/admin/settings/<?= e($key) ?>"
           class="<?= $key === $activePanel ? 'is-active' : '' ?>">
            <span class="settings-nav-icon" aria-hidden="true"><?= $icon ?></span>
            <span class="settings-nav-text">
                <?= e($label) ?>
                <small><?= e($desc) ?></small>
            </span>
        </a>
        <?php endforeach; ?>
    </aside>
    <main class="settings-main">
