<?php
// core/Services/BroadcastService.php
namespace Core\Services;

/**
 * Programmatic real-time event broadcasting.
 *
 * Server-side API to publish events to WebSocket channels. Clients
 * (browser-side JS or other servers) subscribe through the provider's
 * native protocol and receive the events with low latency. Providers:
 *
 *   - pusher  — Pusher Channels (hosted)
 *   - soketi  — Soketi (self-hosted, Pusher-compatible protocol)
 *   - ably    — Ably (hosted, JSON REST API)
 *   - none    — Disabled (every call returns false)
 *
 * Config lives in .env (BROADCAST_PROVIDER + provider-specific
 * BROADCAST_* vars). See IntegrationConfig::DEFS['broadcast'] for the
 * full schema or .env.example for the human-readable list.
 *
 * Usage:
 *
 *     $b = new BroadcastService();
 *     if ($b->isEnabled()) {
 *         $b->trigger('user.42', 'notification.created', [
 *             'title' => 'Welcome',
 *             'body'  => 'Your account is ready.',
 *         ]);
 *     }
 *
 * Multi-channel fan-out:
 *
 *     $b->triggerMany(['site.feed', 'user.42'], 'post.published', $payload);
 *
 * Private / presence channels (channel name starts with `private-` or
 * `presence-`) need a server-side auth signature for each subscriber.
 * That handshake happens at /broadcast/auth which delegates to
 * BroadcastService::authChannel(); see BroadcastController.
 *
 * Every method is best-effort + observable: failures are logged to the
 * PHP error log but never thrown. The event payload itself is just
 * data — the framework doesn't enforce a shape — but conventionally
 * use an array that JSON-encodes cleanly.
 *
 * Performance: every trigger() is a synchronous HTTP request to the
 * provider (~30-200ms). For high-throughput emit sites, consider
 * queuing the broadcast as a Job and triggering from the queue worker.
 */
class BroadcastService
{
    private array  $config;
    private string $provider;

    public function __construct()
    {
        $this->config   = IntegrationConfig::config('broadcast');
        $this->provider = (string) ($this->config['driver'] ?? 'none');
    }

