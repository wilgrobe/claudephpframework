<?php
$pageTitle = 'Menu Items - ' . $menu['name'];
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <a href="/admin/menus" class="btn btn-secondary btn-sm">&larr; All menus</a>
    <h1 style="margin:0;font-size:1.3rem"><?= e($menu['name']) ?> &mdash; items</h1>
    <span style="color:var(--text-muted);font-size:13px">
        location: <code><?= e($menu['location']) ?></code>
    </span>
    <span style="margin-left:auto;display:flex;gap:.5rem">
        <button type="button" class="btn btn-primary" id="mb-save">Save menu</button>
    </span>
</div>

<style>
.mb-grid { display: grid; grid-template-columns: 1fr 320px; gap: 1rem; align-items: flex-start; }
@media (max-width: 1100px) { .mb-grid { grid-template-columns: 1fr; } }

.mb-tree {
    background: var(--bg-panel); border: 1px solid var(--border-default);
    border-radius: 8px; padding: .85rem; min-height: 200px;
}
.mb-tree:empty::before, .mb-tree.is-empty::before {
    content: 'No items yet. Click an item from the palette to add one.';
    display: block; padding: 2rem 1rem; text-align: center;
    color: var(--text-subtle); font-style: italic;
    border: 2px dashed var(--border-default); border-radius: 8px;
}
.mb-item {
    display: flex; align-items: center; gap: .5rem;
    background: var(--bg-page);
    border: 1px solid var(--border-default); border-radius: 6px;
    padding: .45rem .55rem; margin-bottom: .35rem;
    cursor: pointer;
    transition: border-color .12s, box-shadow .12s;
}
.mb-item:hover { border-color: var(--color-primary); box-shadow: 0 1px 4px rgba(79,70,229,.18); }
.mb-item.is-dragging { opacity: .5; }
.mb-item.is-dragover { border-style: dashed; background: var(--accent-subtle); }
.mb-item-children { margin-left: 1.65rem; padding-top: .15rem; }
.mb-drag { color: var(--border-strong); cursor: grab; user-select: none; flex-shrink: 0; padding: 0 .15rem; }
.mb-drag:hover { color: var(--color-primary); }
.mb-kind {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; padding: .15rem .45rem; border-radius: 999px;
    flex-shrink: 0;
}
.mb-kind--link   { background: var(--accent-subtle); color: var(--color-primary); }
.mb-kind--holder { background: var(--border-subtle); color: var(--text-muted); }
.mb-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13.5px; color: var(--text-default); }
.mb-url   { font-family: ui-monospace, Menlo, monospace; font-size: 11.5px; color: var(--text-subtle); flex-shrink: 0; }
.mb-actions { display: flex; gap: .25rem; flex-shrink: 0; }
.mb-actions button {
    background: none; border: 1px solid transparent; color: var(--text-subtle);
    padding: .15rem .45rem; cursor: pointer; border-radius: 4px; line-height: 1;
}
.mb-actions button:hover { color: var(--color-primary); border-color: var(--color-primary); }
.mb-actions button.mb-del:hover { color: var(--color-danger); border-color: var(--color-danger); }

.mb-rail {
    background: var(--bg-panel); border: 1px solid var(--border-default);
    border-radius: 8px; padding: .65rem; max-height: 80vh; overflow-y: auto;
    position: sticky; top: 80px; align-self: start;
}
.mb-rail h3 { margin: 0 0 .5rem; font-size: 13px; font-weight: 700; }
.mb-rail-section { margin-bottom: 1rem; padding-bottom: .85rem; border-bottom: 1px solid var(--border-subtle); }
.mb-rail-section:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
.mb-rail-cat-header {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
    margin: .65rem .25rem .2rem;
}
.mb-tile {
    display: flex; align-items: center; gap: .35rem;
    background: var(--bg-page); border: 1px solid var(--border-subtle);
    border-radius: 6px; padding: .35rem .55rem; margin-bottom: .2rem;
    cursor: pointer; font-size: 12.5px;
    transition: background .12s, border-color .12s;
}
.mb-tile:hover { background: var(--accent-subtle); border-color: var(--color-primary); }
.mb-tile-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-default); }
.mb-tile-url   { font-size: 10.5px; color: var(--text-subtle); flex-shrink: 0; font-family: ui-monospace, Menlo, monospace; }
.mb-special-tile {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: var(--bg-page); border: 1px dashed var(--border-strong); border-radius: 6px;
    padding: .85rem .5rem; cursor: pointer; text-align: center; gap: .15rem;
    transition: border-color .12s, background .12s;
}
.mb-special-tile:hover { border-color: var(--color-primary); background: var(--accent-subtle); }
.mb-special-tile-icon { font-size: 1.35rem; line-height: 1; }
.mb-special-tile-label { font-size: 12.5px; font-weight: 600; color: var(--text-default); }
.mb-special-tile-help  { font-size: 10.5px; color: var(--text-muted); }
.mb-special-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: .35rem; }

