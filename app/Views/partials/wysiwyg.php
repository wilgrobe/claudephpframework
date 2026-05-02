<?php
/**
 * WYSIWYG rich-text editor partial.
 *
 * A contenteditable div mirrored into a hidden textarea that is what the
 * form actually submits. Toolbar maps one-to-one onto the framework's
 * server-side sanitizer allow-list (p, strong, em, u, ul, ol, h2, h3, h4,
 * blockquote, a). Users can toggle to "raw HTML" mode to paste markup;
 * either way, whatever saves the form must run the body through
 * Validator::sanitizeHtml() — anything outside the allow-list is dropped.
 *
 * Usage:
 *   <?php
 *   $wy_name        = 'body';            // textarea name attribute
 *   $wy_value       = $page['body'] ?? ''; // initial HTML
 *   $wy_rows        = 16;                // rows when raw mode is toggled on
 *   $wy_scope       = 'page';            // id suffix so multiple editors on
 *                                        // one page don't collide
 *   $wy_placeholder = 'Start writing...';
 *   $wy_label       = 'Content';         // optional — shows next to the
 *                                        // "Rich editor" toggle so you
 *                                        // don't have to render a separate
 *                                        // <label> above the partial
 *   include BASE_PATH . '/app/Views/partials/wysiwyg.php';
 *   ?>
 *
 * Parameters default to sensible values if not set, so minimum usage is:
 *   <?php $wy_name = 'body'; $wy_value = $x['body'] ?? ''; include ...; ?>
 */
$wy_name        = $wy_name        ?? 'body';
$wy_value       = $wy_value       ?? '';
$wy_rows        = (int)($wy_rows  ?? 16);
$wy_scope       = $wy_scope       ?? 'main';
$wy_placeholder = $wy_placeholder ?? 'Start writing...';
$wy_label       = $wy_label       ?? null;

// Scope DOM ids so two instances on the same page work side by side.
$__wy_wrap   = 'wysiwyg-wrap-'     . $wy_scope;
$__wy_bar    = 'wysiwyg-toolbar-'  . $wy_scope;
$__wy_edit   = 'wysiwyg-editor-'   . $wy_scope;
$__wy_ta     = 'wysiwyg-textarea-' . $wy_scope;
$__wy_toggle = 'wysiwyg-toggle-'   . $wy_scope;
?>

<div style="display:flex;align-items:center;justify-content:<?= $wy_label !== null ? 'space-between' : 'flex-end' ?>;margin-bottom:.4rem">
    <?php if ($wy_label !== null): ?>
    <label style="margin:0"><?= e($wy_label) ?></label>
    <?php endif; ?>
    <label style="display:flex;align-items:center;gap:.4rem;font-size:12.5px;color:#6b7280;cursor:pointer;font-weight:normal">
        <input type="checkbox" id="<?= $__wy_toggle ?>" checked style="margin:0">
        Rich editor
    </label>
</div>

<div id="<?= $__wy_wrap ?>" style="border:1px solid #d1d5db;border-radius:6px;background:#fff;overflow:hidden">
    <div id="<?= $__wy_bar ?>" style="display:flex;flex-wrap:wrap;gap:.2rem;padding:.35rem .5rem;border-bottom:1px solid #e5e7eb;background:#f9fafb">
        <button type="button" class="wy-btn" data-cmd="bold"      title="Bold (Ctrl+B)"><b>B</b></button>
        <button type="button" class="wy-btn" data-cmd="italic"    title="Italic (Ctrl+I)"><i>I</i></button>
        <button type="button" class="wy-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        <span class="wy-sep"></span>
        <button type="button" class="wy-btn" data-block="h2" title="Heading 2">H2</button>
        <button type="button" class="wy-btn" data-block="h3" title="Heading 3">H3</button>
        <button type="button" class="wy-btn" data-block="h4" title="Heading 4">H4</button>
        <button type="button" class="wy-btn" data-block="p"  title="Paragraph">P</button>
        <span class="wy-sep"></span>
        <button type="button" class="wy-btn" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
        <button type="button" class="wy-btn" data-cmd="insertOrderedList"  title="Numbered list">1. List</button>
        <button type="button" class="wy-btn" data-block="blockquote" title="Quote">❝ Quote</button>
        <span class="wy-sep"></span>
        <button type="button" class="wy-btn" data-action="link"   title="Insert link">🔗 Link</button>
        <button type="button" class="wy-btn" data-action="unlink" title="Remove link">Unlink</button>
        <span class="wy-sep"></span>
        <button type="button" class="wy-btn" data-action="clear" title="Clear formatting">✕ Clear</button>
    </div>
    <div id="<?= $__wy_edit ?>" class="wy-editor" contenteditable="true" spellcheck="true"
         style="min-height:360px;padding:1rem 1.1rem;font-size:15px;line-height:1.7;outline:none"></div>
</div>

<!-- The real form field. Rich mode hides it; raw HTML mode shows it. Either
     way, its .value is what the form submits, so sync carefully on edit. -->
<textarea name="<?= e($wy_name) ?>" id="<?= $__wy_ta ?>" class="form-control" aria-label="Rich-text editor source" rows="<?= $wy_rows ?>"
          style="display:none;margin-top:.4rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"><?= htmlspecialchars($wy_value, ENT_QUOTES) ?></textarea>

