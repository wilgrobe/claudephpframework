<?php
// modules/pages/Views/admin/form.php
//
// Unified page-edit + composer view (2026-04-28 layout-editor UX pass).
//
// Top:    condensed page settings (title/slug always visible, the rest in
//         <details> drawers so the page settles short by default).
// Middle: layout grid config drawer.
// Bottom: WYSIWYG-ish composer — visual cell grid + block palette sidebar.
// Modal:  per-placement structured settings popup, opened on tile click.
//
// New pages (no $page yet) show only the top settings; the composer is
// disabled with a "save the page first" message because placements need
// a page id to attach to.

$pageTitle = $page ? 'Edit Page' : 'New Page';

// Build a JSON catalog of every available block keyed by block.key. The
// composer's JS reads this to populate the palette and render the
// per-block structured settings modal. We project only the fields the
// front-end needs — no closures.
$__blockCatalog = [];
foreach (($blocksByCategory ?? []) as $__cat => $__blocks) {
    foreach ($__blocks as $__b) {
        $__blockCatalog[$__b->key] = [
            'key'             => $__b->key,
            'label'           => $__b->label,
            'description'     => $__b->description,
            'category'        => $__b->category,
            'audience'        => $__b->audience,
            'defaultSize'     => $__b->defaultSize,
            'defaultSettings' => $__b->defaultSettings,
            'settingsSchema'  => $__b->settingsSchema,
        ];
    }
}

// Normalize server-side placements to the shape the JS state holds.
$__placements = [];
foreach (($placements ?? []) as $__p) {
    $__settings = $__p['settings'] ?? [];
    if (is_string($__settings)) {
        $__decoded = json_decode($__settings, true);
        $__settings = is_array($__decoded) ? $__decoded : [];
    }
    $__style = $__p['style'] ?? [];
    if (is_string($__style)) {
        $__decoded = json_decode($__style, true);
        $__style = is_array($__decoded) ? $__decoded : [];
    }
    $__placements[] = [
        'row'        => (int) ($__p['row_index']  ?? $__p['row'] ?? 0),
        'col'        => (int) ($__p['col_index']  ?? $__p['col'] ?? 0),
        'sort_order' => (int) ($__p['sort_order'] ?? 0),
        'block_key'  => (string) ($__p['block_key'] ?? ''),
        'visible_to' => (string) ($__p['visible_to'] ?? 'any'),
        'settings'   => $__settings,
        'style'      => is_array($__style) ? $__style : [],
    ];
}

// Row + cell styling state (added by the 2026-04-28 styling pass).
$__rowStyles  = is_array($layout['row_styles']  ?? null) ? $layout['row_styles']  : [];
$__cellStyles = is_array($layout['cell_styles'] ?? null) ? $layout['cell_styles'] : [];
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
/* Wider container just for this view; the framework's default content
   wrap is too narrow for the composer's two-pane layout. */
.page-edit-wrap { max-width: 1500px; margin: 0 auto; }

