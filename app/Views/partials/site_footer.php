<?php
/**
 * Site footer partial.
 *
 * Reads from the site-scope settings:
 *   footer_enabled        (boolean) — master on/off; renders nothing when off
 *   footer_logo_text      (string)  — text/emoji logo on the left
 *   footer_tagline        (string)  — short blurb next to the logo
 *   footer_show_menu      (boolean) — whether to render the menu
 *   footer_menu_location  (string)  — which menu location to pull (default: 'footer')
 *   footer_copyright      (string)  — supports {{year}} substitution
 *   footer_powered_by     (string)  — "Powered by ..." line
 *
 * Layout: a single-row bar pinned to the bottom of the viewport. The
 * left side carries the inline text items (logo, tagline, copyright,
 * powered-by, each separated by a centered dot). The right side carries
 * the menu as a horizontal row of links.
 *
 * Because the footer is position:fixed, a padding-bottom reservation is
 * emitted on <body> so long pages can scroll to their real end without
 * the footer overlapping the last few lines of content.
 *
 * On narrow screens, the bar is allowed to wrap rather than crush items
 * — it'll become a bit taller on mobile, and the body padding reservation
 * is bumped to match.
 *
 * Admins configure these via /admin/settings/footer. Both the authenticated
 * layout (app/Views/layout/footer.php) and the guest-facing page view
 * (app/Views/public/page.php) pull this partial so the footers stay
 * consistent across contexts.
 */

if (!setting('footer_enabled', true)) {
    return;
}

$__f_logo      = (string) setting('footer_logo_text', '');
$__f_tagline   = (string) setting('footer_tagline', '');
$__f_showMenu  = (bool)   setting('footer_show_menu', true);
$__f_menuLoc   = (string) setting('footer_menu_location', 'footer') ?: 'footer';
$__f_copyright = (string) setting('footer_copyright', '');
$__f_powered   = (string) setting('footer_powered_by', '');

// Phase 43.156 + 43.159 — wizard chrome footer overrides. The
// wizard's builder.layout.footer.* keys take precedence over the
// framework's footer_* keys when set, mapping:
//   show_copyright=0     → wipe $__f_copyright
//   copyright_text=...   → replace $__f_copyright (with {YEAR}/{SITE_NAME} subs)
//   show_powered_by=0    → wipe $__f_powered
//   show_social_icons=1  → emit the social-icon row (rendered below)
//   columns≥2            → switch to grid mode (43.159); single-bar
//                          fixed-bottom layout off, normal-flow
//                          multi-column footer on
$__f_socialOn  = false;
$__f_columns   = 1;
// Roam arc — sub-footer row (thin secondary strip in columns mode).
$__f_subEnabled = false;
$__f_subText    = '';
$__f_subMenuLoc = 'subfooter';
if (class_exists(\App\Services\SiteChromeService::class)) {
    $__f_chrome = \App\Services\SiteChromeService::loadFooterLayoutForRender();
    if (($__f_chrome['show_copyright'] ?? '1') === '0') {
        $__f_copyright = '';
    } elseif (!empty($__f_chrome['copyright_text']) && $__f_chrome['copyright_text'] !== \App\Services\SiteChromeService::FOOTER_DEFAULTS['copyright_text']) {
        // Wizard supplied a custom template; substitute {YEAR} + {SITE_NAME}.
        $__f_copyright = \App\Services\SiteChromeService::renderCopyright(
            (string) $__f_chrome['copyright_text'],
            (string) setting('site_name', '')
        );
    }
    if (($__f_chrome['show_powered_by'] ?? '1') === '0') {
        $__f_powered = '';
    }
    $__f_socialOn = ($__f_chrome['show_social_icons'] ?? '0') === '1';
    // Phase 43.159 — columns count drives the grid-mode branch below.
    $__f_columns = max(1, min(5, (int) ($__f_chrome['columns'] ?? 1)));
    // Roam arc — sub-footer row.
    $__f_subEnabled = ($__f_chrome['subfooter_enabled'] ?? '0') === '1';
    $__f_subText    = (string) ($__f_chrome['subfooter_text'] ?? '');
    if ($__f_subText !== '') {
        $__f_subText = \App\Services\SiteChromeService::renderCopyright($__f_subText, (string) setting('site_name', ''));
    }
    $__f_subMenuLoc = (string) ($__f_chrome['subfooter_menu_location'] ?? 'subfooter') ?: 'subfooter';
}

