<?php
// core/Services/MediaCdnService.php
namespace Core\Services;

/**
 * Optional media-CDN / image-transformation layer.
 *
 * When MEDIA_CDN_PROVIDER is configured (cloudinary, imgix) the app's
 * stored image URLs get rewritten through the CDN so that on-the-fly
 * resize / crop / format conversion happens at edge. Storage itself
 * (local or S3) is unchanged — the rewrite happens only at URL-render
 * time via FileUploadService::url().
 *
 * Non-image files (PDFs, docs, zips, etc.) pass through untransformed.
 *
 * Disabled when MEDIA_CDN_PROVIDER=none; rewrite() returns the input URL
 * unchanged so nothing else in the pipeline needs to know about this.
 */
class MediaCdnService
{
    /** Extensions we consider "transformable" — everything else passes through. */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'svg'];

    public static function isEnabled(): bool
    {
        return IntegrationConfig::enabled('media_cdn');
    }

    /**
     * Rewrite a public URL through the configured CDN, or return it
     * unchanged when the CDN is disabled or the URL isn't a transformable
     * image.
     *
     * @param array $transforms  Optional transforms, e.g. ['w' => 800, 'q' => 80].
     *                           Keys are provider-agnostic: w (width), h
     *                           (height), q (quality), fit (cover|contain),
     *                           fmt (auto|webp|avif). Unknown keys are ignored.
     */
    public static function rewrite(string $url, array $transforms = []): string
    {
        if (!self::isEnabled() || $url === '') return $url;
        if (!self::isTransformable($url))     return $url;

        $cfg      = IntegrationConfig::config('media_cdn');
        $provider = (string) ($cfg['driver'] ?? 'none');

        switch ($provider) {
            case 'cloudinary': return self::cloudinary($url, $cfg, $transforms);
            case 'imgix':      return self::imgix($url, $cfg, $transforms);
            case 'cloudflare': return self::cloudflare($url, $cfg, $transforms);
        }
        return $url;
    }

    private static function isTransformable(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXT, true);
    }

    /**
     * Cloudinary "fetch" URL: the CDN loads the source from our origin,
     * applies transforms, and caches the result. No sync step required —
     * works with any public origin URL.
     *
     *   https://res.cloudinary.com/{cloud_name}/image/fetch/{ops}/{source}
     */
    private static function cloudinary(string $url, array $cfg, array $transforms): string
    {
        $cloud = (string) ($cfg['cloud_name'] ?? '');
        if ($cloud === '') return $url;

        $ops = [];
        if (!empty($transforms['w']))   $ops[] = 'w_' . (int) $transforms['w'];
        if (!empty($transforms['h']))   $ops[] = 'h_' . (int) $transforms['h'];
        if (!empty($transforms['q']))   $ops[] = 'q_' . (int) $transforms['q'];
        if (!empty($transforms['fit']) && in_array($transforms['fit'], ['cover','contain','fill','crop'], true)) {
            $map = ['cover' => 'fill', 'contain' => 'fit', 'fill' => 'fill', 'crop' => 'crop'];
            $ops[] = 'c_' . $map[$transforms['fit']];
        }
        if (!empty($transforms['fmt']) && in_array($transforms['fmt'], ['auto','webp','avif','jpg','png'], true)) {
            $ops[] = 'f_' . $transforms['fmt'];
        }
        // Sensible default: auto-format + quality auto when no explicit
        // transforms are provided. Lets Cloudinary pick webp/avif when the
        // browser supports it, at around 70 quality.
        if (empty($ops)) $ops = ['f_auto', 'q_auto'];

        $prefix = "https://res.cloudinary.com/$cloud/image/fetch/" . implode(',', $ops);
        return $prefix . '/' . $url;
    }

    /**
     * imgix expects the bucket/origin to already be mapped to the imgix
     * domain; we only pass the path portion + query-string transforms.
     *
     *   https://{domain}.imgix.net/{path}?w=800&auto=format
     *
     * If a signing key is configured, we append a path-based signature so
     * clients can't request arbitrary transforms. imgix accepts signed
     * URLs via &s=<md5(signing_key + path + query)>.
     */
    private static function imgix(string $url, array $cfg, array $transforms): string
    {
        $domain  = trim((string) ($cfg['domain'] ?? ''));
        if ($domain === '') return $url;
        $domain  = rtrim($domain, '/');
        if (!preg_match('#^https?://#i', $domain)) $domain = 'https://' . $domain;

        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        $params = [];
        if (!empty($transforms['w']))   $params['w']       = (int) $transforms['w'];
        if (!empty($transforms['h']))   $params['h']       = (int) $transforms['h'];
        if (!empty($transforms['q']))   $params['q']       = (int) $transforms['q'];
        if (!empty($transforms['fit'])) $params['fit']     = $transforms['fit'];
        if (!empty($transforms['fmt'])) $params['fm']      = $transforms['fmt'];
        if (empty($params))             $params['auto']    = 'format,compress';

        $qs = http_build_query($params);

        $signingKey = (string) ($cfg['signing_key'] ?? '');
        if ($signingKey !== '') {
            $signature = md5($signingKey . $path . ($qs ? "?$qs" : ''));
            $qs       .= ($qs ? '&' : '') . 's=' . $signature;
        }

        return $domain . $path . ($qs ? "?$qs" : '');
    }

    /**
     * Cloudflare Images uses a URL shape of:
     *   https://imagedelivery.net/{delivery_hash}/{image_id}/{variant}
     *
     * We only know the origin URL of the image, not an image id that's
     * been uploaded to Cloudflare Images — so we use the "image URL
     * rewrite" variant syntax via the fetch-transform feature:
     *   /cdn-cgi/image/{options}/{source}
     *
     * When MEDIA_CDN_CLOUDFLARE_DELIVERY_HASH is set, we use the
     * transform-variant URL pattern targeting the Cloudflare zone that
     * the image delivery hash belongs to. Apps that host the original
     * image behind Cloudflare already (via their own domain) benefit
     * most from this path — Cloudflare fetches, caches, and transforms
     * at edge.
     */
    private static function cloudflare(string $url, array $cfg, array $transforms): string
    {
        $hash = (string) ($cfg['delivery_hash'] ?? '');
        if ($hash === '') return $url;

        $ops = [];
        if (!empty($transforms['w']))   $ops[] = 'width='   . (int) $transforms['w'];
        if (!empty($transforms['h']))   $ops[] = 'height='  . (int) $transforms['h'];
        if (!empty($transforms['q']))   $ops[] = 'quality=' . (int) $transforms['q'];
        if (!empty($transforms['fit']) && in_array($transforms['fit'], ['cover','contain','scale-down','crop','pad'], true)) {
            $ops[] = 'fit=' . $transforms['fit'];
        }
        if (!empty($transforms['fmt']) && in_array($transforms['fmt'], ['auto','webp','avif','json'], true)) {
            $ops[] = 'format=' . $transforms['fmt'];
        }
        if (empty($ops)) $ops = ['format=auto', 'quality=85'];

        // Cloudflare's fetch-transform variant format: the options list
        // sits between /cdn-cgi/image/ and the source URL.
        return 'https://imagedelivery.net/' . rawurlencode($hash) . '/cdn-cgi/image/'
             . implode(',', $ops) . '/' . $url;
    }
}
