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
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 50;
    height: var(--site-footer-height);
    box-sizing: border-box;
    background: var(--chrome-footer-bg);
    color: var(--chrome-footer-text);
    padding: 0 1rem;
    font-size: 13px;
    line-height: 1.35;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem 1.25rem;
    box-shadow: 0 -1px 0 rgba(0,0,0,.15);
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