// {{year}} -> current year. Stored as a template rather than the raw year
// so it ticks over automatically on Jan 1 without anyone editing the setting.
$__f_copyright = str_replace('{{year}}', date('Y'), $__f_copyright);

// Build the left-side text items, dropping any that are empty so we don't
// render stray separators with nothing between them.
$__f_leftItems = array_values(array_filter(
    [$__f_logo, $__f_tagline, $__f_copyright, $__f_powered],
    fn($s) => trim((string)$s) !== ''
));

$__f_menuItems = [];
if ($__f_showMenu) {
    $__f_menuItems = menu($__f_menuLoc);
}

// ────────────────────────────────────────────────────────────────────
// Phase 43.159 — columns-mode branch. When the wizard configured 2+
// columns, render a normal-flow multi-column footer instead of the
// fixed-bottom single bar. Logo/tagline/copyright/powered-by stack
// in column 1; menu items split evenly across columns 2..N. Social
// icons (when on) render in their own row spanning all columns.
// ────────────────────────────────────────────────────────────────────
if ($__f_columns >= 2) {
    // Split menu items across the menu columns. Item count divides as
    // evenly as possible (chunks of ceil(N/colsForMenu) so the leftmost
    // menu column carries the overflow when not perfectly divisible).
    $__f_menuColCount = max(1, $__f_columns - 1);
    $__f_menuChunks = [];
    if (!empty($__f_menuItems)) {
        $__f_chunkSize = (int) ceil(count($__f_menuItems) / $__f_menuColCount);
        $__f_menuChunks = array_chunk($__f_menuItems, max(1, $__f_chunkSize));
    }

    // CCPA link tacks onto the last menu chunk so it appears with the
    // legal-ish items rather than orphaned in its own column.
    $__f_ccpaEnabled = class_exists(\Modules\Ccpa\Services\CcpaService::class)
                    && (bool) setting('ccpa_enabled', true);
    $__f_ccpaLabel   = (string) setting('ccpa_link_label', 'Do Not Sell or Share My Personal Information');
    $__f_ccpaUrl     = (string) setting('ccpa_disclosure_url', '/do-not-sell');
?>
<footer class="site-footer site-footer--cols cols-<?= (int) $__f_columns ?>" role="contentinfo">
    <div class="site-footer__col site-footer__col--identity">
        <?php if ($__f_logo !== ''): ?><div class="site-footer__brand"><?= e($__f_logo) ?></div><?php endif; ?>
        <?php if ($__f_tagline !== ''): ?><div class="site-footer__tagline"><?= e($__f_tagline) ?></div><?php endif; ?>
        <?php if ($__f_copyright !== ''): ?><div class="site-footer__copy"><?= e($__f_copyright) ?></div><?php endif; ?>
        <?php if ($__f_powered !== ''): ?><div class="site-footer__powered"><?= e($__f_powered) ?></div><?php endif; ?>
    </div>
    <?php for ($__f_ci = 0; $__f_ci < $__f_menuColCount; $__f_ci++):
        $__f_chunk = $__f_menuChunks[$__f_ci] ?? [];
        $__f_isLast = ($__f_ci === $__f_menuColCount - 1);
    ?>
    <nav class="site-footer__col site-footer__col--menu" aria-label="Footer menu column <?= (int) $__f_ci + 1 ?>">
        <?php foreach ($__f_chunk as $__f_item): ?>
        <a class="site-footer__col-link" href="<?= e($__f_item['url'] ?? '#') ?>"><?= e($__f_item['label'] ?? '') ?></a>
        <?php endforeach; unset($__f_item); ?>
        <?php if ($__f_isLast && $__f_ccpaEnabled): ?>
        <a class="site-footer__col-link" href="<?= e($__f_ccpaUrl) ?>" rel="nofollow"><?= e($__f_ccpaLabel) ?></a>
        <?php endif; ?>
    </nav>
    <?php endfor; ?>

    <?php if ($__f_socialOn): ?>
    <div class="site-footer__social-row" aria-label="Social links">
        <?php
        $__f_socials = [
            ['key' => 'twitter',  'icon' => '𝕏', 'label' => 'Twitter / X'],
            ['key' => 'github',   'icon' => '⧫', 'label' => 'GitHub'],
            ['key' => 'linkedin', 'icon' => 'in', 'label' => 'LinkedIn'],
            ['key' => 'facebook', 'icon' => 'f',  'label' => 'Facebook'],
            ['key' => 'instagram','icon' => '◉',  'label' => 'Instagram'],
        ];
        foreach ($__f_socials as $__f_s):
            $__f_sUrl = (string) setting('social_' . $__f_s['key'] . '_url', '');
            if ($__f_sUrl === '') continue;
        ?>
            <a href="<?= e($__f_sUrl) ?>" rel="noopener" target="_blank" aria-label="<?= e($__f_s['label']) ?>" title="<?= e($__f_s['label']) ?>"><?= e($__f_s['icon']) ?></a>
        <?php endforeach; unset($__f_s, $__f_sUrl); ?>
    </div>
    <?php endif; ?>

    <?php
    // Roam arc — sub-footer row: a thin secondary strip spanning all columns
    // (legal/policy links + a muted secondary line). Allbirds/Ghost-style.
    $__f_subItems = $__f_subEnabled ? menu($__f_subMenuLoc) : [];
    if ($__f_subEnabled && ($__f_subText !== '' || !empty($__f_subItems))):
    ?>
    <div class="site-footer__subfooter">
        <?php if ($__f_subText !== ''): ?><div class="site-footer__sub-text"><?= e($__f_subText) ?></div><?php endif; ?>
        <?php if (!empty($__f_subItems)): ?>
        <nav class="site-footer__sub-links" aria-label="Legal">
            <?php foreach ($__f_subItems as $__f_subItem): ?>
            <a href="<?= e($__f_subItem['url'] ?? '#') ?>"><?= e($__f_subItem['label'] ?? '') ?></a>
            <?php endforeach; unset($__f_subItem); ?>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</footer>

<style>
/* Phase 43.159 — columns-mode footer. Normal-flow (NOT fixed-bottom)
   so it can be as tall as needed. Body padding-bottom + sidebar
   height-clamp from the single-bar mode are deliberately omitted —
   normal-flow footer doesn't need either. */
.site-footer--cols {
    display: grid;
    grid-template-columns: repeat(<?= (int) $__f_columns ?>, 1fr);
    gap: 2rem;
    padding: 2rem 1.5rem 1.5rem;
    background: var(--chrome-footer-bg);
    color: var(--chrome-footer-text);
    box-shadow: 0 calc(-1 * var(--style-border-card-width, 1px)) 0 rgba(0,0,0,.15);
    box-sizing: border-box;
}
.site-footer--cols .site-footer__col {
    display: flex;
    flex-direction: column;
    gap: .35rem;
    min-width: 0;
}
.site-footer--cols .site-footer__col--identity .site-footer__brand {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--chrome-footer-text);
    filter: brightness(1.15);
    margin-bottom: .35rem;
}
.site-footer--cols .site-footer__tagline,
.site-footer--cols .site-footer__copy,
.site-footer--cols .site-footer__powered {
    font-size: 13px;
    opacity: .85;
}
.site-footer--cols .site-footer__col--menu { gap: .25rem; }
.site-footer--cols .site-footer__col-link {
    color: var(--chrome-footer-text);
    text-decoration: none;
    font-size: 13.5px;
    padding: .25rem 0;
    border-radius: 3px;
    opacity: .9;
}
.site-footer--cols .site-footer__col-link:hover {
    color: #fff;
    filter: brightness(1.2);
    text-decoration: underline;
}
.site-footer--cols .site-footer__social-row {
    grid-column: 1 / -1;
    display: flex;
    gap: .4rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255,255,255,.1);
}
.site-footer--cols .site-footer__social-row a {
    color: var(--chrome-footer-text);
    text-decoration: none;
    font-size: 14px;
    padding: .25rem .55rem;
    border-radius: 4px;
    line-height: 1;
    font-weight: 600;
    opacity: .8;
}
.site-footer--cols .site-footer__social-row a:hover {
    opacity: 1;
    background: rgba(255,255,255,.08);
}
/* Roam arc — sub-footer row. Thin muted strip spanning all columns, split
   between a secondary text line (left) and a legal-links row (right). */