    public function isEnabled(): bool
    {
        return IntegrationConfig::enabled('broadcast');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * Browser-safe subset of the broadcast config for client-side
     * SDK initialization. Deliberately excludes secrets — only the
     * fields a client legitimately needs to connect: provider name,
     * public key, cluster, host override. The Ably api_key is also
     * client-safe ONLY when the app intends every browser tab to
     * authenticate as the key holder (which is the case for public
     * channels); apps that want per-user tokens should switch to
     * fetching tokens from /broadcast/auth instead.
     *
     * Returns an empty array when broadcasting isn't configured —
     * callers can `if (empty($cfg)) skip the script emit entirely`.
     *
     * @return array{provider:string,key?:string,cluster?:string,host?:string,api_key?:string}
     */
    public function clientConfig(): array
    {
        if (!$this->isEnabled()) return [];
        $out = ['provider' => $this->provider];
        if ($this->provider === 'pusher' || $this->provider === 'soketi') {
            $out['key']     = (string) ($this->config['key']     ?? '');
            $out['cluster'] = (string) ($this->config['cluster'] ?? 'mt1');
            $host           = (string) ($this->config['host']    ?? '');
            if ($host !== '') $out['host'] = $host;
        } elseif ($this->provider === 'ably') {
            // Ably's api key has a public name portion. For public-channel
            // subscriptions clients can use the full key directly; for
            // private/presence the client should request a token from
            // /broadcast/auth instead (TODO: split here when token auth
            // becomes the primary flow).
            $out['api_key'] = (string) ($this->config['api_key'] ?? '');
        }
        return $out;
    }

    /**
     * Publish $event on $channel with $data payload. Returns true if
     * the provider accepted the request, false otherwise (including
     * the "not configured" case, so callers can wrap unconditionally).
     */
    public function trigger(string $channel, string $event, array $data): bool
    {
        if (!$this->isEnabled() || $channel === '' || $event === '') return false;

        switch ($this->provider) {
            case 'pusher':
            case 'soketi':
                return $this->triggerPusher([$channel], $event, $data);
            case 'ably':
                return $this->triggerAbly($channel, $event, $data);
        }
        return false;
    }

    /**
     * Multi-channel fan-out. Pusher/Soketi accept up to 100 channels
     * per request as one atomic call; Ably's REST API has no batch
     * primitive so we fall through to a per-channel loop (sequential).
     * Returns true only when every channel succeeded.
     */
    public function triggerMany(array $channels, string $event, array $data): bool
    {
        $channels = array_values(array_filter(array_map('strval', $channels), fn($c) => $c !== ''));
        if (!$this->isEnabled() || empty($channels) || $event === '') return false;

        switch ($this->provider) {
            case 'pusher':
            case 'soketi':
                // Pusher caps a single call at 100 channels; chunk if larger.
                foreach (array_chunk($channels, 100) as $batch) {
                    if (!$this->triggerPusher($batch, $event, $data)) return false;
                }
                return true;
            case 'ably':
                foreach ($channels as $c) {
                    if (!$this->triggerAbly($c, $event, $data)) return false;
                }
                return true;
        }
        return false;
    }

    /**
     * Server-side signature for private/presence channel subscriptions.
     * Called from BroadcastController@auth in response to the client's
     * subscription handshake.
     *
     *   - Public channels (no prefix) don't go through auth.
     *   - Private channels (private-…)   need a signed auth string.
     *   - Presence channels (presence-…) need signed auth + channel_data
     *     (a JSON-encoded user identifier + arbitrary user_info blob).
     *
     * Returns the provider's expected response shape, or null when the
     * service is disabled or the channel name doesn't need auth.
     *
     * @param string     $socketId      Provider-issued connection id.
     * @param string     $channel       Full channel name (with private-/presence- prefix).
     * @param array|null $presenceData  ['user_id' => string|int, 'user_info' => array] — required for presence-* channels.
     */
    public function authChannel(string $socketId, string $channel, ?array $presenceData = null): ?array
    {
        if (!$this->isEnabled()) return null;
        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            return null;
        }

        switch ($this->provider) {
            case 'pusher':
            case 'soketi':
                return $this->authPusher($socketId, $channel, $presenceData);
            case 'ably':
                return $this->authAbly($channel, $presenceData);
        }
        return null;
    }

    // ── Pusher / Soketi ─────────────────────────────────────────────

