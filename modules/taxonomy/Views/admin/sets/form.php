<?php
$pageTitle = 'New vocabulary';
$errors = \Core\Session::get('errors', []);
$old    = \Core\Session::get('old', []);
$fieldVal = fn(string $k, string $default = '') => e($old[$k] ?? $default);
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="page-header">
    <a href="/admin/taxonomy/sets" class="btn btn-sm btn-secondary">← Back</a>
    <h1>New vocabulary</h1>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="/admin/taxonomy/sets/create">
            <?= csrf_field() ?>

            <div class="form-row">
                <label for="name">Name *</label>
                <input type="text" name="name" value="<?= $fieldVal('name')?>" required maxlength="191" placeholder="Product Categories" id="name">
                <?php if (!empty($errors['name'])): ?><div class="error"><?= e($errors['name'][0]) ?></div><?php endif; ?>
            </div>

            <div class="form-row">
                <label for="slug">Slug *</label>
                <input type="text" name="slug" value="<?= $fieldVal('slug')?>" required maxlength="120" pattern="[a-z0-9-]+" placeholder="product-categories" id="slug">
                <small>URL-safe identifier used by <code>taxonomy_tree('your-slug')</code>.</small>
                <?php if (!empty($errors['slug'])): ?><div class="error"><?= e($errors['slug'][0]) ?></div><?php endif; ?>
            </div>

            <div class="form-row">
                <label for="description">Description</label>
                <textarea name="description" rows="2" maxlength="500" id="description"><?= $fieldVal('description') ?></textarea>
            </div>

            <div class="form-row">
                <label><input type="checkbox" name="allow_hierarchy" value="1" <?= !isset($old['allow_hierarchy']) || !empty($old['allow_hierarchy']) ? 'checked' : '' ?>> Allow nested terms</label>
                <small>
                    Check for categories (Electronics → Phones → Smartphones).
                    Uncheck for flat tags (just a list of labels).
                </small>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary">Create vocabulary</button>
                <a href="/admin/taxonomy/sets" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
