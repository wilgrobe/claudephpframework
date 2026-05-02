<?php $pageTitle = 'FAQ Management'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="margin-bottom:1rem;display:flex;gap:.75rem;align-items:center">
    <a href="/admin/faqs/categories" class="btn btn-secondary btn-sm">Manage Categories</a>
</div>

<div class="grid grid-2" style="align-items:flex-start">

    <!-- FAQ list -->
    <div class="card">
        <div class="card-header"><h2>All FAQ Entries</h2></div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Question</th><th>Category</th><th>Public</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($faqs as $f): ?>
                <tr>
                    <td style="max-width:280px">
                        <div style="font-weight:500;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($f['question']) ?></div>
                    </td>
                    <td><span class="badge badge-gray"><?= e($f['category_name'] ?? 'Uncategorized') ?></span></td>
                    <td><?= $f['is_public'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
                    <td>
                        <div style="display:flex;gap:.3rem">
                            <a href="/admin/faqs/<?= $f['id'] ?>/edit" class="btn btn-xs btn-secondary">Edit</a>
                            <form method="POST" action="/admin/faqs/<?= $f['id'] ?>/delete" data-confirm="Delete this FAQ?">
                                <?= csrf_field() ?><button class="btn btn-xs btn-danger">×</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add FAQ form -->
    <div class="card">
        <div class="card-header"><h2>Add FAQ Entry</h2></div>
        <div class="card-body">
            <?php $errors = \Core\Session::flash('errors') ?? []; ?>
            <form method="POST" action="/admin/faqs">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select name="category_id" class="form-control" id="category_id">
                        <option value="">— No Category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="question">Question *</label>
                    <textarea name="question" class="form-control <?= !empty($errors['question'])?'is-invalid':''?>" rows="2" required id="question"><?= old('question') ?></textarea>
                    <?php if (!empty($errors['question'])): ?><span class="form-error"><?= e($errors['question'][0]) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="answer">Answer *</label>
                    <textarea name="answer" class="form-control <?= !empty($errors['answer'])?'is-invalid':''?>" rows="5" required id="answer"><?= old('answer') ?></textarea>
                    <?php if (!empty($errors['answer'])): ?><span class="form-error"><?= e($errors['answer'][0]) ?></span><?php endif; ?>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" id="sort_order">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;padding-bottom:.1rem">
                            <input type="checkbox" name="is_public" value="1" checked> Public
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add FAQ</button>
            </form>
        </div>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
