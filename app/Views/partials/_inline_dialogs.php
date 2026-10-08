<?php
/*
 * Inline replacements for the browser's blocking dialogs (board #421) — never its prompt / confirm / alert boxes:
 * they freeze the page, cannot be themed, and the custom-module validator rejects them. ONE copy for the whole
 * platform (custom modules ship their own `Views/_inline_dialogs.php` with the same API; whichever loads first wins).
 *
 * Included by the page shells (layout/footer.php, public/page.php) and by every view that calls moDialog itself, so
 * it is there on the standalone auth pages too. Prints once per request however often it is included.
 *
 *   <form data-confirm="Delete this?">         the framework's hook: asks inline beside the submit button first
 *   <form|button|a data-mo-confirm="…">        the same, and for a click outside a form
 *   <form data-mo-ask="New name" data-mo-ask-field="name" data-mo-ask-value="Old">  asks for a value inline first
 *        (add data-mo-ask-optional to let an empty answer through; Cancel always submits nothing)
 *   moDialog.confirm(anchorEl, 'Delete this?', onYes)        inline "Are you sure? [Yes] [Cancel]" beside anchorEl
 *   await moDialog.confirmed('Delete this?')                 the same as a promise, beside the clicked control
 *   moDialog.notice(anchorEl|null, 'Saved.', isError)        an inline status line (near anchorEl, or a toast)
 *   moDialog.ask(anchorEl, 'Label', 'default', onValue, {type:'url', onCancel: fn})   an inline field + OK/Cancel
 *
 * Every message is set as TEXT (never HTML), so a value that came from the database is safe in one.
 */
