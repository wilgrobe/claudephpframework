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

/**
 * Dismiss a notification row via the × button.
 * Finds the enclosing .notif-row, POSTs to the delete endpoint, and
 * removes the row on success. The button is only rendered server-side
 * when the notification is deletable, so 409s are a rare race and get
 * surfaced as an alert rather than handled silently.
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
        alert('Could not dismiss this notification. Please reload the page and try again.');
        return;
    }
    // JSON response with a structured error (e.g., 409 "action still pending").
    if (res && res.error) {
        alert(res.error);
        return;
    }
    row.remove();
}
