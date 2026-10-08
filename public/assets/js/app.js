/**
 * phpframework-v2 — public/assets/js/app.js
 * Shared browser utilities loaded on every page.
 */

'use strict';

/**
 * XSSI-safe JSON fetch.
 * All framework JSON endpoints emit the prefix )]}',\n as a Cross-Site
 * Script Inclusion defense. Always use safeJson() instead of res.json().
 *
 * @param {string} url
 * @param {RequestInit} options
 * @returns {Promise<any>}
 */
async function safeJson(url, options = {}) {
    const res  = await fetch(url, options);
    const text = await res.text();
    const json = text.startsWith(")]}',\n") ? text.slice(6) : text;
    try {
        return JSON.parse(json);
    } catch (e) {
        console.error('safeJson parse error for', url, ':', text.slice(0, 200));
        throw e;
    }
}

/**
 * POST with CSRF token auto-injected.
 * Accepts a plain object or existing FormData.
 *
 * @param {string}              url
 * @param {object|FormData}     data
 * @returns {Promise<any>}
 */
async function csrfPost(url, data = {}) {
    const fd = data instanceof FormData ? data : (() => {
        const f = new FormData();
        Object.entries(data).forEach(([k, v]) => f.append(k, String(v)));
        return f;
    })();
    // Prefer a form-embedded _token if one is on the page; otherwise
    // fall back to the global <meta name="csrf-token"> that layout/header
    // always renders. Without that fallback, AJAX POSTs from pages with
    // no form fail a CSRF check silently.
    const token = document.querySelector('[name=_token]')?.value
               || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (token && !fd.has('_token')) fd.append('_token', token);
    return safeJson(url, { method: 'POST', body: fd });
}

// An error said inline, beside the row (#421) — never alert(). moDialog comes from partials/_inline_dialogs.php,
// which the layout footer includes; without it the message still reaches the console.
function notifyInline(anchor, message) {
    if (window.moDialog) moDialog.notice(anchor, message, true); else console.error(message);
}

/**
 * Dismiss a notification row via the × button.
 * Finds the enclosing .notif-row, POSTs to the delete endpoint, and
 * removes the row on success. The button is only rendered server-side
 * when the notification is deletable, so 409s are a rare race and get
 * surfaced inline beside the row rather than handled silently.
 *
 * @param {HTMLElement} btn  The clicked × button.
 */
async function dismissNotification(btn) {
    const row = btn.closest('.notif-row');
    const id  = row?.dataset?.id;
    if (!id) return;

    let res;
    try {
        res = await csrfPost('/notifications/' + id + '/delete');
    } catch (e) {
        // Non-JSON response: typically a 419 CSRF rejection (plain text)
        // or a 500 HTML error page. Surface it rather than swallowing.
        console.error('dismissNotification network/parse failure', e);
        notifyInline(row, 'Could not dismiss this notification. Please reload the page and try again.');
        return;
    }
    // JSON response with a structured error (e.g., 409 "action still pending").
    if (res && res.error) {
        notifyInline(row, res.error);
        return;
    }
    row.remove();
}

/**
 * A11y: auto-wire aria-describedby on .form-row inputs that have a
 * sibling <small> hint or .error message. Without this, screen readers
 * announce the input label but not the supplementary text. Runs at
 * DOMContentLoaded; idempotent — safe to call again after dynamic
 * insertions if needed.
 */
