/**
 * phpframework-v2 — public/assets/js/broadcast.js
 *
 * Browser-side WebSocket subscriber for live events from BroadcastService.
 * Reads window.BROADCAST_CONFIG (emitted by layout/footer.php only when
 * broadcasting is enabled + a user is signed in) and connects to the
 * matching provider (Pusher / Soketi / Ably) via its official SDK.
 *
 * Default subscriptions:
 *   - user.{id} channel, listening for `notification.created`
 *     → refreshes /notifications/count and updates the .notif-count bell badge
 *     → dispatches `broadcast:notification` window event for app code
 *
 * App-level usage (additional channels / events):
 *
 *     window.addEventListener('broadcast:ready', (e) => {
 *         const { client, userChannel } = e.detail;
 *         userChannel.bind('custom.event', (data) => { ... });
 *         const room = client.subscribe('chat.42');
 *         room.bind('message.created', (data) => { ... });
 *     });
 *
 * The window.Broadcast object also exposes the connected client +
 * userChannel after init for any code that loads after this module:
 *
 *     window.Broadcast.userChannel.bind('custom.event', cb);
 *     window.Broadcast.client.subscribe('site.feed').bind('post.published', cb);
 *
 * No-op when BROADCAST_CONFIG isn't present (provider=none or guest
 * page or broadcasting disabled).
 */

'use strict';

(function () {
    const cfg = window.BROADCAST_CONFIG;
    if (!cfg || !cfg.provider || cfg.provider === 'none' || !cfg.user_id) return;

    /**
     * Refresh a specific bell badge by fetching its count endpoint.
     * Finds the bell by the anchor `href` substring (so we don't depend
     * on a class name; whichever .notif-bell links to /notifications
     * is the notifications bell, /messages is the messaging bell).
     * Creates/updates/removes the .notif-count span based on the count.
     */
    function refreshBellBadge(countUrl, anchorHrefMatch) {
        fetch(countUrl, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(text => {
                // XSSI prefix per the framework's Response::json convention.
                const json = text.startsWith(")]}',\n") ? text.slice(6) : text;
                let data;
                try { data = JSON.parse(json); } catch (_) { return; }
                const count = typeof data === 'number' ? data : (data?.count ?? 0);
                const link  = document.querySelector('.notif-bell a[href*="' + anchorHrefMatch + '"]');
                const bell  = link?.closest('.notif-bell');
                if (!bell) return;
                let badge = bell.querySelector('.notif-count');
                if (count <= 0) {
                    badge?.remove();
                    return;
                }
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notif-count';
                    bell.appendChild(badge);
                }
                badge.textContent = String(count);
            })
            .catch(() => { /* network blip — bell badge re-syncs on next page load */ });
    }

    /** Default handlers on user.{id} — notification.created updates the
     *  notifications bell; message.created updates the messaging bell.
     *  Both fire app-level custom events for additional UI hooks. */
    function bindNotificationHandler(channel) {
        channel.bind('notification.created', function (data) {
            refreshBellBadge('/notifications/count', '/notifications');
            window.dispatchEvent(new CustomEvent('broadcast:notification', { detail: data }));
        });
        channel.bind('message.created', function (data) {
            refreshBellBadge('/messages/count', '/messages');
            window.dispatchEvent(new CustomEvent('broadcast:message', { detail: data }));
        });
    }

    // ── Pusher / Soketi ──────────────────────────────────────────
    function initPusher() {
        if (!window.Pusher) {
            console.warn('[broadcast] Pusher SDK not loaded — skipping.');
            return;
        }
        const opts = {
            cluster:     cfg.cluster || 'mt1',
            forceTLS:    true,
            enabledTransports: ['ws', 'wss'],
            // /broadcast/auth handshake only fires for private-/presence-
            // channels. Public channels (like user.{id} by default) skip
            // it entirely.
            authEndpoint: '/broadcast/auth',
            auth: {
                headers: {
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            },
        };
        // Soketi self-hosted: override wsHost + ports. cfg.host can be
        // a bare hostname ("soketi.example.com") or a full URL — we
        // accept both for the pusherapp-compatible client.
        if (cfg.host) {
            try {
                const u = cfg.host.match(/^https?:\/\//i) ? new URL(cfg.host) : new URL('https://' + cfg.host);
                opts.wsHost   = u.hostname;
                opts.wssPort  = u.port ? parseInt(u.port, 10) : 443;
                opts.wsPort   = u.port ? parseInt(u.port, 10) : 80;
                opts.forceTLS = u.protocol === 'https:';
            } catch (e) {
                opts.wsHost = cfg.host;
            }
        }
        const client = new Pusher(cfg.key, opts);
        const userChannel = client.subscribe('user.' + cfg.user_id);
        bindNotificationHandler(userChannel);
        return { client, userChannel };
    }

    // ── Ably ─────────────────────────────────────────────────────
    function initAbly() {
        if (!window.Ably) {
            console.warn('[broadcast] Ably SDK not loaded — skipping.');
            return;
        }
        const client = new Ably.Realtime({ key: cfg.api_key, echoMessages: false });
        const userChannel = client.channels.get('user.' + cfg.user_id);
        // Adapt the Ably API to the same .bind() surface as pusher-js
        // so the rest of the code is provider-agnostic.
        const shim = {
            bind: (event, cb) => {
                userChannel.subscribe(event, (msg) => cb(msg.data));
            },
            unbind: (event) => userChannel.unsubscribe(event),
            _ably: userChannel,
        };
        bindNotificationHandler(shim);
        // Expose a similarly-shaped client.subscribe() for app code.
        const wrappedClient = {
            subscribe: (name) => {
                const ch = client.channels.get(name);
                return {
                    bind: (event, cb) => ch.subscribe(event, (msg) => cb(msg.data)),
                    unbind: (event) => ch.unsubscribe(event),
                    _ably: ch,
                };
            },
            _ably: client,
        };
        return { client: wrappedClient, userChannel: shim };
    }

    let conn = null;
    if (cfg.provider === 'pusher' || cfg.provider === 'soketi') {
        conn = initPusher();
    } else if (cfg.provider === 'ably') {
        conn = initAbly();
    }
    if (!conn) return;

    window.Broadcast = conn;
    window.dispatchEvent(new CustomEvent('broadcast:ready', { detail: conn }));
})();
