<?php $pageTitle = 'System Layout: ' . $name; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto">

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin/system-layouts" style="color:#4f46e5;text-decoration:none">← All system layouts</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.4rem;font-weight:700">
            <?= e(trim((string) ($layout['friendly_name'] ?? '')) ?: $name) ?>
        </h1>
        <div style="font-size:13px;color:#6b7280;margin-top:.25rem">
            <code style="font-size:12px"><?= e($name) ?></code>
            · <?= $hasLayout ? 'Composer enabled' : 'Layout not yet saved — defaults shown' ?>
        </div>
    </div>
</div>

<form method="POST" action="/admin/system-layouts/<?= e(rawurlencode($name)) ?>">
    <?= csrf_field() ?>

    <!-- Layout metadata — drives the /admin/system-layouts index UX -->
    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h2 style="margin:0;font-size:1rem">About this layout</h2></div>
        <div class="card-body">
            <p style="color:#6b7280;font-size:13px;margin:0 0 .85rem 0">
                Optional metadata that helps admins find and recognise this
                layout on the index page. Module migrations seed reasonable
                defaults; you can override them here.
            </p>
            <div class="grid grid-2">
                <div class="form-group">
                    <label for="friendly_name">Friendly name</label>
                    <input type="text" name="friendly_name" id="friendly_name"
                           maxlength="255"
                           value="<?= e($layout['friendly_name'] ?? '') ?>"
                           class="form-control"
                           placeholder="e.g. Messaging — Inbox view">
                </div>
                <div class="form-group">
                    <label for="module">Module</label>
                    <input type="text" name="module" id="module"
                           maxlength="64"
                           value="<?= e($layout['module'] ?? '') ?>"
                           class="form-control"
                           placeholder="e.g. messaging"
                           pattern="[a-zA-Z0-9_-]*">
                </div>
            </div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label for="category">Category</label>
                    <input type="text" name="category" id="category"
                           maxlength="64"
                           value="<?= e($layout['category'] ?? '') ?>"
                           class="form-control"
                           placeholder="e.g. Messaging">
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <input type="text" name="description" id="description"
                           value="<?= e($layout['description'] ?? '') ?>"
                           class="form-control"
                           placeholder="One-sentence summary shown under the friendly name">
                </div>
            </div>
        </div>
    </div>

    <!-- Layout grid configuration -->
    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h2 style="margin:0;font-size:1rem">Grid</h2></div>
        <div class="card-body">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="rows">Rows (1–6)</label>
                    <input type="number" name="rows" min="1" max="6" required
                           value="<?= (int) ($layout['rows'] ?? 1)?>"
                           class="form-control" style="max-width:140px" id="rows">
                </div>
                <div class="form-group">
                    <label for="cols">Columns (1–4)</label>
                    <input type="number" name="cols" min="1" max="4" required
                           value="<?= (int) ($layout['cols'] ?? 2)?>"
                           class="form-control" style="max-width:140px" id="cols">
                </div>
                <div class="form-group">
                    <label for="gap_pct">Gap (% of width)</label>
                    <input type="number" name="gap_pct" min="0" max="20" required
                           value="<?= (int) ($layout['gap_pct'] ?? 3)?>"
                           class="form-control" style="max-width:140px" id="gap_pct">
                </div>
            </div>
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="col_widths">Column widths (% comma-separated)</label>
                    <input type="text" name="col_widths" required
                           value="<?= e(implode(',', (array) ($layout['col_widths'] ?? [70, 27])))?>"
                           class="form-control" placeholder="e.g. 70, 27" id="col_widths">
                </div>
                <div class="form-group">
                    <label for="row_heights">Row heights (% comma-separated)</label>
                    <input type="text" name="row_heights" required
                           value="<?= e(implode(',', (array) ($layout['row_heights'] ?? [100])))?>"
                           class="form-control" placeholder="e.g. 100" id="row_heights">
                </div>
                <div class="form-group">
                    <label for="max_width_px">Max width (px)</label>
                    <input type="number" name="max_width_px" min="320" max="4096" required
                           value="<?= (int) ($layout['max_width_px'] ?? 1280)?>"
                           class="form-control" style="max-width:160px" id="max_width_px">
                </div>
            </div>
        </div>
    </div>

    <!-- Placement editor — block + page-content slot rows -->
    <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0;font-size:1rem">Placements</h2>
            <div style="display:flex;gap:.5rem">
                <button type="button" class="btn btn-sm btn-secondary" onclick="addPlacementRow('block')">+ Block</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addPlacementRow('content_slot')">+ Page content</button>
            </div>
        </div>
        <div class="card-body">
            <p style="color:#6b7280;font-size:13px;margin:0 0 .85rem 0">
                Drop blocks or "page content" slots into specific cells of
                the grid above. Multiple placements per cell stack vertically
                by sort order. Page-content rows are filled at request time
                by the controller — the default slot name is <code>primary</code>.
            </p>
            <table class="table" id="placement-table" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:70px">Row</th>
                        <th style="width:70px">Col</th>
                        <th style="width:80px">Order</th>
                        <th style="width:140px">Kind</th>
                        <th>Block / Slot</th>
                        <th style="width:140px">Visible to</th>
                        <th>Settings (JSON)</th>
                        <th style="width:80px">Remove</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($placements as $i => $p): ?>
                    <?php include BASE_PATH . '/app/Views/admin/system_layouts/_layout_row.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($placements)): ?>
                <tr id="placement-empty"><td colspan="8" style="text-align:center;color:#9ca3af;padding:1rem">No placements yet. Click "+ Block" or "+ Page content" to start composing.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">Save layout &amp; placements</button>
        <a href="/admin/system-layouts" class="btn btn-secondary">Cancel</a>
    </div>
