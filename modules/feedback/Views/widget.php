<?php
/**
 * "Report an issue" widget — the corner bubble + slide-up panel.
 *
 * Included once from app/Views/partials/site_footer.php, which covers both
 * page shells (the app chrome via layout/footer.php and the public guest page
 * via public/page.php), so a customer can report a problem from wherever they
 * hit it without losing the page it happened on.
 *
 * SELF-GUARDING: returns immediately unless the site enabled the widget and
 * the current visitor is in its audience. A site that never opts in gets zero
 * bytes of output — no markup, no CSS, no JS, no behavior change.
 *
 * Self-contained by design: no CDN, no build step, no dependency on app.css
 * (the guest shell doesn't always load it). Every colour is a theme var with
 * a literal fallback, and every class is `cfw-`-prefixed to stay out of the
 * host site's way.
 *
 * The script also installs the diagnostics recorder. It runs from the footer,
 * BEFORE app.js and any deferred page script, so it catches the failures that
 * matter most — the ones that happen while someone is using the page. Errors
 * thrown before the footer parses are the one gap, and those tend to break
 * rendering outright, which is a different (and more visible) failure.
 */

use Modules\Feedback\Services\IssueWidget;

if (!class_exists(IssueWidget::class) || !IssueWidget::visible()) return;

$__cfwAuth   = \Core\Auth\Auth::getInstance();
$__cfwUser   = $__cfwAuth->check() ? $__cfwAuth->user() : null;
$__cfwEmail  = (string) ($__cfwUser['email'] ?? '');
$__cfwToken  = function_exists('csrf_token') ? csrf_token() : '';
$__cfwBubble = IssueWidget::showsBubble();
$__cfwE      = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<?php if ($__cfwBubble): ?>
<button type="button" class="cfw-bubble" data-issue-report-open
        aria-haspopup="dialog" aria-controls="cfw-panel" title="Report an issue">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
    </svg>
    <span>Report an issue</span>
</button>
<?php endif; ?>

<div class="cfw-backdrop" data-issue-report-close hidden></div>

<div class="cfw-panel" id="cfw-panel" role="dialog" aria-modal="true"
     aria-labelledby="cfw-title" hidden>

    <div class="cfw-head">
        <h2 id="cfw-title">Report an issue</h2>
        <button type="button" class="cfw-x" data-issue-report-close aria-label="Close">&times;</button>
    </div>

    <form class="cfw-form" novalidate>
        <input type="hidden" name="_token" value="<?= $__cfwE($__cfwToken) ?>">
        <!-- Honeypot: off-screen, never focusable, never announced. -->
        <div class="cfw-hp" aria-hidden="true">
            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <p class="cfw-lede">Tell us what went wrong and we’ll look into it. We’ll automatically
           include the page you’re on and some technical details, so you don’t have to describe them.</p>

        <label class="cfw-label" for="cfw-intent">What were you trying to do?</label>
        <textarea id="cfw-intent" name="intent" rows="2" maxlength="5000"
                  placeholder="e.g. Mark this week’s LinkedIn post as posted"></textarea>
        <p class="cfw-err" data-err="intent" hidden></p>

        <label class="cfw-label" for="cfw-message">What happened instead?</label>
        <textarea id="cfw-message" name="message" rows="3" maxlength="5000"
                  placeholder="e.g. The page reloaded and the task still shows as pending"></textarea>
        <p class="cfw-err" data-err="message" hidden></p>

        <label class="cfw-check">
            <input type="checkbox" name="severity" value="blocking">
            <span>This is blocking me — I can’t carry on</span>
        </label>

        <?php if ($__cfwEmail === ''): ?>
            <label class="cfw-label" for="cfw-email">Your email <span class="cfw-opt">(so we can reply)</span></label>
            <input id="cfw-email" type="email" name="email" maxlength="254" autocomplete="email" placeholder="you@example.com">
            <p class="cfw-err" data-err="email" hidden></p>
        <?php else: ?>
            <input type="hidden" name="email" value="<?= $__cfwE($__cfwEmail) ?>">
        <?php endif; ?>

        <label class="cfw-check">
            <input type="checkbox" name="request_response" value="1" checked>
            <span>Email me when this is sorted<?= $__cfwEmail !== '' ? ' (' . $__cfwE($__cfwEmail) . ')' : '' ?></span>
        </label>

        <p class="cfw-err cfw-err--form" data-err="_form" hidden></p>

        <div class="cfw-actions">
            <button type="button" class="cfw-btn cfw-btn--ghost" data-issue-report-close>Cancel</button>
            <button type="submit" class="cfw-btn cfw-btn--primary">Send report</button>
        </div>

        <details class="cfw-details">
            <summary>What gets sent with this?</summary>
            <ul>
                <li>Your name and email<?= $__cfwUser ? '' : ' (only if you enter one)' ?>, so we can follow up</li>
                <li>The page you’re on and how you got there</li>
                <li>Your browser, screen size, and time zone</li>
                <li>Any errors or failed requests your browser recorded, and the buttons you clicked just before</li>
            </ul>
            <p>We don’t capture what you typed into other forms, your password, or your screen.</p>
        </details>
    </form>

    <div class="cfw-done" hidden>
        <div class="cfw-done-mark" aria-hidden="true">✓</div>
        <h3>Thanks — we’ve got it</h3>
        <p class="cfw-done-ref"></p>
        <p class="cfw-done-note">We read every report. If you asked for a reply, we’ll email you.</p>
        <button type="button" class="cfw-btn cfw-btn--primary" data-issue-report-close>Close</button>
    </div>
