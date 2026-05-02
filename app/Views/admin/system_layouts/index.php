<?php $pageTitle = 'System Layouts'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:960px;margin:0 auto">

<div style="margin-bottom:1rem">
    <h1 style="margin:0 0 .25rem 0;font-size:1.4rem;font-weight:700">System Layouts</h1>
    <p style="color:#6b7280;font-size:13.5px;margin:0">
        Layouts that drive system surfaces — the dashboard, future admin
        landing pages — through the page composer. Each layout is seeded
        by a migration; you can edit the grid + placements here without
        touching SQL. Block options come from the same registry that
        powers the per-page composer at <code>/admin/pages/{id}/layout</code>.
    </p>
</div>

<?php if (empty($layouts)): ?>
<div class="card" style="padding:2rem 1.25rem;text-align:center;color:#9ca3af">
    No system layouts have been seeded yet. Run <code>php artisan migrate</code>
    to create the default <code>dashboard_stats</code> and <code>dashboard_main</code> layouts.
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table" style="margin:0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="width:90px">Rows</th>
                    <th style="width:90px">Cols</th>
                    <th style="width:90px">Gap %</th>
                    <th style="width:120px">Max width</th>
                    <th style="width:120px">Placements</th>
                    <th style="width:160px">Updated</th>
                    <th style="width:90px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($layouts as $row): ?>
                <tr>
                    <td><code style="font-size:12.5px;font-weight:600"><?= e($row['name']) ?></code></td>
                    <td><?= (int) $row['rows'] ?></td>
                    <td><?= (int) $row['cols'] ?></td>
                    <td><?= (int) $row['gap_pct'] ?>%</td>
                    <td><?= (int) $row['max_width_px'] ?>px</td>
                    <td><?= (int) ($row['placement_count'] ?? 0) ?></td>
                    <td style="color:#6b7280;font-size:12.5px">
                        <?= !empty($row['updated_at']) ? date('M j, g:i A', strtotime($row['updated_at'])) : '—' ?>
                    </td>
                    <td>
                        <a href="/admin/system-layouts/<?= e(rawurlencode($row['name'])) ?>" class="btn btn-xs btn-secondary">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
