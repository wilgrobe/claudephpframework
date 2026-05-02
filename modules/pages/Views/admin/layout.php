<?php $pageTitle = 'Layout: ' . ($page['title'] ?? ''); ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:960px;margin:0 auto">

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin/pages/<?= (int) $page['id'] ?>/edit" style="color:#4f46e5;text-decoration:none">← Back to page</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.4rem;font-weight:700">Layout: <?= e($page['title']) ?></h1>
        <div style="font-size:13px;color:#6b7280;margin-top:.25rem">
            URL: <code>/<?= e($page['slug']) ?></code> ·
            <?= $hasLayout ? 'Composer enabled' : 'No layout — page renders body content' ?>
        </div>
    </div>
    <?php if ($hasLayout): ?>
    <form method="POST" action="/admin/pages/<?= (int) $page['id'] ?>/layout/delete"
          onsubmit="return confirm('Remove this layout and all its block placements? The page will revert to rendering its body content.')">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-danger">Remove layout</button>
    </form>
    <?php endif; ?>
</div>

<form method="POST" action="/admin/pages/<?= (int) $page['id'] ?>/layout">
    <?= csrf_field() ?>

    <!-- Layout grid configuration -->
    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h2 style="margin:0;font-size:1rem">Grid</h2></div>
        <div class="card-body">
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="rows">Rows (1–6)</label>
                    <input type="number" name="rows" min="1" max="6" required
                           value="<?= (int) ($layout['rows'] ?? 2)?>"
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
                           value="<?= e(implode(',', (array) ($layout['col_widths'] ?? [65, 32])))?>"
                           class="form-control" placeholder="e.g. 65, 32" id="col_widths">
                    <span style="font-size:12px;color:#6b7280">Should sum to roughly 100 minus the gap.</span>
                </div>
                <div class="form-group">
                    <label for="row_heights">Row heights (% comma-separated)</label>
                    <input type="text" name="row_heights" required
                           value="<?= e(implode(',', (array) ($layout['row_heights'] ?? [32, 65])))?>"
                           class="form-control" placeholder="e.g. 32, 65" id="row_heights">
                    <span style="font-size:12px;color:#6b7280">Used as minimum row track sizes.</span>
                </div>
                <div class="form-group">
                    <label for="max_width_px">Max width (px)</label>
                    <input type="number" name="max_width_px" min="320" max="4096" required
                           value="<?= (int) ($layout['max_width_px'] ?? 1280)?>"
                           class="form-control" style="max-width:160px" id="max_width_px">
                    <span style="font-size:12px;color:#6b7280">Centred; collapses to one column under 720px.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Placement editor -->
    <div class="card" style="margin-bottom:1rem">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0;font-size:1rem">Block placements</h2>
            <button type="button" class="btn btn-sm btn-secondary" onclick="addPlacementRow()">+ Add placement</button>
        </div>
        <div class="card-body">
            <p style="color:#6b7280;font-size:13px;margin:0 0 .85rem 0">
                Drop blocks into specific cells of the grid above. Multiple placements per cell stack vertically by sort order.
            </p>
            <table class="table" id="placement-table" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:90px">Row</th>
                        <th style="width:90px">Col</th>
                        <th style="width:100px">Order</th>
                        <th>Block</th>
                        <th style="width:130px">Visible to</th>
                        <th>Settings (JSON)</th>
                        <th style="width:70px">Remove</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($placements as $i => $p): ?>
                    <?php include __DIR__ . '/_layout_row.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($placements)): ?>
                <tr id="placement-empty"><td colspan="7" style="text-align:center;color:#9ca3af;padding:1rem">No placements yet. Click "+ Add placement" to drop a block into the grid.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">Save layout &amp; placements</button>
        <a href="/admin/pages/<?= (int) $page['id'] ?>/edit" class="btn btn-secondary">Cancel</a>
    </div>
</form>

</div>

<!-- Hidden template for new placement rows. Cloned by JS on "Add placement". -->
<template id="placement-template">
    <?php
        $i = '__INDEX__';
        $p = ['row_index' => 0, 'col_index' => 0, 'sort_order' => 0, 'block_key' => '', 'settings' => [], 'visible_to' => 'any'];
        include __DIR__ . '/_layout_row.php';
    ?>
</template>

<script>
let __placementIndex = <?= count($placements) ?>;

function addPlacementRow() {
    const tpl = document.getElementById('placement-template');
    const html = tpl.innerHTML.replace(/__INDEX__/g, String(__placementIndex++));
    document.querySelector('#placement-table tbody').insertAdjacentHTML('beforeend', html);
    document.getElementById('placement-empty')?.remove();
}

/* Keep each row's block-key label + visual state in sync with its dropdown +
   Remove checkbox, so admins always see which block they're removing.
   Event-delegated so newly-added (cloned) rows participate without rebinding. */
document.addEventListener('change', (e) => {
    const t = e.target;
    if (!t) return;

    /* Block dropdown changed → update the per-row label cell */
    if (t.matches && t.matches('select.placement-block-select')) {
        const row = t.closest('tr.placement-row');
        const lbl = row?.querySelector('.placement-row-label');
        if (lbl) {
            lbl.textContent = t.value || '—';
            lbl.title = t.value || '';
        }
    }

    /* Remove checkbox toggled → strike-through + warning tint on the whole
       row so the marked-for-delete state reads at a glance. */
    if (t.matches && t.matches('input.placement-delete-checkbox')) {
        const row = t.closest('tr.placement-row');
        if (row) {
            row.style.opacity      = t.checked ? '0.55' : '';
            row.style.background   = t.checked ? '#fef2f2' : '';
            row.style.textDecoration = t.checked ? 'line-through' : '';
        }
    }
});
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
