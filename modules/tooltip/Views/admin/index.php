<?php $pageTitle = 'Tooltips'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php $r = $result; ?>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
        <h2 style="margin:0">Tooltips</h2>
        <div style="display:flex;gap:.4rem">
            <a href="/admin/tooltips/categories" class="btn btn-sm btn-secondary">Categories</a>
            <?php if (!empty($analyticsOn)): ?><a href="/admin/tooltips/analytics" class="btn btn-sm btn-secondary">Analytics</a><?php endif; ?>
            <a href="/admin/tooltips/create" class="btn btn-sm btn-primary">+ New tooltip</a>
        </div>
    </div>

    <form method="get" action="/admin/tooltips" class="card-body" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap;border-bottom:1px solid var(--color-gray-200)">
        <label style="font-size:12px">Search<br><input name="q" value="<?= e((string) $search) ?>" placeholder="slug / label / content" style="width:220px"></label>
        <label style="font-size:12px">Category<br>
            <select name="category" style="width:180px">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= ((int) $categoryId === (int) $c['id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-sm btn-secondary">Filter</button>
        <?php if ($search !== '' || $categoryId): ?><a href="/admin/tooltips" class="btn btn-sm btn-secondary">Clear</a><?php endif; ?>
    </form>

    <?php if (empty($r['rows'])): ?>
    <div class="card-body" style="text-align:center;color:var(--color-gray-500);padding:2.5rem">
        No tooltips match. <a href="/admin/tooltips/create">Create one →</a>
    </div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Slug</th><th>Label</th><th>Category</th><th>Trigger</th><th>Theme</th><?php if (!empty($analyticsOn)): ?><th>Views</th><?php endif; ?><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($r['rows'] as $t): ?>
        <tr style="<?= ((int) $t['is_active'] === 0) ? 'opacity:.55' : '' ?>">
            <td><code><?= e((string) $t['slug']) ?></code></td>
            <td><?= e((string) $t['label']) ?></td>
            <td style="font-size:12px"><?= e((string) ($t['category_name'] ?? '—')) ?></td>
            <td style="font-size:12px"><?= e((string) $t['trigger']) ?></td>
            <td style="font-size:12px"><?= e((string) $t['theme']) ?></td>
            <?php if (!empty($analyticsOn)): ?><td><?= (int) $t['view_count'] ?></td><?php endif; ?>
            <td>
                <form method="post" action="/admin/tooltips/<?= (int) $t['id'] ?>/toggle-active" style="display:inline"><?= csrf_field() ?>
                    <button class="btn btn-sm <?= ((int) $t['is_active'] === 1) ? 'btn-secondary' : 'btn-primary' ?>" title="Toggle active"><?= ((int) $t['is_active'] === 1) ? 'On' : 'Off' ?></button>
                </form>
            </td>
            <td style="text-align:right;white-space:nowrap">
                <a href="/admin/tooltips/<?= (int) $t['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <?php if (!empty($overridesOn)): ?><a href="/admin/tooltips/<?= (int) $t['id'] ?>/overrides" class="btn btn-sm btn-secondary">Overrides</a><?php endif; ?>
                <form method="post" action="/admin/tooltips/<?= (int) $t['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete this tooltip?')"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($r['pages'] > 1): ?>
    <div class="card-body" style="display:flex;gap:.4rem;justify-content:center">
        <?php for ($p = 1; $p <= $r['pages']; $p++): ?>
        <a href="/admin/tooltips?<?= http_build_query(array_filter(['q' => $search, 'category' => $categoryId, 'page' => $p])) ?>"
           class="btn btn-sm <?= ($p === $r['page']) ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