<style>
/* Styles are class-based so two editors on one page share one rule set.
   If this partial is included more than once the block duplicates, but
   the rules are identical so there's no conflict. */
.wy-btn {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 4px;
    padding: .25rem .55rem; font-size: 12.5px; cursor: pointer;
    color: #374151; line-height: 1.2; min-width: 28px;
}
.wy-btn:hover  { background: #eef2ff; border-color: #c7d2fe; color: #4338ca; }
.wy-btn:active { transform: translateY(1px); }
.wy-btn.active { background: #eef2ff; border-color: #6366f1; color: #4338ca; }
.wy-sep        { display: inline-block; width: 1px; background: #e5e7eb; margin: 0 .2rem; align-self: stretch; }
.wy-editor h2  { font-size: 1.4rem;  margin: .9rem 0 .4rem;  font-weight: 700; }
.wy-editor h3  { font-size: 1.2rem;  margin: .8rem 0 .35rem; font-weight: 700; }
.wy-editor h4  { font-size: 1.05rem; margin: .75rem 0 .3rem; font-weight: 600; }
.wy-editor p   { margin: 0 0 .75rem; }
.wy-editor ul, .wy-editor ol { margin: 0 0 .75rem 1.5rem; }
.wy-editor blockquote {
    margin: .75rem 0; padding: .5rem 1rem; border-left: 3px solid #c7d2fe;
    color: #4b5563; background: #f9fafb;
}
.wy-editor a           { color: #4f46e5; text-decoration: underline; }
.wy-editor:focus       { outline: none; }
.wy-editor:empty::before { content: attr(data-placeholder); color: #9ca3af; }
</style>

<script>
/* IIFE per instance. Captures this partial's scoped element ids and wires
   them up in isolation from any other editor on the page. The real form
   field is the hidden textarea — the contenteditable just mirrors its
   HTML on every keystroke so the submitted value always matches what
   the user sees. */
(function () {
    const editor   = document.getElementById(<?= json_encode($__wy_edit, JSON_UNESCAPED_SLASHES) ?>);
    const textarea = document.getElementById(<?= json_encode($__wy_ta,   JSON_UNESCAPED_SLASHES) ?>);
    const wrap     = document.getElementById(<?= json_encode($__wy_wrap, JSON_UNESCAPED_SLASHES) ?>);
    const toggle   = document.getElementById(<?= json_encode($__wy_toggle, JSON_UNESCAPED_SLASHES) ?>);
    const toolbar  = document.getElementById(<?= json_encode($__wy_bar,  JSON_UNESCAPED_SLASHES) ?>);
    if (!editor || !textarea) return;

    editor.dataset.placeholder = <?= json_encode($wy_placeholder, JSON_UNESCAPED_SLASHES) ?>;
    editor.innerHTML = textarea.value;

    const syncToTextarea = () => { textarea.value = editor.innerHTML; };
    editor.addEventListener('input', syncToTextarea);

    toolbar.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        editor.focus();

        if (btn.dataset.cmd) {
            document.execCommand(btn.dataset.cmd, false, null);
        } else if (btn.dataset.block) {
            document.execCommand('formatBlock', false, btn.dataset.block);
        } else if (btn.dataset.action === 'link') {
            const url = prompt('Link URL (http/https only):');
            if (url && /^https?:\/\//i.test(url)) {
                document.execCommand('createLink', false, url);
            } else if (url) {
                alert('Only http:// and https:// links are allowed.');
            }
        } else if (btn.dataset.action === 'unlink') {
            document.execCommand('unlink', false, null);
        } else if (btn.dataset.action === 'clear') {
            document.execCommand('removeFormat', false, null);
            // Put the caret back into a paragraph rather than a floating inline.
            document.execCommand('formatBlock', false, 'p');
        }
        syncToTextarea();
        updateActiveButtons();
    });

    function updateActiveButtons() {
        toolbar.querySelectorAll('.wy-btn[data-cmd]').forEach(b => {
            try {
                b.classList.toggle('active', document.queryCommandState(b.dataset.cmd));
            } catch (_) { /* queryCommandState throws on some commands in some browsers */ }
        });
    }
    editor.addEventListener('keyup',   updateActiveButtons);
    editor.addEventListener('mouseup', updateActiveButtons);

    // Ctrl/Cmd + B/I/U — execCommand handles these natively on
    // contenteditable; we just need to sync the textarea afterwards.
    editor.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && ['b','i','u'].includes(e.key.toLowerCase())) {
            setTimeout(syncToTextarea, 0);
        }
    });

    // Toggle between rich and raw. Pull the latest HTML from whichever
    // side the user was editing so nothing is lost across the swap.
    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            editor.innerHTML = textarea.value;
            wrap.style.display     = '';
            textarea.style.display = 'none';
        } else {
            textarea.value = editor.innerHTML;
            wrap.style.display     = 'none';
            textarea.style.display = '';
        }
    });

    // Paste: strip formatting so Word/Google Docs clipboard markup doesn't
    // sneak in tags the sanitizer will silently delete on save.
    editor.addEventListener('paste', (e) => {
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        if (text) {
            e.preventDefault();
            document.execCommand('insertText', false, text);
        }
    });

    // Belt-and-suspenders: sync once more right before submit, in case
    // an input event was missed.
    const form = editor.closest('form');
    form?.addEventListener('submit', syncToTextarea);
})();
</script>
