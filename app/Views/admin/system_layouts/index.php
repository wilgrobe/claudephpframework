<?php $pageTitle = 'System Layouts'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto">

<div style="margin-bottom:1rem">
    <h1 style="margin:0 0 .25rem 0;font-size:1.4rem;font-weight:700">System Layouts</h1>
    <p style="color:#6b7280;font-size:13.5px;margin:0">
        Layouts that drive system surfaces — the dashboard, admin landing
        pages, and module pages that opt in to the page-chrome system
        — through the page composer. Each layout is seeded by a migration;
        you can edit the grid, placements, and metadata here without
        touching SQL. Block options come from the same registry that
        powers the per-page composer at <code>/admin/pages/{id}/layout</code>.
    </p>
    <p style="color:#6b7280;font-size:12.5px;margin:.5rem 0 0 0">
        <strong>Page content slots</strong> (the <em>Slots</em> column) are
        placeholders the controller fills at request time. A layout with
        zero slots is pure-block (e.g. the dashboard); a layout with one
        or more slots wraps a controller-driven page (e.g. messaging,
        feed, profile).
    </p>
</div>

<?php if (!empty($layouts)): ?>
<!-- Filter bar — pure JS, no server round-trip. The "with content slots"
     toggle hides legacy layouts (dashboard partials, etc.) so admins
     looking for chrome-wrapping layouts can find them in one glance.
     The free-text box matches against friendly name + slug + category +
     description so an admin can type "policy" or "/account/data" and
     land on the right row regardless of which detail they remember. -->
<div class="card" style="margin-bottom:1rem;padding:.85rem 1.25rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center">
    <input type="search" id="layout-filter" placeholder="Filter by friendly name, slug, category, or description…"
           class="form-control"
           style="flex:1 1 280px;min-width:220px;font-size:13px"
           aria-label="Filter layouts">
    <label style="display:flex;align-items:center;gap:.4rem;font-size:13px;color:#374151;white-space:nowrap;cursor:pointer">
        <input type="checkbox" id="layout-filter-slots-only" style="margin:0">
        Show only layouts with content slots
    </label>
    <span id="layout-filter-count" style="color:#6b7280;font-size:12.5px;margin-left:auto"></span>
</div>
<?php endif; ?>

<?php if (empty($layouts)): ?>
<div class="card" style="padding:2rem 1.25rem;text-align:center;color:#9ca3af">
    No system layouts have been seeded yet. Run <code>php artisan migrate</code>
    to create the default <code>dashboard_stats</code> and <code>dashboard_main</code> layouts.
</div>
<?php else: ?>

<?php foreach (($grouped ?? ['' => $layouts]) as $__moduleKey => $__layoutsInModule): ?>
<?php
    $__moduleLabel = $__moduleKey === '_other' || $__moduleKey === ''
        ? 'Other / unattributed'
        : $__moduleKey;
