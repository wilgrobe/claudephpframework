<?php
// app/Views/partials/page_composer.php
//
// Renders a layout + its placements as a stack of styled rows. Each row
// owns its own column grid; rows are separated so per-row backgrounds
// + full-bleed extension can apply (stuff that's impossible if every
// row shares one big CSS Grid).
//
// Required vars in scope:
//   $composer = ['layout' => [...], 'placements' => [...]]
//                shaped like Core\Services\PageLayoutService::getForPage()
//
// Optional vars:
//   $composerContext = array passed to each block's render closure
//                      (page row, viewer, etc). Defaults to []
//
//   $composerSlots   = ['primary' => '...html...', 'sidebar' => '...']
//                      Maps slot_name → pre-rendered HTML. Used when a
//                      placement's placement_type === 'content_slot'.
//                      Slots referenced by the layout but missing from
//                      this map render as empty (admins removed it from
//                      the layout — that's their choice). Slots passed
//                      in but not referenced by the layout are silently
//                      dropped. Defaults to []. See page-chrome plan
//                      §"Rendering changes".
//
// The page's parent template owns <html>, <head>, <body>; this partial
// emits a single <div class="page-composer-root"> with all the grid
// CSS scoped via custom properties.
//
// Responsive behaviour:
//   - Outer wrapper: full width. Each row's inner content is constrained
//     to layout.max_width_px and centered, UNLESS the row is full-bleed
//     (background + content extends to viewport edges).
//   - Each row is its own CSS Grid with cols sized from col_widths.
//   - Below 720px every row collapses to a single column.
//
// Style application:
//   - row_styles[r]   → background, full_bleed, content_padding_px
//   - cell_styles[r-c]→ background, padding
//   - placement.style → wrapper background + padding around the block
//   All values arrive pre-sanitised by PageLayoutService::sanitiseRowStyle
//   and ::sanitiseCellStyle so direct interpolation into inline `style`
//   is safe (no javascript:/expression()/url() chicanery survives).
?>
<?php
    $__cmp = $composer ?? null;
    if (!is_array($__cmp) || empty($__cmp['layout'])) return;

    $__layout      = $__cmp['layout'];
    $__placements  = $__cmp['placements'] ?? [];
    $__ctx         = $composerContext ?? [];
    $__slots       = isset($composerSlots) && is_array($composerSlots) ? $composerSlots : [];

    $__rows        = (int) ($__layout['rows']  ?? 1);
    $__cols        = (int) ($__layout['cols']  ?? 1);
    $__colWidths   = $__layout['col_widths']  ?: [];
    $__rowHeights  = $__layout['row_heights'] ?: [];
    $__gap         = (int) ($__layout['gap_pct'] ?? 3);
    $__maxWidth    = (int) ($__layout['max_width_px'] ?? 1280);
    $__rowStyles   = is_array($__layout['row_styles']  ?? null) ? $__layout['row_styles']  : [];
    $__cellStyles  = is_array($__layout['cell_styles'] ?? null) ? $__layout['cell_styles'] : [];

    // Build CSS grid-template-columns once — same across every row.
    $__colTrack = implode(' ', array_map(fn($p) => $p . 'fr',
        array_pad($__colWidths, $__cols, max(1, intdiv(95, max(1, $__cols))))));

    // Group placements into a row/col map: $cells[row][col] = [...placements].
    $__cells = [];
    foreach ($__placements as $__p) {
        $r = (int) $__p['row_index'];
        $c = (int) $__p['col_index'];
        $__cells[$r][$c][] = $__p;
    }

    $__viewerIsAuth = !empty($__ctx['viewer']);

    /** @var \Core\Module\BlockRegistry|null $__registry */
    $__registry = null;
    try {
        $__registry = \Core\Container\Container::global()->get(\Core\Module\BlockRegistry::class);
    } catch (\Throwable) {
        // Container or registry not bound (test context). Render an
        // empty grid rather than crashing.
    }

    /**
     * Convert a sanitised style array into an inline `style` attribute.
     * $extra is an associative array of additional properties to mix in
     * (e.g. min-height for cells). Returns the full attribute string
     * including style="..." or '' when there's nothing to emit.
     */
    $__styleAttr = function (array $style, array $extra = []) {
        $css = $extra;
        if (!empty($style['bg_color']))   $css['background-color'] = $style['bg_color'];
        if (!empty($style['bg_image']))   {
            $css['background-image']    = 'url("' . $style['bg_image'] . '")';
            $css['background-size']     = 'cover';
            $css['background-position'] = 'center';
        }
        if (!empty($style['text_color'])) $css['color']         = $style['text_color'];
        if (!empty($style['padding_px'])) $css['padding']       = (int) $style['padding_px'] . 'px';
        if (!empty($style['radius_px']))  $css['border-radius'] = (int) $style['radius_px'] . 'px';

        if (empty($css)) return '';
        $parts = [];
        foreach ($css as $k => $v) $parts[] = $k . ':' . $v;
        return ' style="' . htmlspecialchars(implode(';', $parts), ENT_QUOTES | ENT_HTML5) . '"';
    };
