<?php $pageTitle = 'FAQ Categories'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="grid grid-2" style="align-items:flex-start">
    <div class="card">
        <div class="card-header">
            <h2>Categories</h2>
            <a href="/admin/faqs" class="btn btn-secondary btn-sm">← Back to FAQs</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Name</th><th>Slug</th><th>FAQs</th><th>Public</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><strong><?= e($cat['name']) ?></strong></td>
                    <td><code style="font-size:12px"><?= e($cat['slug']) ?></code></td>
                    <td><?= $cat['faq_count'] ?? 0 ?></td>
                    <td><?= $cat['is_public'] ? '🌐' : '🔒' ?></td>
                    <td>
                        <form method="POST" action="/admin/faqs/categories/<?= $cat['id'] ?>/delete"
                              data-confirm="Delete category '<?= e($cat['name']) ?>'? FAQs will become uncategorized.">
                            <?= csrf_field() ?><button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Add Category</h2></div>
        <div class="card-body">
            <?php $errors = \Core\Session::flash('errors') ?? []; ?>
            <form method="POST" action="/admin/faqs/categories">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" name="name" class="form-control" required oninput="catSlug(this)" id="name">
                </div>
                <div class="form-group">
                    <label for="cat-slug">Slug *</label>
                    <input type="text" id="cat-slug" name="slug" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" class="form-control" rows="2" id="description"></textarea>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" id="sort_order">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                            <input type="checkbox" name="is_public" value="1" checked> Public
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Category</button>
            </form>
        </div>
    </div>
</div>
<script>
function catSlug(input) {
    document.getElementById('cat-slug').value = input.value.toLowerCase().replace(/[^a-z0-9\s\-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
}
</script>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
