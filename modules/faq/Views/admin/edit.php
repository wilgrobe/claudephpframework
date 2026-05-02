<?php $pageTitle = 'Edit FAQ'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<div style="max-width:640px;margin:0 auto">
<div class="card">
    <div class="card-header">
        <h2>Edit FAQ Entry</h2>
        <a href="/admin/faqs" class="btn btn-secondary btn-sm">← Back</a>
    </div>
    <div class="card-body">
        <?php $errors = \Core\Session::flash('errors') ?? []; ?>
        <form method="POST" action="/admin/faqs/<?= $faq['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="category_id">Category</label>
                <select name="category_id" class="form-control" id="category_id">
                    <option value="">— No Category —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $faq['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="question">Question *</label>
                <textarea name="question" class="form-control" rows="3" required id="question"><?= e($faq['question']) ?></textarea>
            </div>
            <div class="form-group">
                <label for="answer">Answer *</label>
                <textarea name="answer" class="form-control" rows="8" required id="answer"><?= htmlspecialchars($faq['answer'], ENT_QUOTES) ?></textarea>
            </div>
            <div class="grid grid-3">
                <div class="form-group">
                    <label for="sort_order">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= $faq['sort_order']?>" id="sort_order">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="is_public" value="1" <?= $faq['is_public'] ? 'checked' : '' ?>> Public
                    </label>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="is_active" value="1" <?= $faq['is_active'] ? 'checked' : '' ?>> Active
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/admin/faqs" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