?>
<style>
    /* Scoped to .page-composer-root so a host template's existing styles
       don't accidentally leak into the grid layout. */
    .page-composer-root {
        --pc-max-width: <?= (int) $__maxWidth ?>px;
        --pc-gap: <?= max(0, $__gap) ?>%;
        --pc-side-pad: 24px;
        width: 100%;
    }
    /* Default (non-full-bleed) row: an inner wrapper enforces max-width
       and side padding so content stays centered. The row itself is
       full-width so its background can paint edge-to-edge regardless of
       full_bleed — distinction is whether the *content* respects the
       max-width. */
    .pc-row { width: 100%; }
    .pc-row-inner {
        max-width: var(--pc-max-width);
        margin: 0 auto;
        padding: 1rem var(--pc-side-pad);
        box-sizing: border-box;
    }
    .pc-row.full-bleed > .pc-row-inner {
        max-width: none;
    }
    .pc-row-grid {
        display: grid;
        grid-template-columns: <?= $__colTrack ?>;
        gap: var(--pc-gap);
    }
    .page-composer-cell {
        display: flex;
        flex-direction: column;
        gap: .5rem;
        min-width: 0;
    }
    @media (max-width: 1024px) {
        .page-composer-root { --pc-side-pad: 16px; }
    }
    @media (max-width: 720px) {
        .page-composer-root { --pc-side-pad: 8px; }
        .pc-row-grid { grid-template-columns: 1fr !important; }
    }
    .page-composer-missing {
        background: #fef3c7; color: #92400e;
        border: 1px dashed #fcd34d; padding: .6rem .85rem;
        border-radius: 6px; font-size: 12.5px;
    }
</style>

<div class="page-composer-root">
<?php for ($r = 0; $r < $__rows; $r++):
    $__rs       = isset($__rowStyles[$r]) && is_array($__rowStyles[$r]) ? $__rowStyles[$r] : [];
    $__fullBleed= !empty($__rs['full_bleed']);
    $__rowAttr  = $__styleAttr($__rs);

    // Per-row content padding override — drops onto the inner wrapper.
    $__innerExtra = [];
    if (!empty($__rs['content_padding_px'])) {
        $__innerExtra['padding'] = '1rem ' . (int) $__rs['content_padding_px'] . 'px';
    }
    $__innerAttr = $__innerExtra
        ? ' style="' . htmlspecialchars(implode(';', array_map(fn($k, $v) => "$k:$v", array_keys($__innerExtra), $__innerExtra)), ENT_QUOTES | ENT_HTML5) . '"'
        : '';

    // Row min-height from row_heights (px floor). Applied to the grid,
    // not the row, so backgrounds don't get an unwanted minimum-height
    // band when the row is empty.
    $__rowMinH = isset($__rowHeights[$r]) ? (int) $__rowHeights[$r] : 0;
    $__gridStyle = $__rowMinH > 0 ? ' style="min-height:' . $__rowMinH . 'px"' : '';
?>
    <div class="pc-row<?= $__fullBleed ? ' full-bleed' : '' ?>"<?= $__rowAttr ?>>
        <div class="pc-row-inner"<?= $__innerAttr ?>>
            <div class="pc-row-grid"<?= $__gridStyle ?> role="presentation">
            <?php for ($c = 0; $c < $__cols; $c++):
                $__cs   = $__cellStyles[$r . '-' . $c] ?? [];
                if (!is_array($__cs)) $__cs = [];
                $__cellAttr = $__styleAttr($__cs);
            ?>
                <div class="page-composer-cell"<?= $__cellAttr ?> data-cell="<?= $r ?>-<?= $c ?>">
                <?php foreach (($__cells[$r][$c] ?? []) as $__p):
                    $__type = (string) ($__p['placement_type'] ?? 'block');

                    if ($__type === 'content_slot') {
                        // Content-slot placement: emit the controller-rendered
                        // HTML for the named slot. visible_to is intentionally
                        // ignored — the controller owns auth gating and we
                        // must never silently hide the page's main content.
                        $__slotKey = (string) ($__p['slot_name'] ?? '');
                        if ($__slotKey === '') $__slotKey = 'primary';
                        $html = $__slots[$__slotKey] ?? '';
                        if ($html === '') continue;
                    } else {
                        if ($__p['visible_to'] === 'auth'  && !$__viewerIsAuth) continue;
                        if ($__p['visible_to'] === 'guest' &&  $__viewerIsAuth) continue;

                        $key = (string) $__p['block_key'];
                        if ($__registry === null) {
                            echo '<div class="page-composer-missing">Block registry unavailable.</div>';
                            continue;
                        }
                        $html = $__registry->render($key, $__ctx, $__p['settings'] ?? []);
                        if ($html === null) {
                            if ($__viewerIsAuth) {
                                echo '<div class="page-composer-missing">Block <code>' . htmlspecialchars($key, ENT_QUOTES | ENT_HTML5) . '</code> is unavailable. Edit this page to remove or replace.</div>';
                            }
                            continue;
                        }
                        if ($html === '') continue;
                    }

                    // Per-placement wrapper styling — only emitted when
                    // the placement carries a non-empty `style` array.
                    $__pStyle = $__p['style'] ?? [];
                    if (is_string($__pStyle)) {
                        $__decoded = json_decode($__pStyle, true);
                        $__pStyle  = is_array($__decoded) ? $__decoded : [];
                    }
                    if (!empty($__pStyle)) {
                        echo '<div class="page-composer-block-wrap"' . $__styleAttr($__pStyle) . '>'
                           . $html . '</div>';
                    } else {
                        echo $html;
                    }
                endforeach; ?>
                </div>
            <?php endfor; ?>
            </div>
        </div>
    </div>
<?php endfor; ?>
</div>