</form>

</div>

<!-- Hidden templates for new placement rows. Two variants — block and
     content_slot — so the "+ Block" / "+ Page content" buttons each
     drop in a row pre-set to the right placement_type. -->
<template id="placement-template-block">
    <?php
        $i = '__INDEX__';
        $p = ['row_index' => 0, 'col_index' => 0, 'sort_order' => 0,
              'placement_type' => 'block', 'block_key' => '',
              'settings' => [], 'visible_to' => 'any'];
        include BASE_PATH . '/app/Views/admin/system_layouts/_layout_row.php';
    ?>
</template>
<template id="placement-template-content_slot">
    <?php
        $i = '__INDEX__';
        $p = ['row_index' => 0, 'col_index' => 0, 'sort_order' => 0,
              'placement_type' => 'content_slot', 'slot_name' => 'primary',
              'block_key' => '', 'settings' => [], 'visible_to' => 'any'];
        include BASE_PATH . '/app/Views/admin/system_layouts/_layout_row.php';
    ?>
</template>

<script>
let __placementIndex = <?= count($placements) ?>;

function addPlacementRow(kind) {
    if (kind !== 'block' && kind !== 'content_slot') kind = 'block';
    const tpl  = document.getElementById('placement-template-' + kind);
    const html = tpl.innerHTML.replace(/__INDEX__/g, String(__placementIndex++));
    document.querySelector('#placement-table tbody').insertAdjacentHTML('beforeend', html);
    document.getElementById('placement-empty')?.remove();
}

/* Per-row label sync + delete-mark visualisation. Same handlers the
   per-page composer editor uses, with the page-chrome additions:
     - placement-type select swaps the block-cell vs slot-cell visibility
     - the row-label updates from either the block dropdown OR the
       slot input depending on current type.
*/
function __updateRowLabel(row) {
    const lbl  = row.querySelector('.placement-row-label');
    if (!lbl) return;
    const type = row.dataset.placementType || 'block';
    if (type === 'content_slot') {
        const slot = row.querySelector('input[name*="[slot_name]"]')?.value?.trim() || 'primary';
        lbl.textContent = 'slot:' + slot;
        lbl.title       = 'slot:' + slot;
    } else {
        const sel = row.querySelector('select.placement-block-select');
        lbl.textContent = sel?.value || '—';
        lbl.title       = sel?.value || '';
    }
}

document.addEventListener('change', (e) => {
    const t = e.target;
    if (!t) return;

    if (t.matches && t.matches('select.placement-type-select')) {
        const row = t.closest('tr.placement-row');
        if (!row) return;
        const newType = t.value;
        row.dataset.placementType = newType;
        const blockCell = row.querySelector('.placement-block-cell');
        const slotCell  = row.querySelector('.placement-slot-cell');
        const visSelect = row.querySelector('.placement-visibility-select');
        const badge     = row.querySelector('.placement-kind-badge');
        if (blockCell) blockCell.style.display = newType === 'content_slot' ? 'none' : '';
        if (slotCell)  slotCell.style.display  = newType === 'content_slot' ? '' : 'none';
        if (visSelect) {
            visSelect.disabled = newType === 'content_slot';
            visSelect.style.opacity = newType === 'content_slot' ? '.45' : '';
            visSelect.style.pointerEvents = newType === 'content_slot' ? 'none' : '';
        }
        // Toggle the Page-content badge on the kind cell. The badge only
        // exists for slot rows; create it on demand to avoid a static
        // hidden node we'd have to carry around.
        const kindCell = t.parentElement;
        const existing = kindCell.querySelector('.placement-kind-badge');
        if (newType === 'content_slot' && !existing) {
            const span = document.createElement('span');
            span.className = 'placement-kind-badge';
            span.style.cssText = 'display:inline-block;margin-top:.3rem;padding:.05rem .4rem;background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc;border-radius:10px;font-size:10.5px;font-weight:600';
            span.textContent = 'Page content';
            kindCell.appendChild(span);
        } else if (newType !== 'content_slot' && existing) {
            existing.remove();
        }
        __updateRowLabel(row);
    }

    if (t.matches && t.matches('select.placement-block-select')) {
        const row = t.closest('tr.placement-row');
        if (row) __updateRowLabel(row);
    }
    if (t.matches && t.matches('input.placement-delete-checkbox')) {
        const row = t.closest('tr.placement-row');
        if (row) {
            row.style.opacity        = t.checked ? '0.55' : '';
            row.style.background     = t.checked ? '#fef2f2' : '';
            row.style.textDecoration = t.checked ? 'line-through' : '';
        }
    }
});

// Slot-name updates live-fire the row label too.
document.addEventListener('input', (e) => {
    if (e.target && e.target.matches && e.target.matches('input[name*="[slot_name]"]')) {
        const row = e.target.closest('tr.placement-row');
        if (row) __updateRowLabel(row);
    }
});
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
