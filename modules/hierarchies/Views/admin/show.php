<?php $pageTitle = 'Hierarchy — ' . $hierarchy['name']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
/* Drag-drop tree editor. Native HTML5 DnD, no library — same idiom as the
   menu builder's item list (modules/menus/Views/admin/items.php). The three
   drop zones per row are the standard file-explorer convention: the top and
   bottom thirds reorder within the target's sibling group, the middle third
   re-parents INTO the target. */
.hn-tree ul { list-style: none; margin: 0; }
.hn-row {
    display: flex; align-items: center; gap: .5rem;
    padding: .25rem .35rem; border-radius: 4px; cursor: grab;
}
.hn-row:hover { background: var(--color-gray-50); }
.hn-row.is-dragging { opacity: .45; cursor: grabbing; }
.hn-grip { color: var(--color-gray-400); font-size: 13px; user-select: none; }
/* Distinct affordances: a line for "place beside", a box for "drop inside". */
.hn-row.drop-before { box-shadow: inset 0  2px 0 0 var(--color-primary); }
.hn-row.drop-after  { box-shadow: inset 0 -2px 0 0 var(--color-primary); }
.hn-row.drop-into {
    background: var(--accent-subtle, var(--color-gray-100));
    outline: 2px solid var(--color-primary); outline-offset: -2px;
}
.hn-root-zone {
    margin-top: .75rem; padding: .55rem; text-align: center;
    font-size: 12px; color: var(--color-gray-500);
    border: 1px dashed var(--color-gray-300); border-radius: 4px;
}
.hn-root-zone.drop-into {
    border-color: var(--color-primary); color: var(--color-primary);
    background: var(--accent-subtle, var(--color-gray-100));
}
/* While a save is in flight the tree stops accepting input, so a second drop
   cannot race the first and reorder against a stale DOM. */
.hn-busy { opacity: .6; pointer-events: none; }
</style>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
    <a href="/admin/hierarchies" style="color:var(--color-gray-500);text-decoration:none;font-size:13px">← All hierarchies</a>
    <h1 style="margin:0;font-size:1.5rem;flex:1"><?= e($hierarchy['name']) ?></h1>
    <code><?= e($hierarchy['slug']) ?></code>
</div>

