<?php
// app/Views/partials/_modal_field_kit.php
//
// Shared "field-kit" for any admin view that needs a click-to-configure
// settings modal driven by a field's settingsSchema. Originally extracted
// from modules/pages/Views/admin/form.php so the forms-module visual
// builder could reuse the same mechanics.
//
// What's in here (provided to the calling page):
//   CSS for .cb-modal-* (the modal shell) and .cb-rep-* (repeater +
//          string-list editors)
//   JS:
//     - escapeHtml(s), escapeAttr(s)
//     - renderSchemaField(f, settings, prefix)
//     - buildRepeaterItemHTML(repField, itemValue)
//     - buildStringListItemHTML(value)
//     - readModalField(f, prefix)
//     - readRepeaterValue(f, prefix)
//     - readStringListValue(f, prefix)
//     - handleRepClick(e)  -- delegated click handler for repeater controls
//     - resetRepeaterState() -- call on every modal open
//     - nextUid()
//     - window.__repSchemaRegistry, window.__uidCounter
//
//   The modal HTML wrapper itself: a single #cb-modal-backdrop with an
//   empty title + body. Consumers set the title and innerHTML when they
//   open it.
//
// What's NOT in here (consumer-specific):
//   - The state holding the values being edited (placements vs form fields)
//   - The open-modal entry points and the save handler
//   - Any rendering outside the modal (palette, grid, list, etc.)
//
// Usage:
//   <?php include BASE_PATH . '/app/Views/partials/_modal_field_kit.php'; ?>
//   ...your view...
//
// Then in your view's JS:
//   resetRepeaterState();           // before each modal open
//   const html = renderSchemaField({...field}, currentSettings, '');
//   document.getElementById('cb-modal-body').innerHTML = html;
//   document.getElementById('cb-modal-title').textContent = 'My title';
//   document.getElementById('cb-modal').classList.add('open');
//
//   // On save, walk the schema:
//   for (const f of schema) {
//     const v = readModalField(f, '');
//     if (v && typeof v === 'object' && '__error' in v) { alert(v.__error); return; }
//     // ... assign to your state ...
//   }
?>
<style>
.cb-modal-backdrop {
    position: fixed; inset: 0; background: rgba(17,24,39,.5);
    display: none; align-items: flex-start; justify-content: center;
    z-index: 1000; padding: 4rem 1rem;
}
.cb-modal-backdrop.open { display: flex; }
.cb-modal {
    background: var(--bg-panel); border-radius: 12px; box-shadow: 0 20px 50px -10px rgba(0,0,0,.3);
    width: 100%; max-width: 640px; max-height: 80vh; display: flex; flex-direction: column;
}
.cb-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-subtle);
                   display: flex; align-items: center; justify-content: space-between; }
