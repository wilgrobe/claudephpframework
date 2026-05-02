<?php $pageTitle = 'Menu Management'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="grid grid-2" style="align-items:flex-start">

    <!-- Menus list -->
    <div class="card">
        <div class="card-header"><h2>Menus</h2></div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Name</th><th>Location</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($menus as $m): ?>
                <tr>
                    <td><strong><?= e($m['name']) ?></strong></td>
                    <td><span class="badge badge-gray"><?= e($m['location']) ?></span></td>
                    <td><?= $m['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></td>
                    <td><a href="/admin/menus/<?= $m['id'] ?>/items" class="btn btn-xs btn-secondary">Manage Items</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create menu -->
    <div class="card">
        <div class="card-header"><h2>Create Menu</h2></div>
        <div class="card-body">
            <?php $errors = \Core\Session::flash('errors') ?? []; ?>
            <form method="POST" action="/admin/menus/create">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Main Navigation" id="name">
                </div>
                <div class="form-group">
                    <label for="location">Location *
                        <span style="font-size:11px;color:#6b7280;font-weight:400">(used in views with menu('location'))</span>
                    </label>
                    <input type="text" name="location" class="form-control" required placeholder="header, footer, sidebar…" id="location">
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" class="form-control" rows="2" id="description"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Create Menu</button>
            </form>
        </div>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
