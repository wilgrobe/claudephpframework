<?php $pageTitle = 'Tooltip analytics'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php $maxViews = 0; foreach ($top as $t) { $maxViews = max($maxViews, (int) $t['view_count']); } ?>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h2 style="margin:0">Tooltip analytics</h2>
        <a href="/admin/tooltips" class="btn btn-sm btn-secondary">← Tooltips</a>
    </div>
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap">
        <?php foreach ([['Total views', (int) $totals['total_views']], ['Tooltips', (int) $totals['total_tooltips']], ['Active', (int) $totals['active_count']]] as $tile): ?>
        <div style="border:1px solid var(--color-gray-200);border-radius:10px;padding:.75rem 1.1rem;min-width:120px">
            <div style="font-size:12px;color:var(--color-gray-500);text-transform:uppercase;letter-spacing:.04em"><?= e($tile[0]) ?></div>
            <div style="font-size:1.5rem;font-weight:700"><?= (int) $tile[1] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">Top tooltips by views</h3></div>
    <?php if (empty($top)): ?>
    <div class="card-body" style="color:var(--color-gray-500)">No data yet.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Slug</th><th>Label</th><th>Category</th><th style="width:40%">Views</th></tr></thead>
        <tbody>
        <?php foreach ($top as $t): $pct = $maxViews > 0 ? round((int) $t['view_count'] / $maxViews * 100) : 0; ?>
        <tr style="<?= ((int) $t['is_active'] === 0) ? 'opacity:.55' : '' ?>">
            <td><code><?= e((string) $t['slug']) ?></code></td>
            <td><?= e((string) $t['label']) ?></td>
            <td style="font-size:12px"><?= e((string) ($t['category_name'] ?? '—')) ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <div style="flex:1;background:var(--accent-subtle);border-radius:6px;height:14px;overflow:hidden"><div style="width:<?= $pct ?>%;height:100%;background:var(--color-primary)"></div></div>
                    <span style="font-size:12px;min-width:34px;text-align:right"><?= (int) $t['view_count'] ?></span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <div class="card-body" style="font-size:12px;color:var(--color-gray-500);border-top:1px solid var(--color-gray-200)">
        Cumulative view counts. A per-day trend chart needs a per-event log table — deferred.
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
