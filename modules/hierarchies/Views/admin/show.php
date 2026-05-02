<?php $pageTitle = 'Hierarchy — ' . $hierarchy['name']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
    <a href="/admin/hierarchies" style="color:#6b7280;text-decoration:none;font-size:13px">← All hierarchies</a>
    <h1 style="margin:0;font-size:1.5rem;flex:1"><?= e($hierarchy['name']) ?></h1>
    <code><?= e($hierarchy['slug']) ?></code>
</div>

<div style="display:grid;gap:1rem;grid-template-columns:2fr 1fr">
<div class="card">
    <div class="card-header"><h3 style="margin:0">Tree</h3></div>
    <div class="card-body">
        <?php
        // Recursive render — inline closure avoids a separate partial
        // since it's self-contained admin markup.
        $render = function(array $nodes, int $depth) use (&$render) {
            if (empty($nodes)) return;
            echo '<ul style="list-style:none;padding-left:' . ($depth ? '1.25rem' : '0') . '">';
            foreach ($nodes as $n) {
                echo '<li style="padding:.25rem 0;border-bottom:1px solid #f3f4f6">';
                echo '<div style="display:flex;align-items:center;gap:.5rem">';
                echo '<strong>' . e($n['label']) . '</strong>';
                echo '<code style="color:#9ca3af;font-size:11px">' . e($n['slug']) . '</code>';
                if (!empty($n['url'])) echo '<a href="' . e($n['url']) . '" style="font-size:11px;color:#6b7280" target="_blank">' . e($n['url']) . '</a>';
                echo '<span style="margin-left:auto;display:flex;gap:.25rem">';
                echo '<details style="display:inline-block"><summary style="cursor:pointer;color:#6b7280;font-size:12px">Add child</summary>';
                echo '<form method="post" action="/admin/hierarchies/' . (int) $n['hierarchy_id'] . '/nodes" style="margin-top:.25rem;padding:.5rem;background:#f9fafb;border-radius:4px">';
                echo csrf_field();
                echo '<input type="hidden" name="parent_id" value="' . (int) $n['id'] . '">';
                echo '<input name="label" placeholder="Label" required>';
                echo '<input name="slug"  placeholder="slug">';
                echo '<input name="url"   placeholder="URL (optional)">';
                echo '<button type="submit" class="btn btn-sm btn-primary">Add</button>';
                echo '</form></details>';
                echo '<form method="post" action="/admin/hierarchies/nodes/' . (int) $n['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete node and children?\')">';
                echo csrf_field();
                echo '<button type="submit" class="btn btn-sm btn-danger">×</button>';
                echo '</form>';
                echo '</span>';
                echo '</div>';
                if (!empty($n['children'])) $render($n['children'], $depth + 1);
                echo '</li>';
            }
            echo '</ul>';
        };
        ?>
        <?php if (empty($tree)): ?>
        <div style="text-align:center;color:#6b7280;padding:1.5rem">Empty — add a root node from the right panel.</div>
        <?php else: ?>
        <?php $render($tree, 0); ?>
        <?php endif; ?>
    </div>
</div>

<aside>
    <div class="card">
        <div class="card-header"><strong>Add root node</strong></div>
        <form method="post" action="/admin/hierarchies/<?= (int) $hierarchy['id'] ?>/nodes">
            <?= csrf_field() ?>
            <div class="card-body">
                <label>Label <input name="label" required style="width:100%"></label>
                <label style="display:block;margin-top:.5rem">Slug <input name="slug" style="width:100%" placeholder="auto from label"></label>
                <label style="display:block;margin-top:.5rem">URL <input name="url" style="width:100%"></label>
                <label style="display:block;margin-top:.5rem">Icon <input name="icon" style="width:100%"></label>
                <label style="display:block;margin-top:.5rem">Color <input name="color" style="width:100%" placeholder="#3b82f6"></label>
            </div>
            <div class="card-footer" style="padding:.5rem;background:#f9fafb;text-align:right">
                <button type="submit" class="btn btn-sm btn-primary">Add root</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-header"><strong>Embed this tree</strong></div>
        <div class="card-body" style="font-size:12px;color:#6b7280">
            In a view:
            <pre style="background:#f3f4f6;padding:.5rem;border-radius:4px;margin-top:.25rem"><?= e('<?= render_hierarchy_nav(\'' . $hierarchy['slug'] . '\') ?>') ?></pre>
            Or read the tree directly:
            <pre style="background:#f3f4f6;padding:.5rem;border-radius:4px;margin-top:.25rem"><?= e('$tree = hierarchy_tree(\'' . $hierarchy['slug'] . '\');') ?></pre>
        </div>
    </div>
</aside>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
