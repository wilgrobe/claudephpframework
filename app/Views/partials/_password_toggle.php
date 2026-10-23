<?php
/*
 * app/Views/partials/_password_toggle.php
 *
 * Phase 43.173 — shared show/hide password toggle for auth forms.
 *
 * Auto-wires every <input type="password"> on the current page:
 * wraps it in a flex container + appends a small button that flips
 * input.type between password text + updates its aria-label.
 *
 * Usage: include from any auth view that wants the toggle via
 * require __DIR__ . '/../partials/_password_toggle.php'
 *
 * Placement: anywhere inside <body>. Idempotent — re-includes are
 * no-ops (the script no-ops on already-wired inputs via a data-attr
 * guard).
 *
 * Accessibility: button is type="button" so it can't accidentally
 * submit the surrounding form. aria-label is updated on toggle so
 * screen readers announce the current state. Tab order preserved
 * because the button sits inside the same wrapper as the input.
 *
 * Phase 44.3 (Batch 7) — switched from line comments (//) to a block
 * comment because the original line-comment block contained an example
 * snippet `<?php ... ?>` whose embedded `?>` was terminating PHP mode
 * mid-comment. Everything after that line was rendered literally on
 * every login / register / password-reset page. Block comments treat
 * `?>` as text so the example survives correctly.
 */
?>
<style>
.pw-toggle-wrap { position: relative; display: block; }
.pw-toggle-wrap input { width: 100%; padding-right: 2.6rem; box-sizing: border-box; }
/* admin.css styles `.form-row > .pw-toggle-wrap > input` (padding .75rem); room for the eye must win over it. */
.form-row > .pw-toggle-wrap > input, .pw-toggle-wrap > input[type] { padding-right: 2.6rem; }
.pw-toggle-btn {
    position: absolute; top: 50%; right: .5rem; transform: translateY(-50%);
    background: transparent; border: 0; cursor: pointer;
    color: var(--color-gray-500, #6b7280); line-height: 0;
    padding: .3rem; border-radius: 4px;
    transition: color .12s, background .12s;
}
.pw-toggle-btn svg { width: 18px; height: 18px; display: block; }
.pw-toggle-btn:hover { color: var(--color-primary, var(--color-primary)); background: var(--accent-subtle, var(--accent-subtle)); }
.pw-toggle-btn:focus-visible { outline: 2px solid var(--color-primary, var(--color-primary)); outline-offset: 1px; }
</style>
<script>
(function () {
    // Line icons in currentColor (the 👁/🙈 emoji rendered differently per OS and ignored the theme colour).
    var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.6 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.6 3.6M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M2 2l20 20"/></svg>';
    function wire(input) {
        if (input.dataset.pwToggleWired === '1') return;
        input.dataset.pwToggleWired = '1';
        // If the input is already inside a wrap, reuse it; otherwise wrap.
        var wrap;
        if (input.parentElement && input.parentElement.classList.contains('pw-toggle-wrap')) {
            wrap = input.parentElement;
        } else {
            wrap = document.createElement('div');
            wrap.className = 'pw-toggle-wrap';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle-btn';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function () {
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            btn.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
            btn.innerHTML = isHidden ? EYE_OFF : EYE;
            input.focus();
        });
        wrap.appendChild(btn);
    }
    function wireAll() {
        document.querySelectorAll('input[type="password"]').forEach(wire);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAll);
    } else {
        wireAll();
    }
})();
</script>