    /**
     * Pusher Channels REST API. Same wire protocol works for Soketi.
     *   POST /apps/{app_id}/events
     *   body: { name, channels: [...], data: "<JSON string>" }
     *   auth: query-string HMAC-SHA256 signature of the canonical request.
     */
    private function triggerPusher(array $channels, string $event, array $data): bool
    {
        $appId  = (string) ($this->config['app_id'] ?? '');
        $key    = (string) ($this->config['key']    ?? '');
        $secret = (string) ($this->config['secret'] ?? '');
        if ($appId === '' || $key === '' || $secret === '') {
            error_log('[broadcast] Pusher: missing app_id/key/secret');
            return false;
        }

        $payload = json_encode([
            'name'     => $event,
            'channels' => array_values($channels),
            'data'     => json_encode($data, JSON_UNESCAPED_SLASHES),
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            error_log('[broadcast] Pusher: failed to encode payload');
            return false;
        }

        $path = "/apps/$appId/events";
        $url  = $this->pusherBaseUrl() . $path;

        $params = [
            'auth_key'       => $key,
            'auth_timestamp' => (string) time(),
            'auth_version'   => '1.0',
            'body_md5'       => md5($payload),
        ];
        // Canonical signing string: METHOD\n/path\nkey1=v1&key2=v2…
        ksort($params);
        $queryString = http_build_query($params, '', '&');
        $stringToSign = "POST\n$path\n$queryString";
        $params['auth_signature'] = hash_hmac('sha256', $stringToSign, $secret);

        $finalUrl = $url . '?' . http_build_query($params, '', '&');
        return $this->httpPost($finalUrl, $payload, ['Content-Type: application/json']);
    }

    /**
     * Channel-auth response for Pusher-compatible providers.
     *   private-*: { auth: "<key>:<hmac(socket_id:channel)>" }
     *   presence-*: { auth, channel_data: "<JSON>" } where channel_data
     *               is included in the signed payload.
     */
    private function authPusher(string $socketId, string $channel, ?array $presenceData): ?array
    {
        $key    = (string) ($this->config['key']    ?? '');
        $secret = (string) ($this->config['secret'] ?? '');
        if ($key === '' || $secret === '') return null;

        if (str_starts_with($channel, 'presence-')) {
            if ($presenceData === null || !isset($presenceData['user_id'])) {
                error_log('[broadcast] Pusher: presence channel auth requires user_id');
                return null;
            }
            $channelDataJson = json_encode([
                'user_id'   => $presenceData['user_id'],
                'user_info' => $presenceData['user_info'] ?? new \stdClass(),
            ], JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha256', "$socketId:$channel:$channelDataJson", $secret);
            return [
                'auth'         => "$key:$signature",
                'channel_data' => $channelDataJson,
            ];
        }

        // private-* channel
        $signature = hash_hmac('sha256', "$socketId:$channel", $secret);
        return ['auth' => "$key:$signature"];
    }

    /**
     * Pusher base URL. Pusher hosted: api-{cluster}.pusher.com.
     * Soketi self-hosted: BROADCAST_HOST is the override.
     */
    private function pusherBaseUrl(): string
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        if ($host !== '') {
            // Self-hosted Soketi or custom Pusher host. Accept full URL
            // or bare host; we'll build the rest.
            if (!preg_match('#^https?://#i', $host)) {
                $host = 'https://' . $host;
            }
            return rtrim($host, '/');
        }
        $cluster = trim((string) ($this->config['cluster'] ?? ''));
        if ($cluster === '') $cluster = 'mt1'; // Pusher's default cluster
        return "https://api-$cluster.pusher.com";
    }

    // ── Ably ────────────────────────────────────────────────────────

    /**
     * Ably REST API.
     *   POST https://rest.ably.io/channels/{channel}/messages
     *   Auth: HTTP Basic with KEY_NAME:KEY_SECRET (the BROADCAST_ABLY_API_KEY
     *         env var holds the full "name:secret" form).
     *   body: { name, data }
     */
    private function triggerAbly(string $channel, string $event, array $data): bool
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            error_log('[broadcast] Ably: missing BROADCAST_ABLY_API_KEY');
            return false;
        }

        $url     = 'https://rest.ably.io/channels/' . rawurlencode($channel) . '/messages';
        $payload = json_encode(['name' => $event, 'data' => $data], JSON_UNESCAPED_SLASHES);
        if ($payload === false) return false;

        return $this->httpPost($url, $payload, [
            'Authorization: Basic ' . base64_encode($apiKey),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /**
     * Ably channel auth. For private/presence channels we issue a
     * short-lived token (TTL 1 hour) scoped to the requested channel.
     * Client uses this as its authToken to subscribe.
     */
    private function authAbly(string $channel, ?array $presenceData): ?array
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') return null;

        // Ably's token-request shape: client_id + capability (channel -> [subscribe, presence])
        $capability = json_encode([$channel => ['subscribe', 'presence']]);
        $tokenReq = [
            'capability' => $capability,
            'ttl'        => 3600 * 1000, // ms
        ];
        if ($presenceData !== null && isset($presenceData['user_id'])) {
            $tokenReq['clientId'] = (string) $presenceData['user_id'];
        }

        $url     = 'https://rest.ably.io/keys/' . rawurlencode(explode(':', $apiKey, 2)[0]) . '/requestToken';
        $payload = json_encode($tokenReq, JSON_UNESCAPED_SLASHES);
        if ($payload === false) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($apiKey),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 6,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || $body === false) {
            error_log("[broadcast] Ably token request failed: HTTP $code");
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── HTTP helper ─────────────────────────────────────────────────

    private function httpPost(string $url, string $body, array $headers): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[broadcast] curl failed for $url: $err");
            return false;
        }
        if ($code < 200 || $code >= 300) {
            error_log("[broadcast] $url returned HTTP $code: " . substr((string) $response, 0, 200));
            return false;
        }
        return true;
    }
}