.mb-search {
    width: 100%; padding: .35rem .55rem; font-size: 12.5px;
    margin-bottom: .5rem; border: 1px solid var(--border-strong);
    border-radius: 4px; background: var(--bg-panel); color: var(--text-default);
}
</style>

<div class="mb-grid">
    <section class="mb-tree" id="mb-tree" aria-label="Menu structure"></section>

    <aside class="mb-rail" aria-label="Add items">
        <div class="mb-rail-section">
            <h3>Add custom</h3>
            <div class="mb-special-tiles">
                <div class="mb-special-tile" id="mb-add-custom"
                     title="A free-form link with any URL and label">
                    <div class="mb-special-tile-icon">&#128279;</div>
                    <div class="mb-special-tile-label">Custom link</div>
                    <div class="mb-special-tile-help">URL + label</div>
                </div>
                <div class="mb-special-tile" id="mb-add-holder"
                     title="A non-clickable label that holds child items">
                    <div class="mb-special-tile-icon">&#128193;</div>
                    <div class="mb-special-tile-label">Holder</div>
                    <div class="mb-special-tile-help">non-clickable group</div>
                </div>
            </div>
        </div>

        <div class="mb-rail-section">
            <h3>Internal pages</h3>
            <input type="search" class="mb-search" id="mb-palette-filter" placeholder="Filter…" aria-label="Filter palette items">
            <div id="mb-palette"></div>
        </div>
    </aside>
</div>

<!-- Hidden form for the save submission. -->
<form id="mb-save-form" method="POST" action="/admin/menus/<?= (int) $menu['id'] ?>/save" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="items" id="mb-items-payload" value="">
</form>

<?php include BASE_PATH . '/app/Views/partials/_modal_field_kit.php'; ?>

<script>
/* ============================================================
   Menu Builder: composer-style admin
   ============================================================ */
