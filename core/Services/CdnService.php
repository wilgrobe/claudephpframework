<?php
// core/Services/CdnService.php
namespace Core\Services;

/**
 * Programmatic CDN operations — primarily cache purge / invalidation.
 *
 * Distinct from ASSET_URL (which only rewrites public URLs). This
 * service is what you reach for after a deploy or content update when
 * you need to invalidate cached paths at the edge.
 *
 * Providers: cloudflare, cloudfront, fastly, bunny, none.
 *
 * Every method no-ops gracefully when the service isn't configured —
 * isEnabled() is the gate, but calling purge() on a disabled service
 * just returns false without throwing.
 *
 * Cloudflare, Fastly, and BunnyCDN all expose straightforward HTTPS
 * APIs we can call directly. AWS CloudFront invalidation uses the
 * CloudFront API which requires AWS Signature v4; we delegate to the
 * AWS SDK for PHP when it's installed, and log + return false when
 * it isn't.
 */
class CdnService
{
    private array  $config;
    private string $provider;

    public function __construct()
    {
        $this->config   = IntegrationConfig::config('cdn');
        $this->provider = (string) ($this->config['driver'] ?? 'none');
    }

    public function isEnabled(): bool
    {
        return IntegrationConfig::enabled('cdn');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * Purge one or more paths (or full URLs) from the CDN cache.
     * Returns true when the purge request was accepted, false
     * otherwise. The actual invalidation is asynchronous on every
     * provider — true means "queued," not "already gone."
     *
     * @param array $paths  Array of paths (/assets/app.css) or full URLs.
     */
    public function purge(array $paths): bool
    {
        if (!$this->isEnabled() || empty($paths)) return false;

        switch ($this->provider) {
            case 'cloudflare': return $this->purgeCloudflare($paths);
            case 'cloudfront': return $this->purgeCloudfront($paths);
            case 'fastly':     return $this->purgeFastly($paths);
            case 'bunny':      return $this->purgeBunny($paths);
        }
        return false;
    }

    /**
     * Purge the entire CDN cache. Supported where the provider has a
     * "purge everything" API; returns false for providers that don't.
     * Use sparingly — most providers rate-limit whole-cache purges.
     */
    public function purgeEverything(): bool
    {
        if (!$this->isEnabled()) return false;

        switch ($this->provider) {
            case 'cloudflare':
                return $this->cloudflareCall('/zones/' . rawurlencode((string) $this->config['zone_id']) . '/purge_cache', [
                    'purge_everything' => true,
                ]);
            case 'fastly':
                return $this->fastlyCall('/service/' . rawurlencode((string) $this->config['service_id']) . '/purge_all', []);
            case 'bunny':
                return $this->bunnyCall('/pullzone/' . rawurlencode((string) $this->config['pull_zone_id']) . '/purgeCache', 'POST');
            case 'cloudfront':
                // CloudFront invalidation supports /* as a wildcard —
                // effectively "purge everything."
                return $this->purgeCloudfront(['/*']);
        }
        return false;
    }

    // ── Provider: Cloudflare ────────────────────────────────────────────────

    private function purgeCloudflare(array $paths): bool
    {
        $zone = (string) ($this->config['zone_id'] ?? '');
        if ($zone === '') return false;

        // Cloudflare accepts "files" for exact URLs. It also accepts
        // "prefixes" and "tags" (Enterprise). We normalize to full URLs
        // by joining with APP_URL when the caller gave us bare paths.
        $files = array_map(function ($p) {
            if (preg_match('#^https?://#i', $p)) return $p;
            $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            return $base . '/' . ltrim($p, '/');
        }, $paths);

        return $this->cloudflareCall("/zones/" . rawurlencode($zone) . "/purge_cache", ['files' => $files]);
    }

    private function cloudflareCall(string $path, array $body): bool
    {
        $token = (string) ($this->config['api_token'] ?? '');
        if ($token === '') return false;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
            'content'       => json_encode($body),
            'timeout'       => 10.0,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents("https://api.cloudflare.com/client/v4$path", false, $ctx);
        if ($raw === false) return false;
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['success']);
    }

    // ── Provider: AWS CloudFront ────────────────────────────────────────────

