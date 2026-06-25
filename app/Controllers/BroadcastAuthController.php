<?php
// app/Controllers/BroadcastAuthController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Services\BroadcastService;

/**
 * Server-side auth handshake for private + presence channels.
 *
 * Pusher / Soketi / Ably all require the WebSocket client to fetch a
 * signed auth token before subscribing to channels prefixed with
 * `private-` or `presence-`. The client makes a POST to this endpoint
 * with its socket_id + channel_name; we return the signed payload
 * verbatim for the client to relay back to the broker.
 *
 * Authorization model (Phase 43.187a hardening): the channel name
 * is parsed + validated against the requesting user's identity.
 * Pre-fix, the endpoint signed any private/presence channel for any
 * authenticated user — a logged-in user could fetch a signature for
 * `private-conversation.999` they had no business reading. Now:
 *
 *   private-user.{id}    → {id} must match $auth->id()
 *   presence-user.{id}   → {id} must match $auth->id()
 *   private-broadcast    → any authenticated user (fanout-style)
 *   presence-broadcast   → any authenticated user (fanout-style)
 *
 * Anything else → 403 deny. Modules that need richer authorization
 * (per-conversation membership, per-group rules) MUST register a
 * custom authorizer via BroadcastAuthController::registerAuthorizer()
 * — adding a callable that returns true/false for the channel. The
 * default-deny posture is the safety net.
 *
 * Public (unprefixed) channels don't go through this endpoint —
 * clients subscribe directly with no auth.
 *
 * 401 if not authenticated; 403 if the channel name isn't recognized
 * or fails authorization; 503 if the broadcast service is disabled or
 * returns null.
 */
class BroadcastAuthController
{
    private Auth $auth;

    /** @var array<int, callable(string $channel, array $user): ?bool> */
    private static array $authorizers = [];

    public function __construct()
    {
        $this->auth = Auth::getInstance();
    }

    /**
     * Register a channel authorizer. The callable receives the
     * channel name + the authenticated user array; returns:
     *   true   → allow (this authorizer wins)
     *   false  → deny  (this authorizer wins)
     *   null   → pass  (skip; try the next authorizer / built-in rules)
     *
     * Multiple registrations are evaluated in declaration order.
     * Module code typically registers from its provider's register()
     * hook at boot time.
     */
    public static function registerAuthorizer(callable $fn): void
    {
        self::$authorizers[] = $fn;
    }

    public function authenticate(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        $socketId    = trim((string) $request->post('socket_id', ''));
        $channelName = trim((string) $request->post('channel_name', ''));
        if ($socketId === '' || $channelName === '') {
            return Response::json(['error' => 'socket_id + channel_name required'], 400);
        }

        if (!str_starts_with($channelName, 'private-')
            && !str_starts_with($channelName, 'presence-')) {
            return Response::json(['error' => 'Public channels do not require auth'], 403);
        }

        $user = $this->auth->user();
        if (!is_array($user) || !isset($user['id'])) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }
        $userId = (int) $user['id'];

        // Built-in default-deny authorization. Modules can override
        // via registerAuthorizer() for richer rules.
        $allowed = self::authorize($channelName, $user, $userId);
        if (!$allowed) {
            error_log(sprintf(
                '[BroadcastAuth] denied channel="%s" user_id=%d',
                $channelName, $userId
            ));
            return Response::json(['error' => 'Forbidden for this channel'], 403);
        }

        $service = new BroadcastService();
        if (!$service->isEnabled()) {
            return Response::json(['error' => 'Broadcasting not configured'], 503);
        }

        // Presence channels: include the authenticated user's id and a
        // small user_info blob (name + email so subscribers can render
        // a who's-online indicator). user_info SHOULD NOT contain
        // anything secret — it's broadcast to every other subscriber.
        $presenceData = null;
        if (str_starts_with($channelName, 'presence-')) {
            $presenceData = [
                'user_id'   => $userId,
                'user_info' => [
                    'name'  => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['email'] ?? ''),
                    'email' => $user['email'] ?? '',
                ],
            ];
        }

        $response = $service->authChannel($socketId, $channelName, $presenceData);
        if ($response === null) {
            return Response::json(['error' => 'Broadcast auth failed'], 503);
        }

        return Response::json($response);
    }

    /**
     * Decide whether $userId is allowed to subscribe to $channelName.
     * Custom authorizers run first (in registration order); the first
     * one that returns a bool wins. If none decide, fall back to the
     * built-in rules (default-deny).
     */
    private static function authorize(string $channelName, array $user, int $userId): bool
    {
        foreach (self::$authorizers as $fn) {
            try {
                $verdict = $fn($channelName, $user);
                if (is_bool($verdict)) {
                    return $verdict;
                }
            } catch (\Throwable $e) {
                // A throwing authorizer must NOT silently allow. Log
                // + treat as a no-decision so subsequent authorizers
                // or the built-in rules can decide.
                error_log('[BroadcastAuth] authorizer error: ' . $e->getMessage());
            }
        }

        // Built-in patterns:
        //   private-user.{id} / presence-user.{id} → {id} must == userId
        //   private-broadcast / presence-broadcast → any authenticated user
        if (preg_match('/^(?:private|presence)-user\.(\d+)$/', $channelName, $m)) {
            return (int) $m[1] === $userId;
        }
        if ($channelName === 'private-broadcast' || $channelName === 'presence-broadcast') {
            return true;
        }
        // Default-deny: every other pattern needs an explicit authorizer.
        return false;
    }
}