<div style="display:grid;gap:1rem;grid-template-columns:2fr 1fr">
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:.5rem">
        <h3 style="margin:0;flex:1">Tree</h3>
        <span style="font-size:11px;color:var(--color-gray-500)">Drag a row to reorder; drop onto a row to make it a child</span>
    </div>
    <div class="card-body">
        <?php
        // Recursive render — inline closure avoids a separate partial
        // since it's self-contained admin markup.
        //
        // Each <li> carries data-node-id and data-parent-id so the drag-drop
        // client can read the tree back out of the DOM rather than keeping a
        // parallel model that could drift from what is on screen.
        $render = function(array $nodes, int $depth) use (&$render) {
            if (empty($nodes)) return;
            echo '<ul style="padding-left:' . ($depth ? '1.25rem' : '0') . '">';
            foreach ($nodes as $n) {
                echo '<li data-node-id="' . (int) $n['id'] . '"'
                   . ' data-parent-id="' . ($n['parent_id'] === null ? '' : (int) $n['parent_id']) . '"'
                   . ' style="padding:.25rem 0;border-bottom:1px solid var(--color-gray-100)">';
                echo '<div class="hn-row" draggable="true">';
                echo '<span class="hn-grip" aria-hidden="true">&#10303;</span>';
                echo '<strong>' . e($n['label']) . '</strong>';
                echo '<code style="color:var(--color-gray-400);font-size:11px">' . e($n['slug']) . '</code>';
                if (!empty($n['url'])) echo '<a href="' . e($n['url']) . '" style="font-size:11px;color:var(--color-gray-500)" target="_blank" draggable="false">' . e($n['url']) . '</a>';
                echo '<span style="margin-left:auto;display:flex;gap:.25rem">';
                echo '<details style="display:inline-block"><summary style="cursor:pointer;color:var(--color-gray-500);font-size:12px">Add child</summary>';
                echo '<form method="post" action="/admin/hierarchies/' . (int) $n['hierarchy_id'] . '/nodes" style="margin-top:.25rem;padding:.5rem;background:var(--color-gray-50);border-radius:4px">';
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
        <div style="text-align:center;color:var(--color-gray-500);padding:1.5rem">Empty — add a root node from the right panel.</div>
        <?php else: ?>
        <div class="hn-tree" id="hn-tree">
            <?php $render($tree, 0); ?>
            <div class="hn-root-zone" id="hn-root-zone">Drop here to move to the top level</div>
        </div>
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
                <label style="display:block;margin-top:.5rem">Color <input name="color" style="width:100%" placeholder="var(--color-info)"></label>
            </div>
            <div class="card-footer" style="padding:.5rem;background:var(--color-gray-50);text-align:right">
                <button type="submit" class="btn btn-sm btn-primary">Add root</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-header"><strong>Embed this tree</strong></div>
        <div class="card-body" style="font-size:12px;color:var(--color-gray-500)">
            In a view:
            <pre style="background:var(--color-gray-100);padding:.5rem;border-radius:4px;margin-top:.25rem"><?= e('<?= render_hierarchy_nav(\'' . $hierarchy['slug'] . '\') ?>') ?></pre>
            Or read the tree directly:
            <pre style="background:var(--color-gray-100);padding:.5rem;border-radius:4px;margin-top:.25rem"><?= e('$tree = hierarchy_tree(\'' . $hierarchy['slug'] . '\');') ?></pre>
        </div>
    </div>
</aside>
</div>

<script>
/**
 * Drag-drop reparent + reorder for the hierarchy tree.
 *
 * Wires the two endpoints that already existed with no client:
 *   POST /admin/hierarchies/nodes/{id}/move   — new_parent_id (0 = top level)
 *   POST /admin/hierarchies/nodes/reorder     — ids[] in the new order
 *
 * The DOM is the model. Every question a drop needs answered — who is my
 * parent, who are my siblings, is the target inside me — is read back out of
 * the rendered tree, so there is no parallel structure to drift out of step
 * with what the admin can actually see.
 *
 * Deferred to DOMContentLoaded because csrfPost/safeJson come from app.js,
 * which the layout loads in the FOOTER, i.e. after this script is parsed.
 */
document.addEventListener('DOMContentLoaded', function () {
    var tree = document.getElementById('hn-tree');
    if (!tree) return;

    var rootZone = document.getElementById('hn-root-zone');
    var rootList = tree.querySelector(':scope > ul');
    var dragId   = null;

    function liOf(id) {
        return tree.querySelector('li[data-node-id="' + id + '"]');
    }
    /** Parent node id for an <li>, or null when it sits at the top level. */
    function parentIdOf(li) {
        var p = li.parentElement.closest('li[data-node-id]');
        return p ? p.dataset.nodeId : null;
    }
    /** The <ul> holding a node's children, created on demand for a first child. */
    function childListOf(li) {
        var ul = li.querySelector(':scope > ul');
        if (!ul) {
            ul = document.createElement('ul');
            ul.style.paddingLeft = '1.25rem';
            li.appendChild(ul);
        }
        return ul;
    }
    /** Ids of every <li> directly inside a list, in the order they now appear. */
    function siblingIds(ul) {
        return Array.prototype.map.call(
            ul.querySelectorAll(':scope > li[data-node-id]'),
            function (li) { return li.dataset.nodeId; }
        );
    }
    function clearMarks() {
        Array.prototype.forEach.call(
            tree.querySelectorAll('.drop-before, .drop-after, .drop-into'),
            function (el) { el.classList.remove('drop-before', 'drop-after', 'drop-into'); }
        );
    }
    /** Top third = before, bottom third = after, middle = become a child. */
    function zoneFor(row, event) {
        var box = row.getBoundingClientRect();
        var y   = event.clientY - box.top;
        if (y < box.height / 3)     return 'before';
        if (y > box.height * 2 / 3) return 'after';
        return 'into';
    }
    /** A node may never be dropped inside its own subtree — that orphans it. */
    function wouldCycle(fromId, targetLi) {
        var from = liOf(fromId);
        return !!from && from.contains(targetLi);
    }

    /**
     * Apply a drop: move the <li> in the DOM first so the result is visible
     * immediately, then persist. A failure reloads rather than trying to
     * invert the move — the server is the authority and a resync is always
     * correct, where a hand-rolled undo can itself be wrong.
     */
    function applyDrop(fromId, toId, mode) {
        var fromLi = liOf(fromId);
        if (!fromLi) return;

        var oldParentId = parentIdOf(fromLi);
        var oldList     = fromLi.parentElement;
        var newParentId;

        if (mode === 'into') {
            var intoLi = liOf(toId);
            if (!intoLi) return;
            childListOf(intoLi).appendChild(fromLi);
            newParentId = toId;
        } else if (mode === 'root') {
            if (!rootList) return;
            rootList.appendChild(fromLi);
            newParentId = null;
        } else {
            var toLi = liOf(toId);
            if (!toLi) return;
            newParentId = parentIdOf(toLi);
            toLi.parentElement.insertBefore(fromLi, mode === 'before' ? toLi : toLi.nextSibling);
        }

        // A child list emptied by the move is meaningless markup; drop it so
        // a later "first child" goes through childListOf() cleanly.
        if (oldList !== fromLi.parentElement && oldList.children.length === 0
            && oldList.parentElement && oldList.parentElement.matches('li[data-node-id]')) {
            oldList.remove();
        }
        fromLi.dataset.parentId = newParentId === null ? '' : newParentId;

        persist(fromId, oldParentId, newParentId, fromLi.parentElement);
    }

    function persist(fromId, oldParentId, newParentId, destList) {
        tree.classList.add('hn-busy');

        var chain = Promise.resolve();

        // Only ask for a move when the parent actually changed. A pure
        // reorder inside one group must not post new_parent_id, or every
        // drag would rewrite closure rows for no reason.
        if (String(oldParentId) !== String(newParentId)) {
            chain = chain.then(function () {
                return csrfPost('/admin/hierarchies/nodes/' + fromId + '/move', {
                    new_parent_id: newParentId === null ? 0 : newParentId
                });
            }).then(function (res) {
                if (!res || res.ok !== true) {
                    throw new Error((res && res.error) || 'the move was rejected');
                }
            });
        }

        chain.then(function () {
            var fd = new FormData();
            siblingIds(destList).forEach(function (id) { fd.append('ids[]', id); });
            return csrfPost('/admin/hierarchies/nodes/reorder', fd);
        }).then(function (res) {
            if (!res || res.ok !== true) {
                throw new Error((res && res.error) || 'the new order was rejected');
            }
            tree.classList.remove('hn-busy');
        }).catch(function (err) {
            alert('Could not save that move: ' + (err.message || err) + '\nReloading to resync.');
            location.reload();
        });
    }

    // ── Row handlers ─────────────────────────────────────────────────────
    Array.prototype.forEach.call(tree.querySelectorAll('.hn-row'), function (row) {
        var li = row.closest('li[data-node-id]');

        row.addEventListener('dragstart', function (e) {
            dragId = li.dataset.nodeId;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/x-hn-node', dragId);
        });
        row.addEventListener('dragend', function () {
            dragId = null;
            row.classList.remove('is-dragging');
            clearMarks();
            if (rootZone) rootZone.classList.remove('drop-into');
        });

        row.addEventListener('dragover', function (e) {
            // Refusing the affordance is clearer than letting a drop fail:
            // no preventDefault means no drop, and the cursor says so.
            if (!dragId || dragId === li.dataset.nodeId) return;
            if (wouldCycle(dragId, li)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            clearMarks();
            row.classList.add('drop-' + zoneFor(row, e));
        });
        row.addEventListener('dragleave', function () {
            row.classList.remove('drop-before', 'drop-after', 'drop-into');
        });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var fromId = e.dataTransfer.getData('text/x-hn-node') || dragId;
            var toId   = li.dataset.nodeId;
            var mode   = zoneFor(row, e);
            clearMarks();
            if (!fromId || fromId === toId) return;
            if (wouldCycle(fromId, li)) return;
            applyDrop(fromId, toId, mode);
        });
    });

    // ── "Move to the top level" zone ─────────────────────────────────────
    // Without this a node that is already a child could never be promoted
    // back to a root, since every other drop target is itself a node.
    if (rootZone) {
        rootZone.addEventListener('dragover', function (e) {
            if (!dragId) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            rootZone.classList.add('drop-into');
        });
        rootZone.addEventListener('dragleave', function () {
            rootZone.classList.remove('drop-into');
        });
        rootZone.addEventListener('drop', function (e) {
            e.preventDefault();
            rootZone.classList.remove('drop-into');
            var fromId = e.dataTransfer.getData('text/x-hn-node') || dragId;
            if (!fromId) return;
            applyDrop(fromId, null, 'root');
        });
    }
});
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
