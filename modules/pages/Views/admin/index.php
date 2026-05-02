<?php $pageTitle = 'Pages'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<?php $homeSlug = setting('guest_home_page_slug', ''); ?>

<div class="card">
    <div class="card-header">
        <h2>Static Pages</h2>
        <a href="/admin/pages/create" class="btn btn-primary btn-sm">+ New Page</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Public</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pages as $p): ?>
            <?php $isHome = $homeSlug !== '' && $p['slug'] === $homeSlug; ?>
            <tr>
                <td>
                    <strong><?= e($p['title']) ?></strong>
                    <?php if ($isHome): ?>
                    <span class="badge" style="background:#fef3c7;color:#78350f;border:1px solid #fde68a;font-size:10.5px;margin-left:.4rem;padding:.15rem .45rem;border-radius:10px" title="Shown at / for guests">🏠 HOME</span>
                    <?php endif; ?>
                </td>
                <td><code style="font-size:12px;background:#f3f4f6;padding:.1rem .35rem;border-radius:4px">/<?= e($p['slug']) ?></code></td>
                <td>
                    <?php if ($p['status'] === 'published'): ?>
                    <span class="badge badge-success">Published</span>
                    <?php else: ?>
                    <span class="badge badge-gray">Draft</span>
                    <?php endif; ?>
                </td>
                <td><?= $p['is_public'] ? '🌐 Yes' : '🔒 No' ?></td>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/<?= e($p['slug']) ?>" target="_blank" class="btn btn-xs btn-secondary">View</a>
                        <a href="/admin/pages/<?= $p['id'] ?>/edit" class="btn btn-xs btn-secondary">Edit</a>
                        <form method="POST" action="/admin/pages/<?= $p['id'] ?>/delete" data-confirm="Delete page '<?= e($p['title']) ?>'?">
                            <?= csrf_field() ?><button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
