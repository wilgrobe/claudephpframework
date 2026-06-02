<?php $pageTitle = 'Tooltip categories'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card" style="max-width:760px">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h2 style="margin:0">Tooltip categories</h2>
        <a href="/admin/tooltips" class="btn btn-sm btn-secondary">← Tooltips</a>
    </div>

    <?php if (empty($categories)): ?>
    <div class="card-body" style="color:var(--color-gray-500)">No categories yet.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Name</th><th>Slug</th><th>Tooltips</th><th>Order</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
        <tr>
            <td>
                <form method="post" action="/admin/tooltips/categories/<?= (int) $c['id'] ?>" style="display:flex;gap:.4rem;align-items:center"><?= csrf_field() ?>
                    <input name="name" value="<?= e((string) $c['name']) ?>" style="width:160px">
                    <input name="description" value="<?= e((string) ($c['description'] ?? '')) ?>" placeholder="description" style="width:160px;font-size:12px">
                    <input type="hidden" name="sort_order" value="<?= (int) $c['sort_order'] ?>">
                    <button class="btn btn-sm btn-secondary">Save</button>
                </form>
            </td>
            <td style="font-size:12px"><code><?= e((string) $c['slug']) ?></code></td>
            <td><?= (int) $c['tooltip_count'] ?></td>
            <td><?= (int) $c['sort_order'] ?></td>
            <td style="text-align:right">
                <form method="post" action="/admin/tooltips/categories/<?= (int) $c['id'] ?>/delete" onsubmit="return confirm('Delete category? Its tooltips are kept (detached).')"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <form method="post" action="/admin/tooltips/categories" class="card-body" style="display:flex;gap:.5rem;align-items:end;border-top:1px solid var(--color-gray-200)"><?= csrf_field() ?>
        <label style="font-size:12px">New category<br><input name="name" required placeholder="Name" style="width:180px"></label>
        <label style="font-size:12px">Description<br><input name="description" placeholder="(optional)" style="width:200px"></label>
        <label style="font-size:12px">Order<br><input type="number" name="sort_order" value="0" style="width:70px"></label>
        <button class="btn btn-sm btn-primary">Add</button>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