.cb-modal-header h2 { margin: 0; font-size: 1rem; font-weight: 600; }
.cb-modal-header .close { background: none; border: none; font-size: 1.4rem; color: var(--text-muted); cursor: pointer; line-height: 1; }
.cb-modal-body   { padding: 1rem 1.25rem; overflow-y: auto; }
.cb-modal-footer { padding: .75rem 1.25rem; border-top: 1px solid var(--border-subtle); display: flex; gap: .5rem; justify-content: flex-end; background: #f9fafb; }
.cb-modal-help   { font-size: 12px; color: var(--text-muted); margin-top: .15rem; }
.cb-modal .form-group { margin-bottom: .85rem; }

/* Repeater fields - array-of-objects + string-list editors. */
.cb-rep-container {
    border: 1px solid var(--border-default); border-radius: 8px;
    padding: .55rem; background: #f9fafb;
}
.cb-rep-items { display: flex; flex-direction: column; gap: .5rem; margin-bottom: .55rem; counter-reset: rep-item; }
.cb-rep-items:empty { margin-bottom: 0; }
.cb-rep-items:empty::before {
    content: 'No items yet. Click "+ Add" below.';
    display: block; padding: .75rem .35rem; color: var(--text-subtle);
    font-size: 12.5px; font-style: italic; text-align: center;
}
.cb-rep-item {
    background: var(--bg-panel); border: 1px solid var(--border-default); border-radius: 6px;
    counter-increment: rep-item;
}
.cb-rep-item-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: .35rem .6rem; background: var(--border-subtle);
    border-bottom: 1px solid var(--border-default); border-radius: 6px 6px 0 0;
}
.cb-rep-item-title {
    font-size: 11.5px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .05em;
}
.cb-rep-item-title::after { content: ' #' counter(rep-item); color: var(--text-subtle); font-weight: 500; }
.cb-rep-item-actions { display: flex; gap: .25rem; }
.cb-rep-item-actions button {
    background: var(--bg-panel); border: 1px solid var(--border-strong); border-radius: 4px;
    padding: .15rem .4rem; font-size: 11.5px; line-height: 1; cursor: pointer;
    color: var(--text-muted); min-width: 24px;
}
.cb-rep-item-actions button:hover { color: var(--color-primary); border-color: var(--color-primary); }
.cb-rep-item-actions button[data-act="del"]:hover { color: #dc2626; border-color: #dc2626; }
.cb-rep-item-body { padding: .6rem; }
.cb-rep-item-body .form-group { margin-bottom: .55rem; }
.cb-rep-item-body .form-group:last-child { margin-bottom: 0; }
.cb-rep-item-body .cb-rep-container { background: #f9fafb; padding: .45rem; }
.cb-rep-item.cb-strlist-item {
    display: flex; align-items: center; gap: .35rem;
    padding: .3rem .45rem; background: var(--bg-panel);
}
.cb-rep-item.cb-strlist-item input {
    flex: 1; padding: .3rem .55rem; font-size: 13px;
    border: 1px solid var(--border-strong); border-radius: 4px;
}
.cb-rep-item.cb-strlist-item input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(79,70,229,.15); }
.cb-rep-add { font-size: 12.5px; padding: .35rem .7rem; }
</style>

<!-- Modal shell. Consumers populate the title + body before flipping .open. -->
<div id="cb-modal" class="cb-modal-backdrop" role="dialog" aria-modal="true">
    <div class="cb-modal" role="document">
        <header class="cb-modal-header">
            <h2 id="cb-modal-title">Settings</h2>
            <button type="button" class="close" data-modal-close="1" aria-label="Close">&times;</button>
        </header>
        <div class="cb-modal-body" id="cb-modal-body"></div>
        <footer class="cb-modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-modal-close="1">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="cb-modal-save">Save</button>
        </footer>
    </div>
</div>

<script>
/* Field-kit: shared rendering primitives for settingsSchema-driven modals.
   Loaded once per page; consumer scripts call into these functions. */
(function() {
    if (window.__fieldKitLoaded) return;
    window.__fieldKitLoaded = true;

    /* Globally-unique ids for repeater items. Reset on every modal open. */
    window.__uidCounter = 1;
    window.nextUid = function() { return 'r' + (window.__uidCounter++); };

    /* repId (cb-rep-XXX) -> field descriptor. The "+ Add" handler reads
       this to know what kind of item to insert. */
    window.__repSchemaRegistry = {};

    window.resetRepeaterState = function() {
        window.__uidCounter = 1;
        for (const k of Object.keys(window.__repSchemaRegistry)) delete window.__repSchemaRegistry[k];
    };

    window.escapeHtml = function(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    window.escapeAttr = function(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    };

    window.renderSchemaField = function(f, settings, prefix) {
        const key   = f.key;
        const idKey = (prefix || '') + key;
        const label = escapeHtml(f.label || key);
        const help  = f.help ? `<div class="cb-modal-help">${escapeHtml(f.help)}</div>` : '';
        const def   = (settings && Object.prototype.hasOwnProperty.call(settings, key)) ? settings[key] : f.default;
        const id    = `cb-field-${idKey}`;

        if (f.type === 'checkbox') {
            return `
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:13.5px;cursor:pointer">
                        <input type="checkbox" id="${id}" data-key="${escapeAttr(key)}" data-type="checkbox" ${def ? 'checked' : ''}>
                        ${label}
                    </label>
                    ${help}
                </div>`;
        }
        if (f.type === 'select') {
            const opts = f.options || {};
            let optHtml = '';
            for (const [v, lbl] of Object.entries(opts)) {
                optHtml += `<option value="${escapeAttr(v)}"${String(v) === String(def) ? ' selected' : ''}>${escapeHtml(lbl)}</option>`;
            }
            return `
                <div class="form-group">
                    <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    <select id="${id}" class="form-control" data-key="${escapeAttr(key)}" data-type="select">${optHtml}</select>
                    ${help}
                </div>`;
        }
        if (f.type === 'textarea') {
            return `
                <div class="form-group">
                    <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    <textarea id="${id}" class="form-control" rows="4" data-key="${escapeAttr(key)}" data-type="textarea"
                              ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}
                    >${escapeHtml(String(def == null ? '' : def))}</textarea>
                    ${help}
                </div>`;
        }
        if (f.type === 'json') {
            const val = (def == null) ? '' : JSON.stringify(def, null, 2);
            return `
                <div class="form-group">
                    <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    <textarea id="${id}" class="form-control" rows="8" data-key="${escapeAttr(key)}" data-type="json"
                              style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                    >${escapeHtml(val)}</textarea>
                    ${help}
                </div>`;
        }
        if (f.type === 'number') {
            return `
                <div class="form-group">
                    <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    <input id="${id}" type="number" class="form-control" data-key="${escapeAttr(key)}" data-type="number"
                           value="${escapeAttr(String(def == null ? '' : def))}"
                           ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}>
                    ${help}
                </div>`;
        }
        if (f.type === 'repeater') {
            const repId = `cb-rep-${idKey}`;
            window.__repSchemaRegistry[repId] = f;
            const items = Array.isArray(def) ? def : [];
            let itemsHtml = '';
            for (const itemVal of items) itemsHtml += buildRepeaterItemHTML(f, itemVal);
            const addLabel = escapeHtml(f.item_label || 'item');
            return `
                <div class="form-group">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    ${help}
                    <div class="cb-rep-container" id="${repId}" data-key="${escapeAttr(key)}" data-list-type="object">
                        <div class="cb-rep-items">${itemsHtml}</div>
                        <button type="button" class="btn btn-sm btn-secondary cb-rep-add" data-act="add" data-rep="${repId}">+ Add ${addLabel}</button>
                    </div>
                </div>`;
        }
        if (f.type === 'string_list') {
            const repId = `cb-rep-${idKey}`;
            window.__repSchemaRegistry[repId] = f;
            const items = Array.isArray(def) ? def : [];
            let itemsHtml = '';
            for (const v of items) itemsHtml += buildStringListItemHTML(String(v == null ? '' : v));
            const addLabel = escapeHtml(f.item_label || 'line');
            return `
                <div class="form-group">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                    ${help}
                    <div class="cb-rep-container" id="${repId}" data-key="${escapeAttr(key)}" data-list-type="string">
                        <div class="cb-rep-items">${itemsHtml}</div>
                        <button type="button" class="btn btn-sm btn-secondary cb-rep-add" data-act="add" data-rep="${repId}">+ Add ${addLabel}</button>
                    </div>
                </div>`;
        }
        // text fallback
        return `
            <div class="form-group">
                <label for="${id}" style="display:block;font-weight:600;font-size:13px;margin-bottom:.25rem">${label}</label>
                <input id="${id}" type="text" class="form-control" data-key="${escapeAttr(key)}" data-type="text"
                       value="${escapeAttr(String(def == null ? '' : def))}"
                       ${f.placeholder ? `placeholder="${escapeAttr(f.placeholder)}"` : ''}>
                ${help}
            </div>`;
    };

    window.buildRepeaterItemHTML = function(repField, itemValue) {
        const uid = nextUid();
        const subs = Array.isArray(repField.item_schema) ? repField.item_schema : [];
        let bodyHtml = '';
        for (const sub of subs) {
            bodyHtml += renderSchemaField(sub, itemValue || {}, uid + '-');
        }
        const titleLabel = escapeHtml(repField.item_label || 'Item');
        return `
            <div class="cb-rep-item" data-uid="${uid}">
                <div class="cb-rep-item-header">
                    <span class="cb-rep-item-title">${titleLabel}</span>
                    <div class="cb-rep-item-actions">
                        <button type="button" data-act="up"   title="Move up">&uarr;</button>
                        <button type="button" data-act="down" title="Move down">&darr;</button>
                        <button type="button" data-act="del"  title="Remove">&times;</button>
                    </div>
                </div>
                <div class="cb-rep-item-body">${bodyHtml}</div>
            </div>`;
    };

    window.buildStringListItemHTML = function(value) {
        const uid = nextUid();
        return `
            <div class="cb-rep-item cb-strlist-item" data-uid="${uid}">
                <input type="text" id="cb-field-${uid}-__line" data-key="__line" value="${escapeAttr(value)}" aria-label="List item">
                <div class="cb-rep-item-actions">
                    <button type="button" data-act="up"   title="Move up">&uarr;</button>
                    <button type="button" data-act="down" title="Move down">&darr;</button>
                    <button type="button" data-act="del"  title="Remove">&times;</button>
                </div>
            </div>`;
    };

    window.readModalField = function(f, prefix) {
        const t = f.type || 'text';
        if (t === 'repeater')    return readRepeaterValue(f, prefix);
        if (t === 'string_list') return readStringListValue(f, prefix);

        const id = 'cb-field-' + (prefix || '') + f.key;
        const el = document.getElementById(id);
        if (!el) return undefined;
        if (t === 'checkbox') return el.checked;
        if (t === 'number')   return el.value === '' ? null : Number(el.value);
        if (t === 'json') {
            if (el.value.trim() === '') return f.default == null ? null : f.default;
            try { return JSON.parse(el.value); }
            catch (err) { return { __error: `Invalid JSON in "${f.label}": ` + err.message }; }
        }
        return el.value;
    };

    window.readRepeaterValue = function(f, prefix) {
        const repId = `cb-rep-${prefix || ''}${f.key}`;
        const container = document.getElementById(repId);
        if (!container) return f.default == null ? [] : f.default;
        const items = container.querySelectorAll(':scope > .cb-rep-items > .cb-rep-item');
        const out = [];
        for (const item of items) {
            const uid = item.dataset.uid;
            const obj = {};
            const subs = Array.isArray(f.item_schema) ? f.item_schema : [];
            for (const sub of subs) {
                const v = readModalField(sub, uid + '-');
                if (v && typeof v === 'object' && '__error' in v) return v;
                if (v !== undefined) obj[sub.key] = v;
            }
            out.push(obj);
        }
        return out;
    };

    window.readStringListValue = function(f, prefix) {
        const repId = `cb-rep-${prefix || ''}${f.key}`;
        const container = document.getElementById(repId);
        if (!container) return f.default == null ? [] : f.default;
        const inputs = container.querySelectorAll(':scope > .cb-rep-items > .cb-rep-item input[data-key="__line"]');
        const out = [];
        for (const inp of inputs) {
            const v = (inp.value || '').trim();
            if (v !== '') out.push(v);
        }
        return out;
    };

    /* Delegated click handler for repeater controls (add/del/up/down).
       Wired once at module load against the modal body. */
    window.handleRepClick = function(e) {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        if (!btn.closest('#cb-modal-body')) return;
        const act = btn.dataset.act;

        if (act === 'add') {
            e.preventDefault();
            const repId = btn.dataset.rep;
            const f = window.__repSchemaRegistry[repId];
            if (!f) return;
            const itemsWrap = document.querySelector(`#${CSS.escape(repId)} > .cb-rep-items`);
            if (!itemsWrap) return;
            const html = (f.type === 'string_list')
                ? buildStringListItemHTML('')
                : buildRepeaterItemHTML(f, {});
            itemsWrap.insertAdjacentHTML('beforeend', html);
            return;
        }

        const item = btn.closest('.cb-rep-item');
        if (!item) return;

        if (act === 'del') {
            e.preventDefault();
            item.remove();
            return;
        }
        if (act === 'up') {
            e.preventDefault();
            const prev = item.previousElementSibling;
            if (prev) prev.before(item);
            return;
        }
        if (act === 'down') {
            e.preventDefault();
            const next = item.nextElementSibling;
            if (next) next.after(item);
            return;
        }
    };

    /* Wire delegations once the modal exists in the DOM. */
    function init() {
        const body = document.getElementById('cb-modal-body');
        if (body) body.addEventListener('click', window.handleRepClick);

        // Default close behavior: any button with data-modal-close + the
        // backdrop click + Escape. Consumers can override by listening
        // first; this provides a sensible default so a forgetful consumer
        // still gets a closeable modal.
        const backdrop = document.getElementById('cb-modal');
        if (backdrop) {
            backdrop.addEventListener('click', (e) => {
                if (e.target.id === 'cb-modal' || e.target.dataset.modalClose === '1') {
                    backdrop.classList.remove('open');
                }
            });
        }
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && backdrop && backdrop.classList.contains('open')) {
                backdrop.classList.remove('open');
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