/* Top meta — condensed key/value rows, tight vertical rhythm. */
.meta-card        { margin-bottom: .85rem; }
.meta-grid        { display: grid; gap: .65rem .85rem;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
.meta-grid label  { display: block; font-size: 12px; font-weight: 600;
                    color: var(--text-muted); text-transform: uppercase;
                    letter-spacing: .04em; margin-bottom: .2rem; }
.meta-grid input, .meta-grid select { width: 100%; }

/* Collapsible drawers (SEO / body / grid / advanced).
   Hide the native triangle; render our own ▸/▾ instead. */
.meta-drawer { background: #fff; border: 1px solid var(--border-default); border-radius: 8px;
               margin-bottom: .65rem; overflow: hidden; }
.meta-drawer > summary {
    list-style: none; cursor: pointer; user-select: none;
    padding: .7rem 1rem; font-weight: 600; font-size: 13.5px; color: var(--text-default);
    display: flex; align-items: center; gap: .5rem;
}
.meta-drawer > summary::-webkit-details-marker { display: none; }
.meta-drawer > summary::before { content: '▸'; color: var(--text-muted); transition: transform .15s; }
.meta-drawer[open] > summary::before { transform: rotate(90deg); }
.meta-drawer > summary:hover { background: #f9fafb; }
.meta-drawer-body { padding: 0 1rem 1rem; border-top: 1px solid var(--border-subtle); }

/* Auto-growing body textarea — the JS resizes it on input. */
.body-grow { min-height: 100px; resize: vertical; font-family: var(--font); }

/* ── Composer ── */
.composer-pane {
    display: grid; gap: 1rem;
    grid-template-columns: 1fr 280px;
    margin-top: 1rem;
}
@media (max-width: 1100px) { .composer-pane { grid-template-columns: 1fr; } }

.composer-main { background: #fff; border: 1px solid var(--border-default); border-radius: 8px; padding: 1rem; min-height: 360px; }
.composer-palette { background: #fff; border: 1px solid var(--border-default); border-radius: 8px; padding: .65rem; max-height: 70vh; overflow-y: auto; position: sticky; top: 80px; align-self: start; }
.composer-palette h3 { margin: 0 0 .5rem; font-size: 13px; font-weight: 700; color: var(--text-default); }
.palette-cat { font-size: 10.5px; font-weight: 700; text-transform: uppercase;
               color: var(--text-muted); letter-spacing: .05em; margin: .65rem .25rem .25rem; }
.palette-tile {
    display: flex; flex-direction: column; align-items: flex-start;
    background: #f9fafb; border: 1px solid var(--border-subtle); border-radius: 6px;
    padding: .5rem .65rem; margin-bottom: .25rem; cursor: grab;
    font-size: 12.5px; transition: background .12s, border-color .12s;
}
.palette-tile:hover { background: var(--accent-subtle); border-color: #c7d2fe; }
.palette-tile:active { cursor: grabbing; }
.palette-tile-label { font-weight: 600; color: var(--text-default); }
.palette-tile-aud   { font-size: 10.5px; color: var(--text-muted); margin-top: .15rem; }

/* Composer grid visualization — row-by-row stack so each row can have
   its own background + full-bleed treatment AND its own controls strip
   on the left. Each .composer-row holds: a vertical button strip
   (.composer-row-rail) and a per-row CSS grid (.composer-row-grid). */
.composer-grid { display: flex; flex-direction: column;
                 gap: var(--composer-gap, 12px); width: 100%; }
.composer-row {
    display: flex; align-items: stretch; gap: .5rem;
    border-radius: 6px; transition: background .15s, padding .15s;
}
.composer-row-rail {
    flex-shrink: 0; width: 36px; display: flex; flex-direction: column;
    gap: .35rem; padding-top: .15rem; align-items: center;
}
.composer-row-rail button {
    width: 28px; height: 28px; padding: 0; cursor: pointer;
    background: #fff; border: 1px solid var(--border-default); border-radius: 6px;
    color: var(--text-muted); font-size: 14px; line-height: 1;
    transition: border-color .12s, color .12s, background .12s;
}
.composer-row-rail button:hover { border-color: var(--color-primary); color: var(--color-primary); background: var(--accent-subtle); }
.composer-row-rail-label { font-size: 9.5px; color: var(--text-subtle); font-weight: 700;
                           text-transform: uppercase; letter-spacing: .05em; }
.composer-row.is-styled    { /* visual marker: row carries non-default styling */
                             outline: 1px dashed rgba(79,70,229,.35); outline-offset: 4px; }
.composer-row.is-fullbleed { /* dotted ring + edge tag for full-bleed rows */
                             box-shadow: inset 0 0 0 1px #c7d2fe; }
.composer-row-grid {
    flex: 1; min-width: 0; display: grid; gap: var(--composer-gap, 12px);
}

/* Each cell still owns its own dashed border + drop affordance. */
.composer-cell {
    background: #f9fafb; border: 1.5px dashed var(--border-strong); border-radius: 6px;
    padding: .55rem .5rem .35rem; min-height: 80px;
    display: flex; flex-direction: column;
    transition: background .15s, border-color .15s;
}
.composer-cell.is-styled { border-style: solid; border-color: #c7d2fe; }
.composer-cell-toolbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: .35rem; gap: .25rem;
}
.composer-cell-toolbar button {
    background: none; border: none; cursor: pointer; color: var(--text-subtle);
    font-size: 13px; padding: 0 .15rem; line-height: 1;
}
.composer-cell-toolbar button:hover { color: var(--color-primary); }
.composer-cell.is-dragover { background: var(--accent-subtle); border-color: var(--color-primary); border-style: solid; }
.composer-cell-label { font-size: 10.5px; color: var(--text-subtle); margin-bottom: .35rem;
                       text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
.placement-tile {
    display: flex; align-items: center; justify-content: space-between;
    background: #fff; border: 1px solid var(--border-default); border-radius: 6px;
    padding: .4rem .5rem; margin-bottom: .25rem; font-size: 12.5px;
    cursor: pointer; transition: border-color .12s, box-shadow .12s;
}
.placement-tile:hover { border-color: var(--color-primary); box-shadow: 0 1px 4px rgba(79,70,229,.18); }
.placement-tile-label { font-weight: 500; color: var(--text-default);
                        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                        flex: 1; min-width: 0; }
.placement-tile-aud   { font-size: 10px; color: var(--text-muted); margin-left: .35rem;
                        text-transform: uppercase; letter-spacing: .03em; flex-shrink: 0; }
.placement-tile-x     { background: none; border: none; color: #dc2626; font-size: 14px;
                        cursor: pointer; padding: 0 .25rem; line-height: 1; flex-shrink: 0; }
.placement-tile-x:hover { color: #991b1b; }

/* Modal */
.cb-modal-backdrop {
    position: fixed; inset: 0; background: rgba(17,24,39,.5);
    display: none; align-items: flex-start; justify-content: center;
    z-index: 1000; padding: 4rem 1rem;
}
.cb-modal-backdrop.open { display: flex; }
.cb-modal {
    background: #fff; border-radius: 12px; box-shadow: 0 20px 50px -10px rgba(0,0,0,.3);
    width: 100%; max-width: 640px; max-height: 80vh; display: flex; flex-direction: column;
}
.cb-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-subtle);
                   display: flex; align-items: center; justify-content: space-between; }
.cb-modal-header h2 { margin: 0; font-size: 1rem; font-weight: 600; }
.cb-modal-header .close { background: none; border: none; font-size: 1.4rem; color: var(--text-muted); cursor: pointer; line-height: 1; }
.cb-modal-body   { padding: 1rem 1.25rem; overflow-y: auto; }
.cb-modal-footer { padding: .75rem 1.25rem; border-top: 1px solid var(--border-subtle); display: flex; gap: .5rem; justify-content: flex-end; background: #f9fafb; }
.cb-modal-help   { font-size: 12px; color: var(--text-muted); margin-top: .15rem; }
.cb-modal .form-group { margin-bottom: .85rem; }

/* Repeater fields — array-of-objects + string-list editors. Used by the
   marketing blocks (pricing plans, testimonials, feature grid, logos,
   stats) instead of the old raw-JSON textarea. Items can be added,
   removed, and reordered; nested repeaters are supported (a plan in a
   pricing table contains a string_list of features). */
.cb-rep-container {
    border: 1px solid var(--border-default); border-radius: 8px;
    padding: .55rem; background: #f9fafb;
}
.cb-rep-items { display: flex; flex-direction: column; gap: .5rem; margin-bottom: .55rem; counter-reset: rep-item; }
.cb-rep-items:empty { margin-bottom: 0; }
.cb-rep-items:empty::before {
    content: 'No items yet. Click "+ Add" below.';
    display: block; padding: .75rem .35rem; color: var(--text-subtle);
    font-size: 12.5px; font-style: italic; text-align: center;
}
.cb-rep-item {
    background: #fff; border: 1px solid var(--border-default); border-radius: 6px;
    counter-increment: rep-item;
}
.cb-rep-item-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: .35rem .6rem; background: var(--border-subtle);
    border-bottom: 1px solid var(--border-default); border-radius: 6px 6px 0 0;
}
.cb-rep-item-title {
    font-size: 11.5px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .05em;
}
.cb-rep-item-title::after { content: ' #' counter(rep-item); color: var(--text-subtle); font-weight: 500; }
.cb-rep-item-actions { display: flex; gap: .25rem; }
.cb-rep-item-actions button {
    background: #fff; border: 1px solid var(--border-strong); border-radius: 4px;
    padding: .15rem .4rem; font-size: 11.5px; line-height: 1; cursor: pointer;
    color: var(--text-muted); min-width: 24px;
}
.cb-rep-item-actions button:hover { color: var(--color-primary); border-color: var(--color-primary); }
.cb-rep-item-actions button[data-act="del"]:hover { color: #dc2626; border-color: #dc2626; }
.cb-rep-item-body { padding: .6rem; }
.cb-rep-item-body .form-group { margin-bottom: .55rem; }
.cb-rep-item-body .form-group:last-child { margin-bottom: 0; }
/* Nested repeaters get a slightly tighter look so the visual hierarchy stays clear. */
.cb-rep-item-body .cb-rep-container { background: #f9fafb; padding: .45rem; }
/* String-list items: single-line layout with the input flush to the actions. */
.cb-rep-item.cb-strlist-item {
    display: flex; align-items: center; gap: .35rem;
    padding: .3rem .45rem; background: #fff;
}
.cb-rep-item.cb-strlist-item input {
    flex: 1; padding: .3rem .55rem; font-size: 13px;
    border: 1px solid var(--border-strong); border-radius: 4px;
}
.cb-rep-item.cb-strlist-item input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(79,70,229,.15); }
.cb-rep-add { font-size: 12.5px; padding: .35rem .7rem; }
</style>

<div class="page-edit-wrap">
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:var(--text-muted)">
            <a href="/admin/pages" style="color:var(--color-primary);text-decoration:none">← All pages</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.5rem;font-weight:700">
            <?= $page ? 'Edit: ' . e($page['title']) : 'Create page' ?>
        </h1>
        <?php if ($page): ?>
        <div style="font-size:13px;color:var(--text-muted);margin-top:.25rem">
            URL: <code>/<?= e($page['slug']) ?></code>
            <?= $hasLayout ? ' · Composer enabled' : ' · No layout — page renders body content' ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($page && $hasLayout): ?>
    <form method="POST" action="/admin/pages/<?= (int) $page['id'] ?>/layout/delete"
          onsubmit="return confirm('Remove this layout and all its block placements? The page will revert to rendering its body content.')">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-danger">Remove layout</button>
    </form>
    <?php endif; ?>
</div>

<form method="POST" action="<?= $page ? '/admin/pages/'.(int)$page['id'].'/edit' : '/admin/pages/create' ?>" id="page-edit-form">
    <?= csrf_field() ?>
    <?php $errors = \Core\Session::flash('errors') ?? []; ?>

    <!-- ── Top meta: title/slug/status always visible ───────────────── -->
    <div class="card meta-card">
        <div class="card-body" style="padding:.85rem 1rem">
            <div class="meta-grid">
                <div>
                    <label for="title">Title *</label>
                    <input type="text" name="title" class="form-control <?= !empty($errors['title'])?'is-invalid':''?>"
                           value="<?= e($page['title'] ?? old('title')) ?>" required oninput="autoSlugPage(this)" id="title">
                    <?php if (!empty($errors['title'])): ?><span class="form-error"><?= e($errors['title'][0]) ?></span><?php endif; ?>
                </div>
                <div>
                    <label for="page-slug">URL slug *</label>
                    <input type="text" id="page-slug" name="slug" class="form-control <?= !empty($errors['slug'])?'is-invalid':'' ?>"
                           value="<?= e($page['slug'] ?? old('slug')) ?>" required>
                </div>
                <div>
                    <label for="status">Status</label>
                    <select name="status" class="form-control" id="status">
                        <option value="draft"     <?= ($page['status'] ?? '') === 'draft'     ? 'selected' : '' ?>>Draft</option>
                        <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                    </select>
                </div>
                <div>
                    <label for="layout">Layout style</label>
                    <select name="layout" class="form-control" id="layout">
                        <option value="default" <?= ($page['layout'] ?? 'default') === 'default' ? 'selected' : '' ?>>Default</option>
                        <option value="wide"    <?= ($page['layout'] ?? '') === 'wide'    ? 'selected' : '' ?>>Wide</option>
                        <option value="minimal" <?= ($page['layout'] ?? '') === 'minimal' ? 'selected' : '' ?>>Minimal</option>
                    </select>
                </div>
                <div>
                    <label for="sort_order">Sort</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int) ($page['sort_order'] ?? 0)?>" id="sort_order">
                </div>
                <div style="display:flex;align-items:flex-end;gap:.85rem;flex-wrap:wrap">
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;font-weight:400;font-size:13px;color:#374151;text-transform:none;letter-spacing:0">
                        <input type="hidden" name="is_public" value="0">
                        <input type="checkbox" name="is_public" value="1" <?= ($page['is_public'] ?? 1) ? 'checked' : '' ?>>
                        Public
                    </label>
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;font-weight:400;font-size:13px;color:#374151;text-transform:none;letter-spacing:0">
                        <input type="hidden" name="featured" value="0">
                        <input type="checkbox" name="featured" value="1" <?= !empty($page['featured']) ? 'checked' : '' ?>>
                        ⭐ Featured
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- ── SEO drawer (closed by default) ───────────────────────────── -->
    <details class="meta-drawer">
        <summary>SEO settings</summary>
        <div class="meta-drawer-body">
            <div class="meta-grid" style="margin-top:.85rem">
                <div>
                    <label for="seo_title">SEO title</label>
                    <input type="text" name="seo_title" class="form-control" value="<?= e($page['seo_title'] ?? '')?>" maxlength="255" placeholder="Defaults to page title" id="seo_title">
                </div>
                <div>
                    <label for="seo_keywords">Keywords</label>
                    <input type="text" name="seo_keywords" class="form-control" value="<?= e($page['seo_keywords'] ?? '')?>" placeholder="comma, separated, keywords" id="seo_keywords">
                </div>
            </div>
            <div style="margin-top:.65rem">
                <label for="seo_description" style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem">Meta description</label>
                <textarea name="seo_description" class="form-control" rows="2" maxlength="500" id="seo_description"><?= e($page['seo_description'] ?? '') ?></textarea>
            </div>
        </div>
    </details>

    <!-- ── Fallback content drawer (closed by default; auto-grows) ──── -->
    <details class="meta-drawer">
        <summary>Fallback content (HTML body)</summary>
        <div class="meta-drawer-body">
            <p style="margin:.65rem 0;color:var(--text-muted);font-size:12.5px">
                Rendered when this page has no composer layout. HTML is allowed; only a safe subset (headings, lists, bold/italic/underline, links, blockquote) survives save.
            </p>
            <textarea name="body" class="form-control body-grow" rows="6" placeholder="Page body…" oninput="growTextarea(this)" aria-label="Page body…"><?= e($page['body'] ?? '') ?></textarea>
        </div>
    </details>

    <!-- ── Home-page toggle (closed by default) ─────────────────────── -->
    <?php
    $currentHomeSlug = setting('guest_home_page_slug', '');
    $isCurrentHome   = !empty($page['slug']) && $page['slug'] === $currentHomeSlug;
    ?>
    <details class="meta-drawer" <?= $isCurrentHome ? 'open' : '' ?>>
        <summary>Guest home page</summary>
        <div class="meta-drawer-body">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500;color:#78350f;margin-top:.65rem">
                <input type="hidden" name="is_home_page" value="0">
                <input type="checkbox" name="is_home_page" value="1" <?= $isCurrentHome ? 'checked' : '' ?>>
                Use as guest home page
            </label>
            <div style="font-size:12.5px;color:#78350f;margin-top:.3rem;line-height:1.5">
                When checked, this page is shown at <code>/</code> for visitors who are not signed in.
                <?php if ($currentHomeSlug && !$isCurrentHome): ?>
                The current home page is <strong>/<?= e($currentHomeSlug) ?></strong>; saving with this
                box checked will replace it.
                <?php elseif (!$currentHomeSlug): ?>
                No page is currently set as the home page — guests see the login screen at <code>/</code>.
                <?php endif; ?>
                Only takes effect when the page is Published and Public.
            </div>
        </div>
    </details>

    <?php if ($page): ?>

    <!-- ── Composer grid config drawer (open by default when composer is on) ─ -->
    <details class="meta-drawer" id="composer">
        <summary>Layout grid</summary>
        <div class="meta-drawer-body">
            <p style="margin:.65rem 0;color:var(--text-muted);font-size:12.5px">
                Configure the grid the composer below visualises. Column widths and row heights are CSS percentages; they don't have to sum to exactly 100.
            </p>
            <div class="meta-grid" style="margin-top:.65rem">
                <div>
                    <label for="rows">Rows (1-6)</label>
                    <input type="number" name="rows" min="1" max="6" required
                           value="<?= (int) ($layout['rows'] ?? 2)?>" class="form-control"
                           oninput="rebuildGrid()" id="rows">
                </div>
                <div>
                    <label for="cols">Columns (1-4)</label>
                    <input type="number" name="cols" min="1" max="4" required
                           value="<?= (int) ($layout['cols'] ?? 2)?>" class="form-control"
                           oninput="rebuildGrid()" id="cols">
                </div>
                <div>
                    <label for="gap_pct">Gap (% of width)</label>
                    <input type="number" name="gap_pct" min="0" max="20" required
                           value="<?= (int) ($layout['gap_pct'] ?? 3)?>" class="form-control"
                           oninput="rebuildGrid()" id="gap_pct">
                </div>
                <div>
                    <label for="col_widths">Column widths (CSV)</label>
                    <input type="text" name="col_widths" required
                           value="<?= e(implode(',', (array) ($layout['col_widths'] ?? [65, 32])))?>"
                           class="form-control" placeholder="65, 32"
                           oninput="rebuildGrid()" id="col_widths">
                </div>
                <div>
                    <label for="row_heights">Row heights (CSV)</label>
                    <input type="text" name="row_heights" required
                           value="<?= e(implode(',', (array) ($layout['row_heights'] ?? [32, 65])))?>"
                           class="form-control" placeholder="32, 65"
                           oninput="rebuildGrid()" id="row_heights">
                </div>
                <div>
                    <label for="max_width_px">Max width (px)</label>
                    <input type="number" name="max_width_px" min="320" max="4096" required
                           value="<?= (int) ($layout['max_width_px'] ?? 1280)?>" class="form-control" id="max_width_px">
                </div>
            </div>
        </div>
    </details>

    <!-- ── Composer pane: grid + palette ────────────────────────────── -->
    <div class="composer-pane">
        <section class="composer-main" aria-label="Page composer">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.65rem">
                <h2 style="margin:0;font-size:1rem;font-weight:600">Composer</h2>
                <div style="font-size:11.5px;color:var(--text-subtle)">Drag a block from the palette into a cell. Click a tile to edit its settings. × removes it.</div>
            </div>
            <div id="composer-grid" class="composer-grid"></div>
        </section>

        <aside class="composer-palette" aria-label="Block palette">
            <h3>Blocks</h3>
            <input type="search" id="palette-filter" placeholder="Filter blocks…"
                   class="form-control" style="font-size:12.5px;padding:.35rem .55rem;margin-bottom:.5rem"
                   aria-label="Filter block palette">
            <div id="palette-tiles"></div>
        </aside>
    </div>

    <!-- Hidden inputs for placements — rebuilt by JS on every state change. -->
    <div id="placements-inputs" hidden></div>


    <!-- Settings modal + field-kit JS: shared with the forms builder.
         The partial provides #cb-modal (singular instance), the
         .cb-modal-* / .cb-rep-* CSS, and window.* JS helpers
         (renderSchemaField, readModalField, escapeHtml, etc).
         Inline JS function declarations later in this file currently
         shadow the window.* assignments — that is intentional during
         the migration; future cleanup can prune the inline copies. -->
    <?php include BASE_PATH . '/app/Views/partials/_modal_field_kit.php'; ?>

    <?php else: ?>
    <div class="card" style="margin-top:.85rem">
        <div class="card-body" style="padding:1.25rem;text-align:center;color:var(--text-muted);font-size:13.5px">
            💡 Save the page first to set up its layout grid and drop content blocks into it.
        </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:.75rem;margin-top:1rem">
        <button type="submit" class="btn btn-primary"><?= $page ? 'Save Page' : 'Create Page' ?></button>
        <a href="/admin/pages" class="btn btn-secondary">Cancel</a>
    </div>
</form>
</div>

<script>
function autoSlugPage(input) {
    const slug = input.value.toLowerCase().replace(/[^a-z0-9\s\-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
    const slugInput = document.getElementById('page-slug');
    if (!slugInput.dataset.touched) slugInput.value = slug;
}
document.getElementById('page-slug')?.addEventListener('input', function() { this.dataset.touched = '1'; });

/* Auto-grow the body textarea on input. Two-pass: snap to scrollHeight,
   capped at viewport-half so a very long body doesn't push the composer
   off-screen. */
function growTextarea(el) {
    el.style.height = 'auto';
    const cap = Math.floor(window.innerHeight * 0.55);
    el.style.height = Math.min(cap, el.scrollHeight + 2) + 'px';
}
document.querySelectorAll('.body-grow').forEach(t => growTextarea(t));
</script>

<?php if ($page): ?>
<script>
/* ───────────────────────────────────────────────────────────────────
   Composer state + JS
   ──────────────────────────────────────────────────────────────────── */
const __blocks     = <?= json_encode($__blockCatalog,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const __placements = <?= json_encode($__placements,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

/* Per-row + per-cell style state. Server-rendered into JSON so reload
   round-trips without an extra fetch. Mutated by the row/cell style
   modals; rebuildHiddenInputs serialises them back to form fields. */
const __rowStyles  = <?= json_encode((object) $__rowStyles,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const __cellStyles = <?= json_encode((object) $__cellStyles,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

/* Modal state — one modal element, three modes:
     'placement' — block settings + visibility + style
     'row'       — row-level styling (bg, full-bleed, padding)
     'cell'      — cell-level styling (bg, padding) */
let   __modalMode = '';
let   __modalIdx  = -1;     // placement index, used in 'placement' mode
let   __modalRow  = -1;     // row index,        used in 'row' / 'cell' mode
let   __modalCol  = -1;     // col index,        used in 'cell' mode

/* Common style schema used by row, cell, and placement modals. The
   placement mode prepends visibility + the block's settingsSchema.
   Row mode appends full-bleed + content-padding fields. */
const __commonStyleSchema = [
    { key: 'bg_color',   label: 'Background color', type: 'text',
      help: 'Hex (#fef3c7), rgb()/rgba(), hsl()/hsla(), or a CSS named color. Anything else is ignored.' },
    { key: 'bg_image',   label: 'Background image URL', type: 'text',
      help: 'http(s) or root-relative. Other URL schemes are dropped.' },
    { key: 'padding_px', label: 'Padding (px)', type: 'number' },
    { key: 'radius_px',  label: 'Corner radius (px)', type: 'number' },
    { key: 'text_color', label: 'Text color', type: 'text' },
];
const __rowExtraSchema = [
    { key: 'full_bleed',         label: 'Full-bleed (extends past page margins)', type: 'checkbox' },
    { key: 'content_padding_px', label: 'Inner content side padding (px)', type: 'number',
      help: 'Override the default side padding within this row only.' },
];

/* ── Repeater state ─────────────────────────────────────────────────
   Each repeater (array-of-objects) and string_list (array-of-strings)
   field renders into a container element in the modal. Items get a
   globally unique uid so nested fields can build collision-free DOM
   ids (cb-field-{uid}-{subkey}, cb-rep-{uid}-{subkey}).
   __repSchemaRegistry maps a container's id → the field descriptor,
   so the "+ Add" click handler knows what kind of item to build. We
   reset both on every modal open so old entries can't collide with
   uids generated for the new modal contents. */
let __uidCounter = 1;
function nextUid() { return 'r' + (__uidCounter++); }
const __repSchemaRegistry = {};
function resetRepeaterState() {
    __uidCounter = 1;
    for (const k of Object.keys(__repSchemaRegistry)) delete __repSchemaRegistry[k];
}

/* ── Grid rendering ─────────────────────────────────────────────────── */
function readLayoutInputs() {
    const csv = (s) => String(s).split(',').map(x => parseInt(x.trim(), 10) || 0).filter(x => x > 0);
    return {
        rows:       Math.max(1, Math.min(6, parseInt(document.querySelector('[name=rows]').value, 10) || 2)),
        cols:       Math.max(1, Math.min(4, parseInt(document.querySelector('[name=cols]').value, 10) || 2)),
        gap_pct:    Math.max(0, Math.min(20, parseInt(document.querySelector('[name=gap_pct]').value, 10) || 3)),
        col_widths: csv(document.querySelector('[name=col_widths]').value),
        row_heights:csv(document.querySelector('[name=row_heights]').value),
    };
}

function rebuildGrid() {
    const lay  = readLayoutInputs();
    const grid = document.getElementById('composer-grid');
    grid.style.setProperty('--composer-gap', lay.gap_pct + '%');

    // Each row gets its own grid with column tracks built from col_widths.
    // Padding-shorter-than-cols falls back to evenly distributed.
    const colTpl = [];
    for (let c = 0; c < lay.cols; c++) {
        colTpl.push((lay.col_widths[c] || (100 / lay.cols)) + 'fr');
    }

    grid.innerHTML = '';
    for (let r = 0; r < lay.rows; r++) {
        const rowEl = document.createElement('div');
        rowEl.className = 'composer-row';
        rowEl.dataset.row = r;
        applyRowStylePreview(rowEl, __rowStyles[r] || null);

        // Left rail: row label + style button.
        const rail = document.createElement('div');
        rail.className = 'composer-row-rail';
        rail.innerHTML = `
            <div class="composer-row-rail-label">R${r+1}</div>
            <button type="button" title="Row settings" data-row-style="${r}">⚙</button>
        `;
        rail.querySelector('button[data-row-style]').addEventListener('click', () => openRowStyleModal(r));
        rowEl.appendChild(rail);

        // Per-row inner grid with the cells.
        const rowGrid = document.createElement('div');
        rowGrid.className = 'composer-row-grid';
        rowGrid.style.gridTemplateColumns = colTpl.join(' ');
        rowGrid.style.minHeight = (lay.row_heights[r] || 80) + 'px';
        rowEl.appendChild(rowGrid);

        for (let c = 0; c < lay.cols; c++) {
            const cell = document.createElement('div');
            cell.className   = 'composer-cell';
            cell.dataset.row = r;
            cell.dataset.col = c;
            applyCellStylePreview(cell, __cellStyles[r + '-' + c] || null);

            // Cell toolbar (label on the left, ⚙ on the right).
            const tb = document.createElement('div');
            tb.className = 'composer-cell-toolbar';
            tb.innerHTML = `
                <span style="font-size:10.5px;color:var(--text-subtle);text-transform:uppercase;letter-spacing:.05em;font-weight:600">R${r+1} · C${c+1}</span>
                <button type="button" title="Cell settings" data-cell-style="${r}-${c}">⚙</button>
            `;
            tb.querySelector('button[data-cell-style]').addEventListener('click', () => openCellStyleModal(r, c));
            cell.appendChild(tb);
            wireCellDropTarget(cell);
            rowGrid.appendChild(cell);

            // Hydrate placements targeting this cell.
            const matching = __placements
                .map((p, i) => ({ p, i }))
                .filter(x => x.p.row === r && x.p.col === c)
                .sort((a, b) => (a.p.sort_order || 0) - (b.p.sort_order || 0));
            for (const { p, i } of matching) {
                cell.appendChild(makePlacementTile(p, i));
            }
        }
        grid.appendChild(rowEl);
    }
}

/* Visual preview: paint the row/cell wrapper with the stored style so
   admins see roughly what the public render will look like. We DO NOT
   apply text_color or radius here (they'd interfere with the dashed
   border / cell-toolbar contrast); the public renderer handles those. */
function applyRowStylePreview(el, style) {
    el.classList.toggle('is-styled',    !!(style && Object.keys(style).length));
    el.classList.toggle('is-fullbleed', !!(style && style.full_bleed));
    el.style.background = style && style.bg_color ? style.bg_color : '';
    if (style && style.bg_image) {
        el.style.backgroundImage    = `url("${style.bg_image}")`;
        el.style.backgroundSize     = 'cover';
        el.style.backgroundPosition = 'center';
    } else {
        el.style.backgroundImage = '';
    }
}
function applyCellStylePreview(el, style) {
    el.classList.toggle('is-styled', !!(style && Object.keys(style).length));
    if (style && style.bg_color) el.style.backgroundColor = style.bg_color;
    if (style && style.bg_image) {
        el.style.backgroundImage    = `url("${style.bg_image}")`;
        el.style.backgroundSize     = 'cover';
        el.style.backgroundPosition = 'center';
    }
}

function makePlacementTile(p, idx) {
    const block = __blocks[p.block_key] || null;
    const label = block ? block.label : (p.block_key || '(unset)');
    const aud   = block ? block.audience : 'any';
    const audTag = (p.visible_to && p.visible_to !== 'any') ? p.visible_to : (aud !== 'any' ? aud : '');

    const tile = document.createElement('div');
    tile.className   = 'placement-tile';
    tile.draggable   = true;
    tile.dataset.idx = idx;
    tile.title       = block ? block.description : '';
    tile.innerHTML = `
        <span class="placement-tile-label">${escapeHtml(label)}</span>
        ${audTag ? `<span class="placement-tile-aud">${escapeHtml(audTag)}</span>` : ''}
        <button type="button" class="placement-tile-x" title="Remove" aria-label="Remove">×</button>
    `;
    tile.addEventListener('click', (e) => {
        if (e.target.classList.contains('placement-tile-x')) return;
        openBlockModal(idx);
    });
    tile.querySelector('.placement-tile-x').addEventListener('click', (e) => {
        e.stopPropagation();
        removePlacement(idx);
    });
    tile.addEventListener('dragstart', (e) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/x-placement-idx', String(idx));
    });
    return tile;
}

/* ── Drag-and-drop wiring ───────────────────────────────────────────── */
function wireCellDropTarget(cell) {
    cell.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        cell.classList.add('is-dragover');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('is-dragover'));
    cell.addEventListener('drop', (e) => {
        e.preventDefault();
        cell.classList.remove('is-dragover');
        const r = parseInt(cell.dataset.row, 10);
        const c = parseInt(cell.dataset.col, 10);

        const blockKey = e.dataTransfer.getData('text/x-block-key');
        const moveIdx  = e.dataTransfer.getData('text/x-placement-idx');
        if (blockKey) {
            addPlacement(blockKey, r, c);
        } else if (moveIdx !== '') {
            const i = parseInt(moveIdx, 10);
            if (!isNaN(i) && __placements[i]) {
                __placements[i].row = r;
                __placements[i].col = c;
                __placements[i].sort_order = nextSortOrder(r, c);
                rebuildGrid();
                rebuildHiddenInputs();
            }
        }
    });
}

function nextSortOrder(row, col) {
    let max = -1;
    for (const p of __placements) {
        if (p.row === row && p.col === col && (p.sort_order || 0) > max) max = p.sort_order || 0;
    }
    return max + 1;
}

function addPlacement(blockKey, row, col) {
    const block = __blocks[blockKey];
    if (!block) return;
    __placements.push({
        row, col,
        sort_order: nextSortOrder(row, col),
        block_key:  blockKey,
        visible_to: 'any',
        settings:   JSON.parse(JSON.stringify(block.defaultSettings || {})),
    });
    rebuildGrid();
    rebuildHiddenInputs();
}

function removePlacement(idx) {
    if (idx < 0 || idx >= __placements.length) return;
    __placements.splice(idx, 1);
    rebuildGrid();
    rebuildHiddenInputs();
}

/* ── Palette ────────────────────────────────────────────────────────── */
function rebuildPalette() {
    const filterEl = document.getElementById('palette-filter');
    const filter   = (filterEl?.value || '').trim().toLowerCase();
    const wrap     = document.getElementById('palette-tiles');
    wrap.innerHTML = '';

    // Group blocks by category.
    const byCat = {};
    for (const key of Object.keys(__blocks)) {
        const b = __blocks[key];
        if (filter) {
            const hay = (b.label + ' ' + b.key + ' ' + (b.description || '')).toLowerCase();
            if (!hay.includes(filter)) continue;
        }
        (byCat[b.category] ||= []).push(b);
    }
    const cats = Object.keys(byCat).sort();
    for (const cat of cats) {
        const h = document.createElement('div');
        h.className   = 'palette-cat';
        h.textContent = cat;
        wrap.appendChild(h);

        byCat[cat].sort((a, b) => a.label.localeCompare(b.label));
        for (const b of byCat[cat]) {
            const tile = document.createElement('div');
            tile.className     = 'palette-tile';
            tile.draggable     = true;
            tile.dataset.key   = b.key;
            tile.title         = b.description || '';
            tile.innerHTML = `
                <span class="palette-tile-label">${escapeHtml(b.label)}</span>
                <span class="palette-tile-aud">${escapeHtml(b.key)}${b.audience !== 'any' ? ' · ' + escapeHtml(b.audience) + ' only' : ''}</span>
            `;
            tile.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/x-block-key', b.key);
            });
            // Click-to-add: drops into the first cell.
            tile.addEventListener('click', () => addPlacement(b.key, 0, 0));
            wrap.appendChild(tile);
        }
    }
    if (cats.length === 0) {
        wrap.innerHTML = '<div style="color:var(--text-subtle);font-size:12.5px;padding:.5rem .25rem">No blocks match.</div>';
    }
}
document.getElementById('palette-filter')?.addEventListener('input', rebuildPalette);

/* ── Hidden-input mirroring (keeps the existing backend POST shape) ─── */
function rebuildHiddenInputs() {
    const wrap = document.getElementById('placements-inputs');
    wrap.innerHTML = '';

    // Per-placement: row/col/sort/block_key/visible_to/settings/style.
    __placements.forEach((p, i) => {
        const settings = (typeof p.settings === 'string')
            ? p.settings
            : JSON.stringify(p.settings || {});
        const style    = (typeof p.style === 'string')
            ? p.style
            : JSON.stringify(p.style || {});
        wrap.insertAdjacentHTML('beforeend',
            `<input type="hidden" name="placements[${i}][row]"        value="${p.row || 0}">
             <input type="hidden" name="placements[${i}][col]"        value="${p.col || 0}">
             <input type="hidden" name="placements[${i}][sort_order]" value="${p.sort_order || 0}">
             <input type="hidden" name="placements[${i}][block_key]"  value="${escapeAttr(p.block_key || '')}">
             <input type="hidden" name="placements[${i}][visible_to]" value="${escapeAttr(p.visible_to || 'any')}">
             <input type="hidden" name="placements[${i}][settings]"   value="${escapeAttr(settings)}">
             <input type="hidden" name="placements[${i}][style]"      value="${escapeAttr(style)}">`
        );
    });

    // Row + cell styles posted as single JSON-encoded fields. The
    // service decodes via coerceJsonInput + sanitises per entry.
    wrap.insertAdjacentHTML('beforeend',
        `<input type="hidden" name="row_styles"  value="${escapeAttr(JSON.stringify(__rowStyles))}">
         <input type="hidden" name="cell_styles" value="${escapeAttr(JSON.stringify(__cellStyles))}">`
    );
}

/* ── Modal ──────────────────────────────────────────────────────────── */
function openBlockModal(idx) {
    if (idx < 0 || idx >= __placements.length) return;
    resetRepeaterState();
    __modalMode = 'placement';
    __modalIdx = idx;
    __modalRow = __modalCol = -1;
    const p     = __placements[idx];
    const block = __blocks[p.block_key];
    const body  = document.getElementById('cb-modal-body');
    const title = document.getElementById('cb-modal-title');

    title.textContent = block ? `${block.label} settings` : `${p.block_key} settings`;

    // Visibility selector (always present).
    let html = `
        <div class="form-group">
            <label for="cb-field-visible_to" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">Visible to</label>
            <select id="cb-field-visible_to" class="form-control">
                <option value="any"${p.visible_to === 'any' ? ' selected' : ''}>Anyone</option>
                <option value="auth"${p.visible_to === 'auth' ? ' selected' : ''}>Logged in</option>
                <option value="guest"${p.visible_to === 'guest' ? ' selected' : ''}>Guests only</option>
            </select>
            ${block && block.audience !== 'any'
                ? `<div class="cb-modal-help">Heads-up: this block is <strong>${escapeHtml(block.audience)}</strong>-only at the render layer — it returns nothing to viewers outside that audience regardless of this setting.</div>`
                : ''}
        </div>
    `;

    // Per-block fields from settingsSchema, or a JSON textarea fallback.
    const schema = (block && Array.isArray(block.settingsSchema)) ? block.settingsSchema : [];
    if (schema.length > 0) {
        html += `<h3 style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:1rem 0 .35rem;padding-top:.65rem;border-top:1px solid var(--border-subtle);font-weight:700">Block settings</h3>`;
        for (const f of schema) {
            html += renderSchemaField(f, p.settings);
        }
    } else {
        html += `
            <div class="form-group" style="margin-top:1rem;padding-top:.85rem;border-top:1px solid var(--border-subtle)">
                <label for="cb-field-__json" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">Settings (JSON)</label>
                <textarea id="cb-field-__json" class="form-control" rows="8"
                          style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                >${escapeHtml(JSON.stringify(p.settings || {}, null, 2))}</textarea>
                <div class="cb-modal-help">No structured schema declared for this block — edit the raw JSON.</div>
            </div>
        `;
    }

    // Style section — common fields. Stored on the placement separately
    // from `settings` so block-specific fields don't get polluted.
    html += `<h3 style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin:1rem 0 .35rem;padding-top:.65rem;border-top:1px solid var(--border-subtle);font-weight:700">Block wrapper style</h3>`;
    for (const f of __commonStyleSchema) {
        html += renderSchemaField({ ...f, key: '__style_' + f.key }, prefixedStyle(p.style || {}, '__style_'));
    }

    body.innerHTML = html;
    document.getElementById('cb-modal').classList.add('open');
    document.addEventListener('keydown', modalEscHandler);
}

/* Row style modal — opens a modal scoped to a single row's style. */
function openRowStyleModal(rowIdx) {
    resetRepeaterState();
    __modalMode = 'row';
    __modalIdx = -1;
    __modalRow = rowIdx;
    __modalCol = -1;
    const body  = document.getElementById('cb-modal-body');
    const title = document.getElementById('cb-modal-title');
    title.textContent = `Row ${rowIdx + 1} style`;

    const cur = __rowStyles[rowIdx] || {};
    let html = '<p class="cb-modal-help" style="margin-bottom:.85rem">Backgrounds + full-bleed apply at render time. Hex/rgb/hsl/named colors only — invalid values are dropped silently.</p>';
    for (const f of __commonStyleSchema) {
        html += renderSchemaField({ ...f, key: '__style_' + f.key }, prefixedStyle(cur, '__style_'));
    }
    for (const f of __rowExtraSchema) {
        html += renderSchemaField({ ...f, key: '__style_' + f.key }, prefixedStyle(cur, '__style_'));
    }
    body.innerHTML = html;
    document.getElementById('cb-modal').classList.add('open');
    document.addEventListener('keydown', modalEscHandler);
}

/* Cell style modal — same shape as row, minus the row-only fields. */
function openCellStyleModal(rowIdx, colIdx) {
    resetRepeaterState();
    __modalMode = 'cell';
    __modalIdx = -1;
    __modalRow = rowIdx;
    __modalCol = colIdx;
    const body  = document.getElementById('cb-modal-body');
    const title = document.getElementById('cb-modal-title');
    title.textContent = `Cell R${rowIdx + 1} · C${colIdx + 1} style`;

    const key = rowIdx + '-' + colIdx;
    const cur = __cellStyles[key] || {};
    let html = '<p class="cb-modal-help" style="margin-bottom:.85rem">Cell-level wrapper. Background colors and image URLs are sanitised on save.</p>';
    for (const f of __commonStyleSchema) {
        html += renderSchemaField({ ...f, key: '__style_' + f.key }, prefixedStyle(cur, '__style_'));
    }
    body.innerHTML = html;
    document.getElementById('cb-modal').classList.add('open');
    document.addEventListener('keydown', modalEscHandler);
}

/* Re-key an unprefixed style object to the prefixed shape that
   renderSchemaField reads from when populating defaults. */
function prefixedStyle(style, prefix) {
    const out = {};
    for (const k of Object.keys(style)) out[prefix + k] = style[k];
    return out;
}

function renderSchemaField(f, settings, prefix) {
    const key   = f.key;
    const idKey = (prefix || '') + key;
    const label = escapeHtml(f.label || key);
    const help  = f.help ? `<div class="cb-modal-help">${escapeHtml(f.help)}</div>` : '';
    const def   = (settings && Object.prototype.hasOwnProperty.call(settings, key)) ? settings[key] : f.default;
    const id    = `cb-field-${idKey}`;

    if (f.type === 'checkbox') {
        return `
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:13.5px;cursor:pointer">
                    <input type="checkbox" id="${id}" data-key="${escapeAttr(key)}" data-type="checkbox" ${def ? 'checked' : ''}>
                    ${label}
                </label>
                ${help}
            </div>`;
    }
    if (f.type === 'select') {
        const opts = f.options || {};
        let optHtml = '';
        for (const [v, lbl] of Object.entries(opts)) {
            optHtml += `<option value="${escapeAttr(v)}"${String(v) === String(def) ? ' selected' : ''}>${escapeHtml(lbl)}</option>`;
        }
        return `
            <div class="form-group">
                <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                <select id="${id}" class="form-control" data-key="${escapeAttr(key)}" data-type="select">${optHtml}</select>
                ${help}
            </div>`;
    }
    if (f.type === 'textarea') {
        return `
            <div class="form-group">
                <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                <textarea id="${id}" class="form-control" rows="4" data-key="${escapeAttr(key)}" data-type="textarea"
                          ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}
                >${escapeHtml(String(def == null ? '' : def))}</textarea>
                ${help}
            </div>`;
    }
    if (f.type === 'json') {
        const val = (def == null) ? '' : JSON.stringify(def, null, 2);
        return `
            <div class="form-group">
                <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                <textarea id="${id}" class="form-control" rows="8" data-key="${escapeAttr(key)}" data-type="json"
                          style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                >${escapeHtml(val)}</textarea>
                ${help}
            </div>`;
    }
    if (f.type === 'number') {
        return `
            <div class="form-group">
                <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                <input id="${id}" type="number" class="form-control" data-key="${escapeAttr(key)}" data-type="number"
                       value="${escapeAttr(String(def == null ? '' : def))}"
                       ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}>
                ${help}
            </div>`;
    }
    if (f.type === 'repeater') {
        // Array-of-objects editor. Each item is a card with the
        // field's item_schema rendered inside, plus reorder/remove
        // buttons. Container id is what the "+ Add" handler and the
        // save-side reader look up. Item body is rendered with a uid
        // prefix so nested fields and nested repeaters get unique
        // DOM ids.
        const repId = `cb-rep-${idKey}`;
        __repSchemaRegistry[repId] = f;
        const items = Array.isArray(def) ? def : [];
        let itemsHtml = '';
        for (const itemVal of items) itemsHtml += buildRepeaterItemHTML(f, itemVal);
        const addLabel = escapeHtml(f.item_label || 'item');
        return `
            <div class="form-group">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                ${help}
                <div class="cb-rep-container" id="${repId}" data-key="${escapeAttr(key)}" data-list-type="object">
                    <div class="cb-rep-items">${itemsHtml}</div>
                    <button type="button" class="btn btn-sm btn-secondary cb-rep-add" data-act="add" data-rep="${repId}">+ Add ${addLabel}</button>
                </div>
            </div>`;
    }
    if (f.type === 'string_list') {
        // Flat array of strings — single text input per row.
        const repId = `cb-rep-${idKey}`;
        __repSchemaRegistry[repId] = f;
        const items = Array.isArray(def) ? def : [];
        let itemsHtml = '';
        for (const v of items) itemsHtml += buildStringListItemHTML(String(v == null ? '' : v));
        const addLabel = escapeHtml(f.item_label || 'line');
        return `
            <div class="form-group">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                ${help}
                <div class="cb-rep-container" id="${repId}" data-key="${escapeAttr(key)}" data-list-type="string">
                    <div class="cb-rep-items">${itemsHtml}</div>
                    <button type="button" class="btn btn-sm btn-secondary cb-rep-add" data-act="add" data-rep="${repId}">+ Add ${addLabel}</button>
                </div>
            </div>`;
    }
    // text fallback
    return `
        <div class="form-group">
            <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
            <input id="${id}" type="text" class="form-control" data-key="${escapeAttr(key)}" data-type="text"
                   value="${escapeAttr(String(def == null ? '' : def))}"
                   ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}>
            ${help}
        </div>`;
}

/* Build a single repeater item: header (title + reorder/remove
   buttons) plus a body that recursively renders the item_schema with
   a fresh uid prefix. Empty itemValue ({}) is fine — sub-renderers
   fall back to each field's `default`. */
function buildRepeaterItemHTML(repField, itemValue) {
    const uid = nextUid();
    const subs = Array.isArray(repField.item_schema) ? repField.item_schema : [];
    let bodyHtml = '';
    for (const sub of subs) {
        bodyHtml += renderSchemaField(sub, itemValue || {}, uid + '-');
    }
    const titleLabel = escapeHtml(repField.item_label || 'Item');
    return `
        <div class="cb-rep-item" data-uid="${uid}">
            <div class="cb-rep-item-header">
                <span class="cb-rep-item-title">${titleLabel}</span>
                <div class="cb-rep-item-actions">
                    <button type="button" data-act="up"   title="Move up">↑</button>
                    <button type="button" data-act="down" title="Move down">↓</button>
                    <button type="button" data-act="del"  title="Remove">×</button>
                </div>
            </div>
            <div class="cb-rep-item-body">${bodyHtml}</div>
        </div>`;
}

/* String-list items are simpler — just the text input plus the
   reorder/remove buttons inline. The hidden data-key="__line"
   identifies the input to readStringListValue. */
function buildStringListItemHTML(value) {
    const uid = nextUid();
    return `
        <div class="cb-rep-item cb-strlist-item" data-uid="${uid}">
            <input type="text" id="cb-field-${uid}-__line" data-key="__line" value="${escapeAttr(value)}" aria-label="List item">
            <div class="cb-rep-item-actions">
                <button type="button" data-act="up"   title="Move up">↑</button>
                <button type="button" data-act="down" title="Move down">↓</button>
                <button type="button" data-act="del"  title="Remove">×</button>
            </div>
        </div>`;
}

/* Read a single schema field's current modal value. Returns the value
   in its native shape (number/bool/string/array). Returns the
   sentinel {__error:msg} when JSON parsing fails or when a nested
   repeater bubbles an error up. */
function readModalField(f, prefix) {
    const t = f.type || 'text';
    if (t === 'repeater')    return readRepeaterValue(f, prefix);
    if (t === 'string_list') return readStringListValue(f, prefix);

    const id = 'cb-field-' + (prefix || '') + f.key;
    const el = document.getElementById(id);
    if (!el) return undefined;
    if (t === 'checkbox') return el.checked;
    if (t === 'number')   return el.value === '' ? null : Number(el.value);
    if (t === 'json') {
        if (el.value.trim() === '') return f.default ?? null;
        try { return JSON.parse(el.value); }
        catch (err) { return { __error: `Invalid JSON in "${f.label}": ` + err.message }; }
    }
    return el.value;
}

/* Walk the repeater's items in DOM order and rebuild the array.
   Each item's uid prefixes the recursive sub-field reads, so nested
   repeaters Just Work. Errors (invalid JSON / nested repeater error)
   bubble up via the {__error} sentinel. */
function readRepeaterValue(f, prefix) {
    const repId = `cb-rep-${prefix || ''}${f.key}`;
    const container = document.getElementById(repId);
    if (!container) return f.default ?? [];
    const items = container.querySelectorAll(':scope > .cb-rep-items > .cb-rep-item');
    const out = [];
    for (const item of items) {
        const uid = item.dataset.uid;
        const obj = {};
        const subs = Array.isArray(f.item_schema) ? f.item_schema : [];
        for (const sub of subs) {
            const v = readModalField(sub, uid + '-');
            if (v && typeof v === 'object' && '__error' in v) return v;
            if (v !== undefined) obj[sub.key] = v;
        }
        out.push(obj);
    }
    return out;
}

/* String-list reader. Empty rows are dropped — admins shouldn't have
   to manually clean up a half-typed feature line. */
function readStringListValue(f, prefix) {
    const repId = `cb-rep-${prefix || ''}${f.key}`;
    const container = document.getElementById(repId);
    if (!container) return f.default ?? [];
    const inputs = container.querySelectorAll(':scope > .cb-rep-items > .cb-rep-item input[data-key="__line"]');
    const out = [];
    for (const inp of inputs) {
        const v = (inp.value || '').trim();
        if (v !== '') out.push(v);
    }
    return out;
}

/* Delegated click handler for repeater controls (add/del/up/down).
   Wired once on init against the modal body so it covers items added
   after the initial render. */
function handleRepClick(e) {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    if (!btn.closest('#cb-modal-body')) return;
    const act = btn.dataset.act;

    if (act === 'add') {
        e.preventDefault();
        const repId = btn.dataset.rep;
        const f = __repSchemaRegistry[repId];
        if (!f) return;
        const itemsWrap = document.querySelector(`#${CSS.escape(repId)} > .cb-rep-items`);
        if (!itemsWrap) return;
        const html = (f.type === 'string_list')
            ? buildStringListItemHTML('')
            : buildRepeaterItemHTML(f, {});
        itemsWrap.insertAdjacentHTML('beforeend', html);
        return;
    }

    const item = btn.closest('.cb-rep-item');
    if (!item) return;

    if (act === 'del') {
        e.preventDefault();
        item.remove();
        return;
    }
    if (act === 'up') {
        e.preventDefault();
        const prev = item.previousElementSibling;
        if (prev) prev.before(item);
        return;
    }
    if (act === 'down') {
        e.preventDefault();
        const next = item.nextElementSibling;
        if (next) next.after(item);
        return;
    }
}

/* Read every common-style field into an unprefixed object; drop empty
   strings + nulls + zeroes so a sanitised-empty style serialises as
   {} (which the service stores as NULL). Used by all three modes. */
function readCommonStyleFromModal(extras) {
    const out = {};
    const all = [...__commonStyleSchema, ...(extras || [])];
    for (const f of all) {
        const v = readModalField(f, '__style_');
        if (v && typeof v === 'object' && '__error' in v) { alert(v.__error); throw v; }
        if (v === '' || v == null || v === false || v === 0) continue;
        out[f.key] = v;
    }
    return out;
}

function saveBlockModal() {
    try {
        if (__modalMode === 'row') {
            const style = readCommonStyleFromModal(__rowExtraSchema);
            if (Object.keys(style).length === 0) delete __rowStyles[__modalRow];
            else __rowStyles[__modalRow] = style;
        }
        else if (__modalMode === 'cell') {
            const style = readCommonStyleFromModal();
            const key   = __modalRow + '-' + __modalCol;
            if (Object.keys(style).length === 0) delete __cellStyles[key];
            else __cellStyles[key] = style;
        }
        else if (__modalMode === 'placement') {
            if (__modalIdx < 0) return;
            const p     = __placements[__modalIdx];
            const block = __blocks[p.block_key];

            p.visible_to = document.getElementById('cb-field-visible_to').value;

            const schema = (block && Array.isArray(block.settingsSchema)) ? block.settingsSchema : [];
            if (schema.length > 0) {
                const next = {};
                for (const f of schema) {
                    const v = readModalField(f, '');
                    if (v && typeof v === 'object' && '__error' in v) { alert(v.__error); return; }
                    if (v !== undefined) next[f.key] = v;
                }
                p.settings = next;
            } else {
                const ta = document.getElementById('cb-field-__json');
                try { p.settings = ta.value.trim() === '' ? {} : JSON.parse(ta.value); }
                catch (err) { alert('Invalid JSON: ' + err.message); return; }
            }

            // Block wrapper style — independent of block-specific settings.
            p.style = readCommonStyleFromModal();
        }
    } catch (e) {
        // readCommonStyleFromModal throws on JSON parse error; alert
        // already shown.
        return;
    }

    closeBlockModal();
    rebuildGrid();
    rebuildHiddenInputs();
}

function closeBlockModal() {
    __modalMode = '';
    __modalIdx = __modalRow = __modalCol = -1;
    document.getElementById('cb-modal').classList.remove('open');
    document.removeEventListener('keydown', modalEscHandler);
}
function modalEscHandler(e) { if (e.key === 'Escape') closeBlockModal(); }
document.getElementById('cb-modal')?.addEventListener('click', (e) => {
    if (e.target.id === 'cb-modal') closeBlockModal();
});

/* ── Helpers ────────────────────────────────────────────────────────── */
function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function escapeAttr(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

/* ── Init ───────────────────────────────────────────────────────────── */
rebuildGrid();
rebuildPalette();
rebuildHiddenInputs();
document.getElementById('cb-modal-body')?.addEventListener('click', handleRepClick);
</script>
<?php endif; ?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