.site-footer--cols .site-footer__subfooter {
    grid-column: 1 / -1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .4rem 1.25rem;
    margin-top: .5rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255,255,255,.12);
    font-size: 12px;
    opacity: .72;
}
.site-footer--cols .site-footer__sub-text { white-space: normal; }
.site-footer--cols .site-footer__sub-links { display: flex; flex-wrap: wrap; gap: .15rem .9rem; }
.site-footer--cols .site-footer__sub-links a {
    color: var(--chrome-footer-text);
    text-decoration: none;
    font-size: 12px;
}
.site-footer--cols .site-footer__sub-links a:hover { text-decoration: underline; filter: brightness(1.2); }

/* On narrow screens, collapse to a single column. */
@media (max-width: 720px) {
    .site-footer--cols { grid-template-columns: 1fr; gap: 1.25rem; padding: 1.5rem 1rem 1rem; }
    .site-footer--cols .site-footer__subfooter { justify-content: flex-start; }
}
</style>
<?php
    return; // columns-mode footer fully rendered — skip the single-bar branch below
}
?>
<footer class="site-footer" role="contentinfo">
    <!-- LEFT: logo · tagline · copyright · powered-by, inline -->
    <?php if (!empty($__f_leftItems)): ?>
    <div class="site-footer__left">
        <?php foreach ($__f_leftItems as $__f_i => $__f_text): ?>
            <?php if ($__f_i > 0): ?><span class="site-footer__sep" aria-hidden="true">·</span><?php endif; ?>
            <span class="site-footer__item"><?= e($__f_text) ?></span>
        <?php endforeach; unset($__f_i, $__f_text); ?>
    </div>
    <?php endif; ?>

    <!-- RIGHT: horizontal menu -->
    <?php
    // CCPA "Do Not Sell or Share" footer link — auto-injected when the
    // ccpa module is installed AND the master toggle is on. Rendered
    // alongside the regular menu items so it appears in the same line.
    $__f_ccpaEnabled = class_exists(\Modules\Ccpa\Services\CcpaService::class)
                    && (bool) setting('ccpa_enabled', true);
    $__f_ccpaLabel   = (string) setting('ccpa_link_label', 'Do Not Sell or Share My Personal Information');
    $__f_ccpaUrl     = (string) setting('ccpa_disclosure_url', '/do-not-sell');
    ?>
    <?php if (!empty($__f_menuItems) || $__f_ccpaEnabled): ?>
    <nav class="site-footer__menu" aria-label="Footer menu">
        <?php if (!empty($__f_menuItems)): foreach ($__f_menuItems as $__f_item): ?>
        <a href="<?= e($__f_item['url'] ?? '#') ?>"><?= e($__f_item['label'] ?? '') ?></a>
        <?php endforeach; unset($__f_item); endif; ?>
        <?php if ($__f_ccpaEnabled): ?>
        <a href="<?= e($__f_ccpaUrl) ?>" rel="nofollow"><?= e($__f_ccpaLabel) ?></a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if ($__f_socialOn): ?>
    <!-- Phase 43.156 — social icons row. URLs not yet wired into the
         wizard's footer modal; reads from existing settings keys when
         present (site admin sets via /admin/settings/footer or similar).
         Empty URLs render as static placeholders for visual completeness. -->
    <div class="site-footer__social" aria-label="Social links">
        <?php
        $__f_socials = [
            ['key' => 'twitter',  'icon' => '𝕏', 'label' => 'Twitter / X'],
            ['key' => 'github',   'icon' => '⧫', 'label' => 'GitHub'],
            ['key' => 'linkedin', 'icon' => 'in', 'label' => 'LinkedIn'],
            ['key' => 'facebook', 'icon' => 'f',  'label' => 'Facebook'],
            ['key' => 'instagram','icon' => '◉',  'label' => 'Instagram'],
        ];
        foreach ($__f_socials as $__f_s):
            $__f_sUrl = (string) setting('social_' . $__f_s['key'] . '_url', '');
            if ($__f_sUrl === '') continue; // skip unset networks; toggle ALONE doesn't render placeholders
        ?>
            <a href="<?= e($__f_sUrl) ?>" rel="noopener" target="_blank" aria-label="<?= e($__f_s['label']) ?>" title="<?= e($__f_s['label']) ?>"><?= e($__f_s['icon']) ?></a>
        <?php endforeach; unset($__f_s, $__f_sUrl); ?>
    </div>
    <?php endif; ?>