(function() {
    /* Server-rendered state. linkSources is grouped by source name,
       each carrying a label + items array. items are the existing menu
       rows (flat, with parent_id/sort_order). We rebuild the tree client-side. */
    const LINK_SOURCES = <?= json_encode($linkSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL_ITEMS = <?= json_encode(array_map(function($i) {
        return [
            'id'              => (int) $i['id'],
            'parent_id'       => isset($i['parent_id']) ? (int) $i['parent_id'] : null,
            'label'           => (string) $i['label'],
            'url'             => $i['url'] ?? null,
            'kind'            => (string) ($i['kind'] ?? ($i['url'] === null ? 'holder' : 'link')),
            'icon'            => $i['icon'] ?? '',
            'target'          => (string) ($i['target'] ?? '_self'),
            'visibility'      => (string) ($i['visibility'] ?? 'always'),
            'condition_value' => $i['condition_value'] ?? '',
            'show_on_pages'   => $i['show_on_pages'] ? json_decode($i['show_on_pages'], true) : [],
            'sort_order'      => (int) ($i['sort_order'] ?? 0),
        ];
    }, $items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    /* Client-side state: each item has a stable client_id (used to track
       reorders + parent references during the session). */
    let __cidCounter = 1;
    function nextCid() { return 'c' + (__cidCounter++); }

    /* Convert server items (flat, parent_id by DB id) into our own shape
       with client_ids (and parent_client_id) so the rest of the code
       only deals with one id type. */
    let __items = (function () {
        const idToCid = {};
        const out = INITIAL_ITEMS.map(it => {
            const cid = nextCid();
            idToCid[it.id] = cid;
            return {
                client_id: cid,
                parent_client_id: null,  // resolved in second pass
                label: it.label,
                url: it.url || '',
                kind: it.kind,
                icon: it.icon || '',
                target: it.target,
                visibility: it.visibility,
                condition_value: it.condition_value || '',
                show_on_pages: Array.isArray(it.show_on_pages) ? it.show_on_pages : [],
                _orig_sort: it.sort_order,
                _orig_parent: it.parent_id,
            };
        });
        // Second pass: resolve parent client_ids
        out.forEach((entry, i) => {
            const orig = INITIAL_ITEMS[i];
            if (orig.parent_id != null && idToCid[orig.parent_id]) {
                entry.parent_client_id = idToCid[orig.parent_id];
            }
        });
        // Sort by original sort_order to preserve admin-chosen order
        out.sort((a, b) => (a._orig_sort || 0) - (b._orig_sort || 0));
        return out;
    })();

    /* ---- Tree rendering ---- */
    function renderTree() {
        const tree = document.getElementById('mb-tree');
        tree.innerHTML = '';
        if (__items.length === 0) {
            tree.classList.add('is-empty');
            return;
        }
        tree.classList.remove('is-empty');

        // Group by parent_client_id for hierarchical render
        const childrenOf = {};
        for (const it of __items) {
            const pcid = it.parent_client_id || '__root__';
            (childrenOf[pcid] ||= []).push(it);
        }

        function renderLevel(parentCid, container) {
            const list = childrenOf[parentCid] || [];
            list.forEach(item => {
                const row = document.createElement('div');
                row.className = 'mb-item';
                row.draggable = true;
                row.dataset.cid = item.client_id;
                row.innerHTML = `
                    <span class="mb-drag" title="Drag to reorder">&vellip;&vellip;</span>
                    <span class="mb-kind mb-kind--${escapeAttr(item.kind)}">${escapeHtml(item.kind)}</span>
                    <span class="mb-label">${escapeHtml(item.label || '(unlabeled)')}</span>
                    ${item.url && item.kind === 'link'
                        ? `<span class="mb-url">${escapeHtml(item.url)}</span>` : ''}
                    <div class="mb-actions">
                        <button type="button" data-act="edit" title="Edit">&#9881;</button>
                        <button type="button" class="mb-del" data-act="del" title="Remove">&times;</button>
                    </div>
                `;
                row.addEventListener('click', e => {
                    if (e.target.closest('button')) return;
                    openEditModal(item.client_id);
                });
                row.querySelector('[data-act="edit"]').addEventListener('click', e => {
                    e.stopPropagation();
                    openEditModal(item.client_id);
                });
                row.querySelector('[data-act="del"]').addEventListener('click', e => {
                    e.stopPropagation();
                    if (!confirm('Remove "' + (item.label || '(item)') + '" and any children?')) return;
                    removeItemAndDescendants(item.client_id);
                });
                wireDrag(row);
                container.appendChild(row);

                // Recurse for children
                const kids = document.createElement('div');
                kids.className = 'mb-item-children';
                kids.dataset.parentCid = item.client_id;
                container.appendChild(kids);
                renderLevel(item.client_id, kids);
            });
        }

        renderLevel('__root__', tree);
    }

    function removeItemAndDescendants(cid) {
        // BFS through __items collecting every descendant
        const toRemove = new Set([cid]);
        let added = true;
        while (added) {
            added = false;
            for (const it of __items) {
                if (it.parent_client_id && toRemove.has(it.parent_client_id) && !toRemove.has(it.client_id)) {
                    toRemove.add(it.client_id);
                    added = true;
                }
            }
        }
        __items = __items.filter(it => !toRemove.has(it.client_id));
        renderTree();
    }

    /* ---- Drag-drop reorder + reparent ---- */
    let __draggingCid = null;
    function wireDrag(el) {
        el.addEventListener('dragstart', e => {
            __draggingCid = el.dataset.cid;
            el.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/x-mb-cid', __draggingCid);
        });
        el.addEventListener('dragend', () => {
            __draggingCid = null;
            document.querySelectorAll('.mb-item').forEach(n => n.classList.remove('is-dragging','is-dragover'));
        });
        el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('is-dragover'); });
        el.addEventListener('dragleave', () => el.classList.remove('is-dragover'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            el.classList.remove('is-dragover');
            const fromCid = e.dataTransfer.getData('text/x-mb-cid');
            const toCid   = el.dataset.cid;
            if (!fromCid || fromCid === toCid) return;
            // Don't allow dropping a parent into its own descendant (cycle)
            if (isDescendant(toCid, fromCid)) return;
            // Move fromCid to immediately AFTER toCid, with the same parent.
            const fromIdx = __items.findIndex(it => it.client_id === fromCid);
            if (fromIdx < 0) return;
            const moved = __items.splice(fromIdx, 1)[0];
            const toItem = __items.find(it => it.client_id === toCid);
            moved.parent_client_id = toItem ? toItem.parent_client_id : null;
            const toIdx = __items.findIndex(it => it.client_id === toCid);
            __items.splice(toIdx + 1, 0, moved);
            renderTree();
        });
    }
    function isDescendant(maybeChild, maybeParent) {
        let cur = __items.find(it => it.client_id === maybeChild);
        while (cur && cur.parent_client_id) {
            if (cur.parent_client_id === maybeParent) return true;
            cur = __items.find(it => it.client_id === cur.parent_client_id);
        }
        return false;
    }

    /* ---- Palette: internal pages from LINK_SOURCES ---- */
    function renderPalette() {
        const wrap = document.getElementById('mb-palette');
        const filter = (document.getElementById('mb-palette-filter').value || '').trim().toLowerCase();
        wrap.innerHTML = '';
        for (const [name, group] of Object.entries(LINK_SOURCES)) {
            const matched = (group.items || []).filter(it => {
                if (!filter) return true;
                return (it.label + ' ' + it.url).toLowerCase().includes(filter);
            });
            if (matched.length === 0) continue;
            const h = document.createElement('div');
            h.className = 'mb-rail-cat-header';
            h.textContent = group.label;
            wrap.appendChild(h);
            for (const it of matched) {
                const tile = document.createElement('div');
                tile.className = 'mb-tile';
                tile.title = it.url;
                tile.innerHTML = `
                    <span class="mb-tile-label">${escapeHtml(it.label)}</span>
                    <span class="mb-tile-url">${escapeHtml(it.url)}</span>
                `;
                tile.addEventListener('click', () => addLinkFromSource(it));
                wrap.appendChild(tile);
            }
        }
    }
    document.getElementById('mb-palette-filter').addEventListener('input', renderPalette);

    function addLinkFromSource(srcItem) {
        const item = {
            client_id: nextCid(),
            parent_client_id: null,
            label: srcItem.label,
            url: srcItem.url,
            kind: 'link',
            icon: srcItem.icon || '',
            target: '_self',
            visibility: 'always',
            condition_value: '',
            show_on_pages: [],
        };
        __items.push(item);
        renderTree();
    }

    /* ---- Custom link / Holder add: open modal first with stub ---- */
    document.getElementById('mb-add-custom').addEventListener('click', () => {
        const stub = newStub('link');
        __items.push(stub);
        renderTree();
        openEditModal(stub.client_id, /*isNew=*/true);
    });
    document.getElementById('mb-add-holder').addEventListener('click', () => {
        const stub = newStub('holder');
        __items.push(stub);
        renderTree();
        openEditModal(stub.client_id, /*isNew=*/true);
    });
    function newStub(kind) {
        return {
            client_id: nextCid(),
            parent_client_id: null,
            label: kind === 'holder' ? 'New section' : 'New link',
            url: kind === 'holder' ? '' : '',
            kind,
            icon: '',
            target: '_self',
            visibility: 'always',
            condition_value: '',
            show_on_pages: [],
        };
    }

    /* ---- Per-item edit modal (uses field-kit partial) ---- */
    function settingsSchemaFor(item) {
        const isHolder = item.kind === 'holder';
        const parents = __items
            .filter(it => it.client_id !== item.client_id && !isDescendant(it.client_id, item.client_id))
            .reduce((acc, it) => { acc[it.client_id] = it.label || '(unnamed)'; return acc; }, {'__root__': '— Top level —'});
        const schema = [
            { key: 'label',  label: 'Label',  type: 'text', default: '' },
        ];
        if (!isHolder) {
            schema.push({ key: 'url', label: 'URL', type: 'text', default: '',
                          help: 'Internal /path or absolute https://... .' });
            schema.push({ key: 'icon',   label: 'Icon (optional emoji or class)', type: 'text', default: '' });
            schema.push({ key: 'target', label: 'Open in', type: 'select', default: '_self',
                          options: { '_self': 'Same window', '_blank': 'New tab' } });
        }
        schema.push({ key: 'parent_client_id', label: 'Parent', type: 'select', default: '__root__', options: parents });
        schema.push({ key: 'visibility', label: 'Visibility', type: 'select', default: 'always',
                      options: {
                          'always':     'Always',
                          'logged_in':  'Logged in only',
                          'logged_out': 'Guests only',
                          'role':       'Role-gated',
                          'permission': 'Permission-gated',
                          'group':      'Group-gated',
                      }});
        schema.push({ key: 'condition_value', label: 'Visibility condition value', type: 'text', default: '',
                      help: 'Role/permission/group slug. Only applies for role/permission/group visibility.' });
        return schema;
    }
    function openEditModal(cid, isNew) {
        const item = __items.find(it => it.client_id === cid);
        if (!item) return;
        const schema = settingsSchemaFor(item);
        const flat = {
            label: item.label,
            url: item.url,
            icon: item.icon,
            target: item.target,
            parent_client_id: item.parent_client_id || '__root__',
            visibility: item.visibility,
            condition_value: item.condition_value,
        };
        resetRepeaterState();
        document.getElementById('cb-modal-title').textContent =
            (isNew ? 'New ' : 'Edit ') + (item.kind === 'holder' ? 'holder' : 'link');
        let html = '';
        for (const f of schema) html += renderSchemaField(f, flat, '');
        document.getElementById('cb-modal-body').innerHTML = html;
        document.getElementById('cb-modal').classList.add('open');
        document.getElementById('cb-modal-save').onclick = () => saveEditModal(cid);
    }
    function saveEditModal(cid) {
        const item = __items.find(it => it.client_id === cid);
        if (!item) return;
        const schema = settingsSchemaFor(item);
        const next = {};
        for (const f of schema) {
            const v = readModalField(f, '');
            if (v && typeof v === 'object' && '__error' in v) { alert(v.__error); return; }
            next[f.key] = v;
        }
        item.label  = String(next.label  || '').trim();
        if (item.kind !== 'holder') {
            item.url    = String(next.url    || '').trim();
            item.icon   = String(next.icon   || '').trim();
            item.target = next.target || '_self';
        }
        item.parent_client_id = next.parent_client_id === '__root__' ? null : next.parent_client_id;
        item.visibility       = next.visibility || 'always';
        item.condition_value  = String(next.condition_value || '').trim();

        document.getElementById('cb-modal').classList.remove('open');
        renderTree();
    }

    /* ---- Save (POST whole tree) ---- */
    document.getElementById('mb-save').addEventListener('click', () => {
        // Serialise minimal payload - server only needs what replaceItems() reads
        const payload = __items.map(it => ({
            client_id:        it.client_id,
            parent_client_id: it.parent_client_id,
            label:            it.label,
            url:              it.kind === 'holder' ? null : (it.url || null),
            kind:             it.kind,
            icon:             it.icon || null,
            target:           it.target || '_self',
            visibility:       it.visibility || 'always',
            condition_value:  it.condition_value || null,
            show_on_pages:    it.show_on_pages || [],
        }));
        document.getElementById('mb-items-payload').value = JSON.stringify(payload);
        document.getElementById('mb-save-form').requestSubmit();
    });

    /* ---- Init ---- */
    renderTree();
    renderPalette();
})();
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
