<?php $pageTitle = 'Hierarchies'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="margin:0">Hierarchies</h2>
        <a href="/admin/hierarchies/create" class="btn btn-sm btn-primary">New hierarchy</a>
    </div>
    <?php if (empty($hierarchies)): ?>
    <div class="card-body" style="text-align:center;color:#6b7280;padding:3rem 1rem">
        No hierarchies yet. Create one for each navigable tree you need — site nav, product catalog, sections, org chart, etc.
    </div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Name</th><th>Slug</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hierarchies as $h): ?>
        <tr>
            <td><strong><?= e($h['name']) ?></strong><?php if (!empty($h['description'])): ?><div style="color:#9ca3af;font-size:12px"><?= e($h['description']) ?></div><?php endif; ?></td>
            <td><code><?= e($h['slug']) ?></code></td>
            <td><?= (int) $h['active'] ? '✓' : '—' ?></td>
            <td>
                <a href="/admin/hierarchies/<?= e($h['slug']) ?>" class="btn btn-sm btn-secondary">Edit tree</a>
                <form method="post" action="/admin/hierarchies/<?= (int) $h['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete hierarchy and all nodes?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