?>
<div class="card layout-group-card" data-layout-group="<?= e((string) $__moduleKey) ?>" style="margin-bottom:1.25rem">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="margin:0;font-size:1rem;text-transform:capitalize"><?= e($__moduleLabel) ?></h2>
        <span style="color:#6b7280;font-size:12px"
              data-group-count-label
              data-total="<?= count($__layoutsInModule) ?>"><?= count($__layoutsInModule) ?> layout<?= count($__layoutsInModule) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table" style="margin:0">
            <thead>
                <tr>
                    <th>Friendly name</th>
                    <th style="width:200px">Slug</th>
                    <th style="width:110px">Category</th>
                    <th style="width:60px">Rows</th>
                    <th style="width:60px">Cols</th>
                    <th style="width:80px">Blocks</th>
                    <th style="width:80px">Slots</th>
                    <th style="width:140px">Updated</th>
                    <th style="width:160px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($__layoutsInModule as $row):
                $__placementCount = (int) ($row['placement_count'] ?? 0);
                $__slotCount      = (int) ($row['slot_count']      ?? 0);
                $__blockCount     = max(0, $__placementCount - $__slotCount);
                $__friendly       = trim((string) ($row['friendly_name'] ?? '')) ?: '—';
                // Pre-build a lowercased haystack of every searchable
                // field so the JS filter is just an indexOf, no
                // per-keystroke string-building. Joined with a non-word
                // separator to keep "foo" from matching "fooBar"
                // accidentally across two fields.
                $__haystack = strtolower(implode('|', array_filter([
                    $row['name']          ?? '',
                    $row['friendly_name'] ?? '',
                    $row['module']        ?? '',
                    $row['category']      ?? '',
                    $row['description']   ?? '',
                    $row['chromed_url']   ?? '',
                ], fn($v) => $v !== '' && $v !== null)));
            ?>
                <tr data-layout-row
                    data-search="<?= e($__haystack) ?>"
                    data-slots="<?= $__slotCount ?>">
                    <td>
                        <div style="font-weight:600"><?= e($__friendly) ?></div>
                        <?php if (!empty($row['description'])): ?>
                        <div style="color:#6b7280;font-size:12px;margin-top:.15rem;max-width:380px">
                            <?= e($row['description']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size:12.5px"><?= e($row['name']) ?></code></td>
                    <td style="color:#6b7280;font-size:12.5px"><?= e($row['category'] ?? '—') ?></td>
                    <td><?= (int) $row['rows'] ?></td>
                    <td><?= (int) $row['cols'] ?></td>
                    <td><?= $__blockCount ?></td>
                    <td>
                        <?php if ($__slotCount > 0): ?>
                            <span style="display:inline-block;padding:.1rem .45rem;background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc;border-radius:10px;font-size:11.5px;font-weight:600">
                                <?= $__slotCount ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#9ca3af">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#6b7280;font-size:12.5px">
                        <?= !empty($row['updated_at']) ? date('M j, g:i A', strtotime($row['updated_at'])) : '—' ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="/admin/system-layouts/<?= e(rawurlencode($row['name'])) ?>" class="btn btn-xs btn-secondary">Edit</a>
                        <?php if (!empty($row['chromed_url'])): ?>
                        <a href="<?= e($row['chromed_url']) ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-xs btn-secondary"
                           title="Open <?= e($row['chromed_url']) ?> in a new tab"
                           style="margin-left:.25rem">View ↗</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

</div>

<?php if (!empty($layouts)): ?>
<script>
/* /admin/system-layouts filter (Batch E polish).
   Two filter inputs join via AND: the text box does an indexOf against
   the pre-built data-search haystack; the toggle hides rows where
   data-slots="0". Group cards collapse when every row inside is hidden.

   No persistence — filter state resets per page load. Admins typically
   land here via the SA nav and want a clean default view. localStorage
   would also conflict with browser autofill on the search box.
*/
(function() {
    var inputEl     = document.getElementById('layout-filter');
    var slotsOnlyEl = document.getElementById('layout-filter-slots-only');
    var countEl     = document.getElementById('layout-filter-count');
    if (!inputEl || !slotsOnlyEl) return;

    var rows   = document.querySelectorAll('[data-layout-row]');
    var groups = document.querySelectorAll('[data-layout-group]');

    function refresh() {
        var q          = inputEl.value.trim().toLowerCase();
        var slotsOnly  = slotsOnlyEl.checked;
        var visibleAll = 0;

        rows.forEach(function(row) {
            var hay   = row.getAttribute('data-search') || '';
            var slots = parseInt(row.getAttribute('data-slots') || '0', 10);

            var matchText  = (q === '' || hay.indexOf(q) !== -1);
            var matchSlots = (!slotsOnly || slots > 0);
            var visible    = matchText && matchSlots;

            row.style.display = visible ? '' : 'none';
            if (visible) visibleAll++;
        });

        // Per-group: hide the whole card when every row inside is filtered
        // out, otherwise show + update the in-card count.
        groups.forEach(function(card) {
            var groupRows  = card.querySelectorAll('[data-layout-row]');
            var groupShown = 0;
            groupRows.forEach(function(r) {
                if (r.style.display !== 'none') groupShown++;
            });
            card.style.display = groupShown === 0 ? 'none' : '';

            var lbl = card.querySelector('[data-group-count-label]');
            if (lbl) {
                var total = parseInt(lbl.getAttribute('data-total') || '0', 10);
                lbl.textContent = (groupShown === total)
                    ? total + ' layout' + (total === 1 ? '' : 's')
                    : groupShown + ' of ' + total + ' shown';
            }
        });

        if (countEl) {
            var totalRows = rows.length;
            countEl.textContent = (visibleAll === totalRows)
                ? totalRows + ' layout' + (totalRows === 1 ? '' : 's')
                : 'Showing ' + visibleAll + ' of ' + totalRows;
        }
    }

    inputEl.addEventListener('input',  refresh);
    slotsOnlyEl.addEventListener('change', refresh);
    refresh();
})();
</script>
<?php endif; ?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
