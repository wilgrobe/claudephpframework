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
 * Authorization rules in v1: the user must be authenticated. The
 * channel name itself is opaque — we don't parse `private-user.{id}`
 * to verify that {id} matches $auth->id(). For tighter rules, apps
 * can subclass or wrap the trigger in their own controller.
 *
 * Public (unprefixed) channels don't go through this endpoint —
 * clients subscribe directly with no auth.
 *
 * Endpoint contract:
 *   POST /broadcast/auth
 *   form fields: socket_id, channel_name
 *   response:    JSON shape that the provider's client library expects
 *                (pusher: {auth, channel_data?}, ably: {token request})
 *
 * 401 if not authenticated; 403 if the channel name isn't a private/
 * presence channel (those don't need auth — clients shouldn't be
 * asking); 503 if the broadcast service is disabled or returns null.
 */
class BroadcastAuthController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
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
            $user = $this->auth->user();
            $presenceData = [
                'user_id'   => (int) ($user['id'] ?? 0),
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
}