    /**
     * CloudFront invalidation via the AWS SDK for PHP when available.
     * We don't reimplement SigV4 here — if the app is using S3 for
     * storage the SDK is likely already installed, and if not, the
     * warning message tells the admin exactly what to do.
     */
    private function purgeCloudfront(array $paths): bool
    {
        if (!class_exists(\Aws\CloudFront\CloudFrontClient::class)) {
            error_log('[cdn:cloudfront] AWS SDK for PHP is not installed. `composer require aws/aws-sdk-php` to enable CloudFront invalidation.');
            return false;
        }
        try {
            $client = new \Aws\CloudFront\CloudFrontClient([
                'version'     => 'latest',
                'region'      => (string) ($this->config['aws_region'] ?? 'us-east-1'),
                'credentials' => [
                    'key'    => (string) ($this->config['aws_access_key'] ?? ''),
                    'secret' => (string) ($this->config['aws_secret_key'] ?? ''),
                ],
            ]);
            // Paths must be absolute (start with /). Normalize.
            $normalized = array_map(function ($p) {
                $path = parse_url($p, PHP_URL_PATH) ?? $p;
                return '/' . ltrim($path, '/');
            }, $paths);

            $res = $client->createInvalidation([
                'DistributionId'    => (string) ($this->config['distribution_id'] ?? ''),
                'InvalidationBatch' => [
                    'Paths'           => ['Quantity' => count($normalized), 'Items' => $normalized],
                    'CallerReference' => 'cdn-purge-' . bin2hex(random_bytes(8)),
                ],
            ]);
            return !empty($res['Invalidation']['Id']);
        } catch (\Throwable $e) {
            error_log('[cdn:cloudfront] ' . $e->getMessage());
            return false;
        }
    }

    // ── Provider: Fastly ────────────────────────────────────────────────────

    private function purgeFastly(array $paths): bool
    {
        $service = (string) ($this->config['service_id'] ?? '');
        $token   = (string) ($this->config['api_token']  ?? '');
        if ($service === '' || $token === '') return false;

        // Fastly doesn't have a "purge by list of paths" API on a plain
        // service; instead you PURGE each URL individually. For small
        // batches this is fine; for larger purges, use surrogate keys.
        $anyFailed = false;
        foreach ($paths as $p) {
            if (!preg_match('#^https?://#i', $p)) {
                $p = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/' . ltrim($p, '/');
            }
            $ctx = stream_context_create(['http' => [
                'method'        => 'PURGE',
                'header'        => "Fastly-Key: $token\r\n",
                'timeout'       => 10.0,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($p, false, $ctx);
            if ($raw === false) $anyFailed = true;
        }
        return !$anyFailed;
    }

    private function fastlyCall(string $path, array $body): bool
    {
        $token = (string) ($this->config['api_token'] ?? '');
        if ($token === '') return false;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Fastly-Key: $token\r\nAccept: application/json\r\n",
            'timeout'       => 10.0,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents("https://api.fastly.com$path", false, $ctx);
        return $raw !== false;
    }

    // ── Provider: BunnyCDN ──────────────────────────────────────────────────

    private function purgeBunny(array $paths): bool
    {
        // Bunny doesn't expose a batch purge-by-URL on the standard API;
        // it has a per-URL purge endpoint. For convenience we iterate.
        $key = (string) ($this->config['api_key'] ?? '');
        if ($key === '') return false;

        $anyFailed = false;
        foreach ($paths as $p) {
            if (!preg_match('#^https?://#i', $p)) {
                $p = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/' . ltrim($p, '/');
            }
            $url = 'https://api.bunny.net/purge?url=' . rawurlencode($p);
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "AccessKey: $key\r\n",
                'timeout'       => 10.0,
                'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) $anyFailed = true;
        }
        return !$anyFailed;
    }

    private function bunnyCall(string $path, string $method): bool
    {
        $key = (string) ($this->config['api_key'] ?? '');
        if ($key === '') return false;
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => "AccessKey: $key\r\n",
            'timeout'       => 10.0,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents("https://api.bunny.net$path", false, $ctx);
        return $raw !== false;
    }
}