function wireFormRowAria() {
    let counter = 0;
    document.querySelectorAll('.form-row').forEach((row) => {
        const input = row.querySelector('input, select, textarea');
        if (!input) return;
        const hint  = row.querySelector(':scope > small');
        const error = row.querySelector(':scope > .error, :scope > .form-error');
        const ids = [];
        for (const el of [error, hint]) {  // error first so it reads before hint
            if (!el) continue;
            if (!el.id) el.id = 'fra-' + (++counter);
            ids.push(el.id);
        }
        if (ids.length === 0) return;
        const existing = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        const merged = Array.from(new Set([...existing, ...ids]));
        input.setAttribute('aria-describedby', merged.join(' '));
        if (error) input.setAttribute('aria-invalid', 'true');
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireFormRowAria);
} else {
    wireFormRowAria();
}

/* ── Keep guest forms' CSRF tokens fresh ──────────────────────────────────
 * Lives here, not in the app footer: auth/login.php is a standalone document
 * that never includes the app layout, so a footer script cannot reach the one
 * page this exists for. Every auth view loads this file, and so does the app
 * footer, so one copy covers both.
 *
 * A sign-in page left open (or restored from bfcache) posts a token minted in
 * a session that no longer exists. The middleware self-heals - it re-renders
 * the form with a fresh token and an explanation - but that is still an error
 * the person did nothing to earn, and it used to be logged as a security
 * event and page the owner by SMS. Better not to go stale at all.
 */
/* ── Keep guest forms' CSRF tokens fresh ──────────────────────────────────
 * A sign-in page left open (or restored from bfcache) posts a token minted in
 * a session that no longer exists. The middleware self-heals — it hands back
 * the form with a new token and a message — but the message is still an error
 * the person did nothing to deserve, and it used to page the owner as a
 * security event. Better not to go stale in the first place.
 */
(function () {
    var forms = Array.prototype.slice.call(document.querySelectorAll('form'))
        .filter(function (f) { return f.querySelector('input[name="_token"]'); });
    if (!forms.length) return;
    // Only the guest forms. An authenticated page has the session guard above,
    // and re-tokening a form mid-edit there would be noise for no gain.
    if (!/^\/(login|register|password\/(forgot|reset))\/?$/.test(location.pathname)) return;

    function refresh() {
        fetch('/csrf-token', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (t) {
                if (t === null) return;
                var d = null;
                try { d = JSON.parse(t.replace(/^\)\]\}',?\s*/, '')); } catch (e) { return; }
                if (!d || !d.token) return;
                forms.forEach(function (f) {
                    var i = f.querySelector('input[name="_token"]');
                    if (i) i.value = d.token;
                });
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.content = d.token;
            })
            .catch(function () { /* the form still has its original token */ });
    }

    // Refresh at the moment of submit, which is the one thing Turnstile cannot do.
    //
    // Turnstile's own answer to staleness is to refresh ~18s BEFORE its 300s
    // expiry, so a submit almost never carries a dead token — but "almost never"
    // still leaves a window, and when it closes on somebody the cost here is
    // worse than Turnstile's: a failed captcha asks you to redo a checkbox, a
    // failed CSRF asks you to retype your password. So the token is re-minted
    // immediately before the form goes, and the window closes entirely.
    //
    // The timeout is the important part. A slow or dead network must never trap
    // anyone on a sign-in form: if the refresh has not answered within 1.2s the
    // form submits with the token it already had, and the middleware's self-heal
    // is still there behind it. Failing open is right here — the worst case is
    // the behaviour we had before this existed.
    forms.forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (f.dataset.freshToken === '1') return;   // this is our own re-submit
            ev.preventDefault();

            var sent = false;
            function go() {
                if (sent) return;
                sent = true;
                f.dataset.freshToken = '1';
                // form.submit() does not re-fire submit handlers, so there is no
                // loop; the dataset flag is belt and braces.
                f.submit();
            }
            var timer = setTimeout(go, 1200);

            fetch('/csrf-token', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (t) {
                    if (t !== null) {
                        var d = null;
                        try { d = JSON.parse(t.replace(/^\)\]\}',?\s*/, '')); } catch (e) { d = null; }
                        if (d && d.token) {
                            var i = f.querySelector('input[name="_token"]');
                            if (i) i.value = d.token;
                        }
                    }
                    clearTimeout(timer); go();
                })
                .catch(function () { clearTimeout(timer); go(); });
        });
    });
    // On restore from bfcache the token is as old as the page; on a long sit it
    // ages out. Refresh on both, and every ten minutes in between.
    window.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
    setInterval(function () { if (!document.hidden) refresh(); }, 600000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
