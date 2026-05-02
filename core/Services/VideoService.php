<?php
// core/Services/VideoService.php
namespace Core\Services;

/**
 * Scaffolding for video hosting (Mux or Cloudflare Stream).
 *
 * This class is intentionally thin — the framework doesn't know anything
 * about your video model (lessons, course modules, movies, etc.), so the
 * upload / player / webhook specifics live in the app. What the service
 * provides is:
 *
 *   - Credential management (all from .env)
 *   - isEnabled() guard so feature code can gate cleanly
 *   - signedPlaybackUrl() for Mux signed playback (HMAC-signed JWT),
 *     which is the one piece of the integration that benefits from
 *     being centralized
 *
 * When you're ready to add actual video uploading, reach for the
 * provider's API directly (Mux REST API, Cloudflare Stream API) using
 * the credentials this class exposes.
 */
class VideoService
{
    private array  $config;
    private string $provider;

    public function __construct()
    {
        $this->config   = IntegrationConfig::config('video');
        $this->provider = (string) ($this->config['driver'] ?? 'none');
    }

    public function isEnabled(): bool
    {
        return IntegrationConfig::enabled('video');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** Full config map — credentials for the active provider. */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Build a signed playback URL for a piece of content. Currently
     * implemented for Mux; Cloudflare Stream uses a different signing
     * flow (per-video signed tokens via the API) that needs a live HTTP
     * call and is out of scope here.
     *
     * For Mux: given a playback_id and a signing-key pair, issue a short
     * JWT that Mux validates. Returns null when the provider doesn't
     * support local signing or credentials are missing.
     *
     * @param string $playbackId  Provider-specific asset id.
     * @param int    $ttlSeconds  Token lifetime.
     */
    public function signedPlaybackUrl(string $playbackId, int $ttlSeconds = 3600): ?string
    {
        if (!$this->isEnabled()) return null;

        if ($this->provider === 'mux') {
            $kid = (string) ($this->config['signing_key_id'] ?? '');
            $key = (string) ($this->config['signing_key']    ?? '');
            if ($kid === '' || $key === '') return null;

            $jwt = $this->muxSignJwt($playbackId, $kid, $key, $ttlSeconds);
            return "https://stream.mux.com/$playbackId.m3u8?token=$jwt";
        }

        // Vimeo playback works via the embed URL — no server-side signing
        // is needed for private videos when the app uses Vimeo's domain
        // privacy settings. Return the canonical embed URL so callers can
        // render an iframe.
        if ($this->provider === 'vimeo') {
            // $playbackId is the numeric Vimeo video id.
            return "https://player.vimeo.com/video/" . rawurlencode($playbackId);
        }

        // Cloudflare Stream playback URLs are signed via the API, not
        // locally. AWS IVS streams are public-by-default at the ingest
        // layer; private playback needs an IAM-signed tag via the AWS SDK.
        // Return null for both so callers can gate and implement their
        // own signing flow.
        return null;
    }

    /**
     * Vimeo embed URL for an iframe player. Accepts transform params
     * (autoplay, loop, muted, quality, h for private-hash videos).
     * Returns null when the provider isn't Vimeo.
     */
    public function vimeoEmbedUrl(string $videoId, array $params = []): ?string
    {
        if (!$this->isEnabled() || $this->provider !== 'vimeo') return null;
        $base = "https://player.vimeo.com/video/" . rawurlencode($videoId);
        return $params ? $base . '?' . http_build_query($params) : $base;
    }

    /**
     * Fetch a Vimeo video's metadata (title, description, duration, HLS
     * URL, thumbnails). Requires VIDEO_VIMEO_ACCESS_TOKEN. Returns null
     * on any failure.
     */
    public function vimeoMetadata(string $videoId): ?array
    {
        if (!$this->isEnabled() || $this->provider !== 'vimeo') return null;
        $token = (string) ($this->config['access_token'] ?? '');
        if ($token === '') return null;

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $token\r\nAccept: application/vnd.vimeo.*+json;version=3.4\r\n",
            'timeout'       => 10.0,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents("https://api.vimeo.com/videos/$videoId", false, $ctx);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Minimal JWT signer for Mux playback tokens. Base64URL encodes
     * header + payload, HMACs with the PEM private key passed in as a
     * base64 string (Mux's convention). Output: header.payload.signature.
     *
     * Mux expects RS256-signed JWTs, which need openssl_sign with a PEM
     * private key. We assume $key is the base64-encoded PEM as provided
     * in Mux's dashboard.
     */
    private function muxSignJwt(string $playbackId, string $kid, string $b64Key, int $ttl): string
    {
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
        $now    = time();
        $body   = self::b64url(json_encode([
            'sub' => $playbackId,
            'aud' => 'v',         // 'v' = video (Mux convention)
            'exp' => $now + $ttl,
            'iat' => $now,
        ]));

        $pem       = base64_decode($b64Key, true);
        $signature = '';
        if ($pem !== false && openssl_sign("$header.$body", $signature, $pem, OPENSSL_ALGO_SHA256)) {
            return "$header.$body." . self::b64url($signature);
        }
        // Signature failed — return an unsigned token so callers can tell
        // via the 403 from Mux that the key didn't work. Better than a
        // silent empty string.
        return "$header.$body.";
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