</footer>

<style>
/* Scoped via .site-footer so including the partial twice on one page
   (unlikely but possible) just duplicates identical rules rather than
   conflicting with anything else.

   The footer's height is declared once as a custom property and reused
   for the body's padding reservation AND the sidebar's height clamp.
   If those three values ever drift apart, a gap opens up below a sticky
   sidebar or content hides behind the footer — so they share one source. */
:root { --site-footer-height: 2.4rem; }

.site-footer {
    /* Phase 43.14 — footer chrome border + horizontal padding + gap
       tie to the --style-* page-style tokens so the chrome rhythm
       tracks the design system. */
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 50;
    height: var(--site-footer-height);
    box-sizing: border-box;
    background: var(--chrome-footer-bg);
    color: var(--chrome-footer-text);
    padding: 0 var(--style-spacing-section-padding, 1rem);
    font-size: 13px;
    line-height: 1.35;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: calc(var(--style-spacing-element-gap, 12px) * 0.6) var(--style-spacing-element-gap, 1.25rem);
    box-shadow: 0 calc(-1 * var(--style-border-card-width, 1px)) 0 rgba(0,0,0,.15);
    overflow: hidden; /* don't let wrapped content bleed past the fixed height */
}
.site-footer__left {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .15rem .5rem;
    min-width: 0;
}
.site-footer__item      { color: var(--chrome-footer-text); white-space: nowrap; }
.site-footer__item:first-child { font-weight: 600; color: var(--chrome-footer-text); filter: brightness(1.15); }
.site-footer__sep       { color: var(--chrome-footer-text); opacity: .45; }
.site-footer__menu      { display: flex; flex-wrap: nowrap; gap: .15rem .25rem; align-items: center; }
.site-footer__menu a    { color: var(--chrome-footer-text); text-decoration: none; padding: .15rem .5rem; border-radius: 4px; font-size: 13px; white-space: nowrap; }
.site-footer__menu a:hover { color: #fff; background: rgba(255,255,255,.08); filter: brightness(1.15); }
.site-footer__social    { display: flex; gap: .35rem; align-items: center; margin-left: .5rem; }
.site-footer__social a  { color: var(--chrome-footer-text); text-decoration: none; font-size: 13px; padding: .15rem .45rem; border-radius: 4px; line-height: 1; font-weight: 600; opacity: .8; }
.site-footer__social a:hover { opacity: 1; background: rgba(255,255,255,.08); }

/* Reserve exactly the footer's height at the bottom of the page so the
   fixed footer never overlaps the last line of content, and no leftover
   padding shows through between the sidebar and the footer. */
body { padding-bottom: var(--site-footer-height); }

/* On narrow screens the app's sidebar is already hidden (see header.php
   media query), so the sidebar-gap concern doesn't apply. Let the footer
   wrap instead of clipping content, and give the body enough padding to
   cover the worst-case two-line footer. */
@media (max-width: 640px) {
    .site-footer             { height: auto; min-height: var(--site-footer-height); padding: .35rem 1rem; flex-wrap: wrap; overflow: visible; }
    .site-footer__menu       { flex-wrap: wrap; }
    body                     { padding-bottom: 5rem; }
}

/* The app's sidebar uses position: sticky + height: 100vh. With a fixed
   footer pinned to the bottom, the sidebar's sticky logic can pull it up
   by the body's padding amount and expose body background between the
   sidebar's bottom and the footer's top. Clamping the sidebar's height
   to the viewport-minus-footer keeps its bottom exactly flush with the
   footer's top, eliminating that gap. Scoped selector so we only touch
   the app's own sidebar — this partial is imported from guest-facing
   pages that don't have one. */
.layout > .sidebar { height: calc(100vh - var(--site-footer-height)); }
</style>

