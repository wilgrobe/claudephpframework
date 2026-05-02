<?php $pageTitle = 'Taxonomy'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card">
    <div class="card-header">
        <h2>Taxonomy — Vocabularies</h2>
        <a href="/admin/taxonomy/sets/create" class="btn btn-sm btn-primary">+ New vocabulary</a>
    </div>
    <?php if (empty($sets)): ?>
    <div class="card-body">
        <div class="empty-state">
            <div class="empty-state-icon">🏷️</div>
            <p>No vocabularies yet.</p>
            <p>Create one to start organizing — e.g. "Product Categories" (hierarchical) or "Article Tags" (flat).</p>
            <a href="/admin/taxonomy/sets/create" class="btn btn-primary">+ New vocabulary</a>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Slug</th><th>Hierarchy</th><th>Terms</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sets as $s): ?>
            <tr>
                <td>
                    <strong><?= e($s['name']) ?></strong>
                    <?php if (!empty($s['description'])): ?>
                    <div class="row-subtext"><?= e($s['description']) ?></div>
                    <?php endif; ?>
                </td>
                <td><code class="code-chip"><?= e($s['slug']) ?></code></td>
                <td>
                    <?= (int) $s['allow_hierarchy'] === 1
                        ? '<span class="badge badge-info">Nested</span>'
                        : '<span class="badge badge-gray">Flat</span>' ?>
                </td>
                <td><?= (int) $s['term_count'] ?></td>
                <td>
                    <div class="btn-group">
                        <a href="/admin/taxonomy/sets/<?= (int) $s['id'] ?>" class="btn btn-xs btn-secondary">Manage terms</a>
                        <form method="POST" action="/admin/taxonomy/sets/<?= (int) $s['id'] ?>/delete"
                              data-confirm="Delete vocabulary '<?= e($s['name']) ?>' and all <?= (int) $s['term_count'] ?> terms?">
                            <?= csrf_field() ?><button class="btn btn-xs btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