if (!empty($GLOBALS['__cpf_inline_dialogs'])) return;
$GLOBALS['__cpf_inline_dialogs'] = true;
?>
<style>
.mo-ask{display:inline-flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin:.25rem 0;padding:.35rem .6rem;border-radius:6px;background:var(--color-warning-bg,#fff7e6);color:var(--color-warning-fg,#7a4b00);font-size:.85rem;line-height:1.4;max-width:100%}
.mo-ask .mo-msg{white-space:pre-line}
.mo-ask button{font:inherit;font-size:.8rem;padding:.2rem .65rem;border-radius:5px;border:1px solid currentColor;background:transparent;color:inherit;cursor:pointer}
.mo-ask button.mo-yes{background:var(--color-danger-fg,#b42318);border-color:var(--color-danger-fg,#b42318);color:#fff}
.mo-ask button.mo-ok{background:var(--color-primary,#2563eb);border-color:var(--color-primary,#2563eb);color:#fff}
.mo-ask input{font:inherit;font-size:.85rem;padding:.2rem .4rem;border:1px solid var(--color-gray-300,#ccc);border-radius:4px;min-width:10rem}
.mo-note{display:block;margin:.35rem 0;padding:.4rem .65rem;border-radius:6px;font-size:.85rem;line-height:1.4;white-space:pre-line;background:var(--color-success-bg,#ecfdf3);color:var(--color-success-fg,#05603a)}
.mo-note.mo-err{background:var(--color-danger-bg,#fef3f2);color:var(--color-danger-fg,#b42318)}
#mo-toasts{position:fixed;top:1rem;right:1rem;z-index:10000;display:flex;flex-direction:column;gap:.4rem;max-width:min(26rem,calc(100vw - 2rem))}
#mo-toasts .mo-note,#mo-toasts .mo-ask{margin:0;box-shadow:0 4px 14px rgba(0,0,0,.12)}
</style>
<script>
(function () {
  if (window.moDialog) return;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = String(text); return e; }
  function toasts() {
    var t = document.getElementById('mo-toasts');
    if (!t) { t = el('div'); t.id = 'mo-toasts'; document.body.appendChild(t); }
    return t;
  }
  // Where an inline question goes: right after the control, which is hidden while the question is open. With no
  // control (a keyboard shortcut, a background task) it goes where the toasts go — fixed, so it is always seen.
  // Clicks inside it stop there: a row or card under the control must not also react to Yes / Cancel.
  function place(anchor, node) {
    node.addEventListener('click', function (e) { e.stopPropagation(); });
    if (anchor && anchor.parentNode) { anchor.insertAdjacentElement('afterend', node); anchor.hidden = true; return; }
    toasts().appendChild(node);
  }
  function close(anchor, node) { if (node && node.parentNode) node.parentNode.removeChild(node); if (anchor) anchor.hidden = false; }

  function confirmInline(anchor, message, onYes, opts) {
    opts = opts || {};
    if (anchor && anchor._moOpen) return;
    var box = el('span', 'mo-ask'); box.setAttribute('role', 'alertdialog');
    box.appendChild(el('span', 'mo-msg', message || 'Are you sure?'));
    var yes = el('button', 'mo-yes', opts.yes || 'Yes'); yes.type = 'button';
    var no = el('button', 'mo-no', opts.no || 'Cancel'); no.type = 'button';
    box.appendChild(yes); box.appendChild(no);
    if (anchor) anchor._moOpen = true;
    function done() { if (anchor) anchor._moOpen = false; close(anchor, box); }
    yes.addEventListener('click', function () { done(); onYes(); });
    no.addEventListener('click', function () { done(); if (opts.onNo) opts.onNo(); if (anchor && anchor.focus) anchor.focus(); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); no.click(); } });
    place(anchor, box);
    no.focus();
  }

  // `if (!(await moDialog.confirmed('Delete?'))) return;` — the same question as a promise (true = Yes). With no
  // anchor it sits beside the control whose click is being handled (read now, so call it before any await).
  function confirmed(message, anchor) {
    if (anchor === undefined) { var t = window.event && window.event.currentTarget; anchor = (t && t.nodeType === 1) ? t : null; }
    return new Promise(function (res) { confirmInline(anchor, message, function () { res(true); }, { onNo: function () { res(false); } }); });
  }

  function notice(anchor, message, isError) {
    var n = el('div', 'mo-note' + (isError ? ' mo-err' : ''), message);
    n.setAttribute('role', isError ? 'alert' : 'status');
    if (anchor && anchor.parentNode) {
      var host = anchor.closest ? (anchor.closest('form,td,li,.card,section') || anchor) : anchor;
      var prev = host.parentNode && host.nextElementSibling && host.nextElementSibling.classList && host.nextElementSibling.classList.contains('mo-note') ? host.nextElementSibling : null;
      if (prev) prev.parentNode.removeChild(prev);
      host.insertAdjacentElement('afterend', n);
    } else {
      toasts().appendChild(n);
    }
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, isError ? 9000 : 4500);
    return n;
  }

  function ask(anchor, label, value, onValue, opts) {
    opts = opts || {};
    if (anchor && anchor._moOpen) return;
    var box = el('span', 'mo-ask'); box.setAttribute('role', 'dialog');
    var lab = el('label', 'mo-msg', label);
    var input = el('input'); input.type = opts.type || 'text'; if (value != null) input.value = value;
    lab.appendChild(document.createTextNode(' ')); lab.appendChild(input);
    var ok = el('button', 'mo-ok', opts.ok || 'OK'); ok.type = 'button';
    var no = el('button', 'mo-no', 'Cancel'); no.type = 'button';
    box.appendChild(lab); box.appendChild(ok); box.appendChild(no);
    if (anchor) anchor._moOpen = true;
    function done() { if (anchor) anchor._moOpen = false; close(anchor, box); }
    ok.addEventListener('click', function () { var v = input.value; if (opts.required !== false && String(v).trim() === '') { input.focus(); return; } done(); onValue(v); });
    no.addEventListener('click', function () { done(); if (opts.onCancel) opts.onCancel(); if (anchor && anchor.focus) anchor.focus(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } if (e.key === 'Escape') { e.preventDefault(); no.click(); } });
    place(anchor, box);
    input.focus(); if (input.select) input.select();
  }

  // <form data-mo-confirm="…"> / <form data-confirm="…">: the first submit asks; Yes submits for real, with the same
  // submit button. An empty data-confirm asks "Are you sure?", as the old confirm() hook did.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.matches || !f.matches('form[data-mo-confirm],form[data-confirm]')) return;
    if (f._moOk) { f._moOk = false; return; }
    e.preventDefault(); e.stopImmediatePropagation();
    var sub = e.submitter || f.querySelector('[type=submit],button:not([type])');
    confirmInline(sub || f, f.getAttribute('data-mo-confirm') || f.getAttribute('data-confirm') || 'Are you sure?', function () {
      f._moOk = true;
      if (f.requestSubmit) { sub && sub.form === f ? f.requestSubmit(sub) : f.requestSubmit(); } else { f.submit(); }
    });
  }, true);
  // <form data-mo-ask="Label" data-mo-ask-field="name" [data-mo-ask-value="…"]>: the first submit asks for a value
  // inline, puts it in that field, then submits for real. Cancel submits nothing.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.matches || !f.matches('form[data-mo-ask]')) return;
    if (f._moOk) { f._moOk = false; return; }
    e.preventDefault(); e.stopImmediatePropagation();
    var sub = e.submitter || f.querySelector('[type=submit],button:not([type])');
    var field = f.elements[f.getAttribute('data-mo-ask-field') || ''];
    if (!field) return;
    ask(sub || f, f.getAttribute('data-mo-ask'), f.getAttribute('data-mo-ask-value') || field.value, function (v) {
      field.value = v;
      f._moOk = true;
      if (f.requestSubmit) { sub && sub.form === f ? f.requestSubmit(sub) : f.requestSubmit(); } else { f.submit(); }
    }, { required: !f.hasAttribute('data-mo-ask-optional') });
  }, true);
  // <button|a data-mo-confirm="…"> outside a confirming form: the click asks; Yes repeats the click.
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-mo-confirm]:not(form)') : null;
    if (!b) return;
    if (b._moOk) { b._moOk = false; return; }
    e.preventDefault(); e.stopImmediatePropagation();
    confirmInline(b, b.getAttribute('data-mo-confirm'), function () { b._moOk = true; b.click(); });
  }, true);

  window.moDialog = { confirm: confirmInline, confirmed: confirmed, notice: notice, ask: ask };
})();
</script>
