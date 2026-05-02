<?php $pageTitle = 'New hierarchy'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card">
    <div class="card-header"><h2 style="margin:0">New hierarchy</h2></div>
    <form method="post" action="/admin/hierarchies/create">
        <?= csrf_field() ?>
        <div class="card-body">
            <label>Name <input name="name" required style="width:100%"></label>
            <label style="display:block;margin-top:.75rem">Slug <input name="slug" required style="width:100%" placeholder="main-nav"></label>
            <label style="display:block;margin-top:.75rem">Description <textarea name="description" rows="2" style="width:100%"></textarea></label>
        </div>
        <div class="card-footer" style="padding:.75rem 1.25rem;background:#f9fafb">
            <a href="/admin/hierarchies" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create</button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