</div>

<style>
/* Everything is cfw-prefixed and only ever positioned fixed, so the widget
   can't disturb the host page's layout. Colours prefer the site's theme vars
   and fall back to literals for the guest shell, which doesn't always load
   the full token set. */
.cfw-bubble {
    position: fixed; right: 1rem; bottom: 1rem; z-index: 2147482000;
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .55rem .9rem; border: 0; border-radius: 999px; cursor: pointer;
    font: 600 13px/1 -apple-system, "Segoe UI", Roboto, sans-serif;
    background: var(--color-primary, #111827); color: var(--color-primary-fg, #fff);
    box-shadow: 0 4px 14px rgba(0,0,0,.22);
    transition: transform .15s ease, box-shadow .15s ease;
}
.cfw-bubble:hover  { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(0,0,0,.28); }
.cfw-bubble:focus-visible { outline: 2px solid var(--color-primary, #111827); outline-offset: 3px; }

/* Below ~480px the label is dropped so the launcher doesn't sit on top of
   the content it's meant to help report about. */
@media (max-width: 480px) {
    .cfw-bubble span { display: none; }
    .cfw-bubble      { padding: .7rem; }
}
<?php if (($__f_position ?? 'static') === 'fixed'): ?>
/* This site pins its footer to the viewport, so lift the launcher clear of it
   (the footer's own height is declared as --site-footer-height above). */
.cfw-bubble { bottom: calc(var(--site-footer-height, 2.4rem) + .6rem); }
<?php endif; ?>

.cfw-backdrop {
    position: fixed; inset: 0; z-index: 2147482500;
    background: rgba(17,24,39,.45);
}
.cfw-panel {
    position: fixed; right: 1rem; bottom: 1rem; z-index: 2147483000;
    width: min(400px, calc(100vw - 2rem)); max-height: min(82vh, 680px);
    display: flex; flex-direction: column; overflow: hidden;
    border-radius: 12px;
    background: var(--bg-panel, #fff);
    color: var(--text-default, #111827);
    border: 1px solid var(--color-gray-200, #e5e7eb);
    box-shadow: 0 18px 50px rgba(0,0,0,.28);
    font: 400 14px/1.55 -apple-system, "Segoe UI", Roboto, sans-serif;
    animation: cfw-in .18s ease-out;
}
/* display:flex above would otherwise beat the [hidden] attribute's UA
   display:none and leave the panel permanently open. */
.cfw-panel[hidden], .cfw-backdrop[hidden] { display: none; }
@keyframes cfw-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .cfw-panel { animation: none; } .cfw-bubble { transition: none; } }
@media (max-width: 520px) {
    .cfw-panel { right: .5rem; left: .5rem; bottom: .5rem; width: auto; max-height: 88vh; }
}

.cfw-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .85rem 1rem; border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
}
.cfw-head h2 { margin: 0; font-size: 15px; font-weight: 700; }
.cfw-x {
    background: none; border: 0; cursor: pointer; line-height: 1;
    font-size: 22px; color: var(--text-subtle, #6b7280); padding: 0 .15rem;
}
.cfw-x:hover { color: var(--text-default, #111827); }

.cfw-form, .cfw-done { padding: 1rem; overflow-y: auto; }
.cfw-lede  { margin: 0 0 .9rem; font-size: 12.5px; color: var(--text-subtle, #6b7280); }
.cfw-label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 .3rem; }
.cfw-opt   { font-weight: 400; color: var(--text-subtle, #6b7280); }

.cfw-panel textarea, .cfw-panel input[type="email"] {
    width: 100%; box-sizing: border-box; margin: 0 0 .25rem;
    padding: .5rem .65rem; border-radius: 7px; resize: vertical;
    border: 1px solid var(--color-gray-300, #d1d5db);
    background: var(--bg-input, #fff); color: inherit;
    font: inherit; font-size: 13.5px;
}
.cfw-panel textarea:focus, .cfw-panel input[type="email"]:focus {
    outline: 0; border-color: var(--color-primary, #111827);
    box-shadow: 0 0 0 3px rgba(17,24,39,.09);
}
.cfw-panel textarea[aria-invalid="true"], .cfw-panel input[aria-invalid="true"] { border-color: #dc2626; }

.cfw-check {
    display: flex; align-items: flex-start; gap: .5rem;
    margin: .55rem 0 .9rem; font-size: 13px; cursor: pointer;
    color: var(--text-subtle, #4b5563);
}
.cfw-check input { margin-top: .2rem; flex: none; }

.cfw-err { margin: 0 0 .7rem; font-size: 12.5px; color: #dc2626; }
.cfw-err--form { margin-top: .5rem; }
.cfw-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

.cfw-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: .35rem; }
.cfw-btn {
    padding: .5rem .95rem; border-radius: 7px; cursor: pointer;
    font: 600 13px/1 -apple-system, "Segoe UI", Roboto, sans-serif;
    border: 1px solid transparent;
}
.cfw-btn--primary { background: var(--color-primary, #111827); color: var(--color-primary-fg, #fff); }
.cfw-btn--ghost   { background: transparent; color: var(--text-subtle, #6b7280); border-color: var(--color-gray-300, #d1d5db); }
.cfw-btn[disabled] { opacity: .6; cursor: default; }

.cfw-details { margin-top: 1rem; font-size: 12.5px; color: var(--text-subtle, #6b7280); }
.cfw-details summary { cursor: pointer; font-weight: 600; }
.cfw-details ul { margin: .5rem 0 .5rem 1.1rem; padding: 0; }
.cfw-details li { margin-bottom: .2rem; }
.cfw-details p  { margin: .5rem 0 0; }

.cfw-done { text-align: center; padding: 2rem 1.25rem; }
.cfw-done-mark {
    width: 44px; height: 44px; margin: 0 auto .75rem; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: #dcfce7; color: #16a34a; font-size: 22px; font-weight: 700;
}
.cfw-done h3 { margin: 0 0 .35rem; font-size: 15px; }
.cfw-done-ref  { margin: 0 0 .5rem; font-size: 13px; color: var(--text-subtle, #6b7280); }
.cfw-done-note { margin: 0 0 1.25rem; font-size: 12.5px; color: var(--text-subtle, #6b7280); }
</style>

<script>
(function () {
    'use strict';

    var panel = document.getElementById('cfw-panel');
    if (!panel || panel.dataset.cfwReady) return;   // include-twice guard
    panel.dataset.cfwReady = '1';

    var backdrop = document.querySelector('.cfw-backdrop');
    var form     = panel.querySelector('.cfw-form');
    var done     = panel.querySelector('.cfw-done');
    var submit   = form.querySelector('button[type="submit"]');
    var START    = Date.now();
    var lastFocus = null;

    /* ── Diagnostics recorder ──────────────────────────────────────────
       A capped ring buffer of what the browser saw. This is the part that
       turns "it didn't work" into something actionable: the failed request
       and the click that triggered it, captured while it happened rather
       than reconstructed from memory afterwards.

       Every hook is wrapped in try/catch and always delegates to the
       original — instrumentation that breaks the page it's monitoring
       would be worse than no instrumentation at all. */
    var MAX = 25;
    var buf = { js_errors: [], failed_requests: [], console_errors: [], breadcrumbs: [] };

    function push(list, entry) {
        try {
            var a = buf[list];
            a.push(entry);
            if (a.length > MAX) a.shift();
        } catch (e) { /* never let recording throw into caller code */ }
    }
    function stamp() {
        try { return new Date().toISOString().substr(11, 8); } catch (e) { return ''; }
    }
    function clip(s, n) {
        s = (s === null || s === undefined) ? '' : String(s);
        return s.length > n ? s.slice(0, n) : s;
    }

    window.addEventListener('error', function (ev) {
        push('js_errors', {
            message: clip(ev && ev.message, 300),
            source:  clip(((ev && ev.filename) || '') + ':' + ((ev && ev.lineno) || 0), 200),
            at:      stamp()
        });
    }, true);

    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev && ev.reason;
        push('js_errors', {
            message: clip('Unhandled promise rejection: ' + ((r && r.message) ? r.message : r), 300),
            at: stamp()
        });
    });

    if (window.console && console.error) {
        var origError = console.error;
        console.error = function () {
            try {
                push('console_errors', {
                    message: clip(Array.prototype.map.call(arguments, function (a) {
                        try { return typeof a === 'string' ? a : JSON.stringify(a); } catch (e) { return String(a); }
                    }).join(' '), 400),
                    at: stamp()
                });
            } catch (e) {}
            return origError.apply(console, arguments);
        };
    }

    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = '', method = 'GET';
            try {
                url    = typeof input === 'string' ? input : (input && input.url) || '';
                method = (init && init.method) || (input && input.method) || 'GET';
            } catch (e) {}
            return origFetch.apply(this, arguments).then(function (res) {
                if (res && res.status >= 400) {
                    push('failed_requests', { method: method, url: clip(url, 300), status: res.status, at: stamp() });
                }
                return res;
            }, function (err) {
                push('failed_requests', { method: method, url: clip(url, 300), status: 'network error', at: stamp() });
                throw err;
            });
        };
    }

    if (window.XMLHttpRequest && XMLHttpRequest.prototype) {
        var xhrOpen = XMLHttpRequest.prototype.open;
        var xhrSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, u) {
            try { this.__cfw = { m: m, u: u }; } catch (e) {}
            return xhrOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            var xhr = this;
            try {
                xhr.addEventListener('loadend', function () {
                    try {
                        if (xhr.status === 0 || xhr.status >= 400) {
                            push('failed_requests', {
                                method: (xhr.__cfw && xhr.__cfw.m) || 'GET',
                                url:    clip((xhr.__cfw && xhr.__cfw.u) || '', 300),
                                status: xhr.status || 'network error',
                                at:     stamp()
                            });
                        }
                    } catch (e) {}
                });
            } catch (e) {}
            return xhrSend.apply(this, arguments);
        };
    }

    /* Click trail — what they pressed, in order. Labels only: never the
       value of a text field, so nothing anyone typed elsewhere is captured. */
    document.addEventListener('click', function (ev) {
        try {
            var t = ev.target;
            if (!t || !t.closest) return;
            var el = t.closest('a, button, summary, [role="button"], input[type="submit"], input[type="checkbox"], select');
            if (!el || el.closest('.cfw-panel, .cfw-bubble')) return;
            var label = el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || '';
            label = label.replace(/\s+/g, ' ').trim();
            push('breadcrumbs', {
                label:  clip(label || el.tagName.toLowerCase(), 60),
                target: clip(el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''), 80),
                at:     stamp()
            });
        } catch (e) {}
    }, true);

    function snapshot() {
        var c = {};
        try {
            c = {
                viewport:        window.innerWidth + 'x' + window.innerHeight,
                screen:          (screen.width || '?') + 'x' + (screen.height || '?'),
                pixel_ratio:     window.devicePixelRatio || 1,
                timezone:        (Intl.DateTimeFormat().resolvedOptions().timeZone) || '',
                locale:          navigator.language || '',
                online:          navigator.onLine,
                cookies_enabled: navigator.cookieEnabled,
                time_on_page_s:  Math.round((Date.now() - START) / 1000),
                local_time:      new Date().toString()
            };
        } catch (e) {}
        return {
            page: { url: location.href, title: document.title, referrer: document.referrer },
            client: c,
            diagnostics: buf
        };
    }

    /* ── Open / close ──────────────────────────────────────────────── */
    function open() {
        lastFocus = document.activeElement;
        backdrop.hidden = false;
        panel.hidden = false;
        form.hidden = false;
        done.hidden = true;
        var first = panel.querySelector('textarea');
        if (first) first.focus();
    }
    function close() {
        panel.hidden = true;
        backdrop.hidden = true;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    /* Delegated so the footer link (and anything else a site adds with
       data-issue-report-open) works without this script knowing about it. */
    document.addEventListener('click', function (ev) {
        var o = ev.target.closest && ev.target.closest('[data-issue-report-open]');
        if (o) { ev.preventDefault(); open(); return; }
        var c = ev.target.closest && ev.target.closest('[data-issue-report-close]');
        if (c) { ev.preventDefault(); close(); }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !panel.hidden) close();
    });

    /* ── Submit ────────────────────────────────────────────────────── */
    function showErrors(errs) {
        panel.querySelectorAll('.cfw-err').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        panel.querySelectorAll('[aria-invalid]').forEach(function (f) { f.removeAttribute('aria-invalid'); });
        Object.keys(errs || {}).forEach(function (k) {
            var p = panel.querySelector('[data-err="' + k + '"]');
            if (p) { p.textContent = errs[k]; p.hidden = false; }
            var field = form.querySelector('[name="' + k + '"]');
            if (field) field.setAttribute('aria-invalid', 'true');
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        showErrors({});
        submit.disabled = true;
        submit.textContent = 'Sending…';

        var fd = new FormData(form);
        fd.append('context', JSON.stringify(snapshot()));
        if (!form.querySelector('[name="severity"]').checked) fd.set('severity', 'normal');

        fetch('/feedback/report', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': form.querySelector('[name="_token"]').value, 'Accept': 'application/json' },
            body: fd
        }).then(function (res) {
            // JSON responses are served with a `)]}',` anti-hijacking prefix,
            // so res.json() would throw on every reply — read as text and
            // strip it before parsing.
            return res.text().then(function (body) {
                var data = null;
                try { data = JSON.parse(body.replace(/^\)\]\}',?\s*/, '')); } catch (e) {}
                return { status: res.status, data: data };
            });
        }).then(function (r) {
            if (r.data && r.data.ok) {
                form.hidden = true;
                done.hidden = false;
                done.querySelector('.cfw-done-ref').textContent =
                    r.data.id ? 'Your reference is #' + r.data.id + '.' : '';
                return;
            }
            if (r.data && r.data.errors) showErrors(r.data.errors);
            else showErrors({ _form: (r.data && r.data.error) || 'Something went wrong sending that. Please try again.' });
        }).catch(function () {
            showErrors({ _form: 'We couldn’t reach the server. Check your connection and try again.' });
        }).then(function () {
            submit.disabled = false;
            submit.textContent = 'Send report';
        });
    });
})();
</script>
