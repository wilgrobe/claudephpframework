<?php
$pageTitle = $set['name'];
$allowHierarchy = (int) $set['allow_hierarchy'] === 1;

/**
 * Recursive term renderer. Defined inline so it's scoped to this view.
 * Walks the $tree array producing a nested <ul><li> with depth-based
 * indentation and an inline delete form per node.
 */
$render = function (array $nodes, int $depth = 0) use (&$render) {
    foreach ($nodes as $node) {
        $indent = $depth * 20;
        echo '<li style="padding:.5rem 0;padding-left:' . $indent . 'px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;gap:1rem">';
        echo '<div style="flex:1;min-width:0">';
        echo '<strong>' . e($node['name']) . '</strong> ';
        echo '<code style="font-size:11px;color:#9ca3af;margin-left:.5rem">' . e($node['slug']) . '</code>';
        if (!empty($node['description'])) {
            echo '<div style="font-size:12px;color:#6b7280;margin-top:.2rem">' . e($node['description']) . '</div>';
        }
        echo '</div>';
        echo '<form method="POST" action="/admin/taxonomy/terms/' . (int) $node['id'] . '/delete" data-confirm="Delete term \'' . e($node['name']) . '\'? This also deletes all its children.">';
        echo csrf_field();
        echo '<button class="btn btn-xs btn-danger">Delete</button>';
        echo '</form>';
        echo '</li>';
        if (!empty($node['children'])) {
            $render($node['children'], $depth + 1);
        }
    }
};
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
    <a href="/admin/taxonomy/sets" class="btn btn-sm btn-secondary">← Back</a>
    <h1 style="margin:0;font-size:1.5rem"><?= e($set['name']) ?></h1>
    <span style="color:#6b7280;font-size:13px"><code><?= e($set['slug']) ?></code>
        · <?= $allowHierarchy ? 'nested' : 'flat' ?></span>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- ── Existing terms tree ─────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h2 style="font-size:1.125rem">Terms</h2></div>
        <?php if (empty($tree)): ?>
        <div class="card-body" style="color:#6b7280;text-align:center;padding:2rem 1rem">
            No terms yet. Add one using the form on the right.
        </div>
        <?php else: ?>
        <ul style="list-style:none;margin:0;padding:0">
            <?php $render($tree); ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- ── Add term + vocabulary edit ──────────────────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2 style="font-size:1.125rem">Add term</h2></div>
            <div class="card-body">
                <form method="POST" action="/admin/taxonomy/sets/<?= (int) $set['id'] ?>/terms">
                    <?= csrf_field() ?>

                    <div class="form-row">
                        <label for="name">Name *</label>
                        <input type="text" name="name" required maxlength="191" id="name">
                    </div>

                    <div class="form-row">
                        <label for="slug">Slug</label>
                        <input type="text" name="slug" maxlength="191" placeholder="auto-from-name" id="slug">
                        <small>Unique within this vocabulary. Leave blank to derive from the name.</small>
                    </div>

                    <?php if ($allowHierarchy && !empty($terms)): ?>
                    <div class="form-row">
                        <label for="parent_id">Parent (optional)</label>
                        <select name="parent_id" id="parent_id">
                            <option value="">— Top level —</option>
                            <?php foreach ($terms as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <label for="description">Description</label>
                        <textarea name="description" rows="2" maxlength="500" id="description"></textarea>
                    </div>

                    <div class="form-row">
                        <label for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" value="0" id="sort_order">
                    </div>

                    <button class="btn btn-primary">Add term</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 style="font-size:1.125rem">Vocabulary settings</h2></div>
            <div class="card-body">
                <form method="POST" action="/admin/taxonomy/sets/<?= (int) $set['id'] ?>">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <label for="name">Name</label>
                        <input type="text" name="name" value="<?= e($set['name'])?>" required maxlength="191" id="name">
                    </div>
                    <div class="form-row">
                        <label for="slug">Slug</label>
                        <input type="text" name="slug" value="<?= e($set['slug'])?>" required maxlength="120" pattern="[a-z0-9-]+" id="slug">
                    </div>
                    <div class="form-row">
                        <label for="description">Description</label>
                        <textarea name="description" rows="2" maxlength="500" id="description"><?= e((string) ($set['description'] ?? '')) ?></textarea>
                    </div>
                    <div class="form-row">
                        <label><input type="checkbox" name="allow_hierarchy" value="1" <?= $allowHierarchy ? 'checked' : '' ?>> Allow nested terms</label>
                        <small>Unchecking doesn't flatten existing nested terms — only prevents new ones.</small>
                    </div>
                    <button class="btn btn-secondary btn-sm">Save settings</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
