<?php
// core/Http/TrustedProxy.php
namespace Core\Http;

/**
 * Restore the true client IP when the app sits behind a trusted CDN/proxy
 * (Cloudflare). When Cloudflare proxies a request, the TCP peer PHP sees in
 * $_SERVER['REMOTE_ADDR'] is a Cloudflare EDGE ip, and the real visitor ip is
 * carried in the CF-Connecting-IP header. Without restoration, everything that
 * keys on REMOTE_ADDR — IP rate-limiting (login/signup), audit-log ip_address,
 * CAPTCHA remoteip — sees the handful of Cloudflare edges instead of the actual
 * visitor, which both defeats per-visitor rate-limiting and pollutes logs.
 *
 * SECURITY: CF-Connecting-IP is trusted ONLY when the immediate peer
 * (REMOTE_ADDR) is inside Cloudflare's published ip ranges. A client hitting
 * the origin ip directly (bypassing Cloudflare) and forging CF-Connecting-IP
 * arrives with REMOTE_ADDR = their own public ip (not a Cloudflare range), so
 * the header is ignored and their real ip stands. This is why we range-check
 * rather than blindly trusting the header (cf. the X-Forwarded-For spoofing
 * note in Request::ip()).
 *
 * Idempotent + safe on every host:
 *   - Hosts that already restore the ip at the web-server level (e.g. DreamHost
 *     runs mod_remoteip) hand us a REMOTE_ADDR that is the real client — not a
 *     Cloudflare range — so isCloudflareIp() is false and we no-op.
 *   - Bare hosts (a failover droplet, a standalone Builder kit) get the
 *     restoration they'd otherwise lack.
 *
 * Cloudflare ranges below are the published lists (https://www.cloudflare.com/ips/).
 * They change rarely; refresh if Cloudflare ever expands them.
 */
class TrustedProxy
{
    /** Cloudflare IPv4 ranges — https://www.cloudflare.com/ips-v4 */
    private const CLOUDFLARE_V4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /** Cloudflare IPv6 ranges — https://www.cloudflare.com/ips-v6 */
    private const CLOUDFLARE_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * If the request came through Cloudflare, overwrite REMOTE_ADDR with the
     * real client ip from CF-Connecting-IP. No-op otherwise. Call once, early,
     * in the web entry point (before anything reads the client ip).
     */
    public static function restoreClientIp(): void
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $cf     = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($remote === '' || $cf === '') {
            return;
        }
        // Only trust the header when the direct peer is genuinely Cloudflare.
        if (!self::isCloudflareIp($remote)) {
            return;
        }
        // CF-Connecting-IP is a single ip; validate before trusting it.
        if (!filter_var($cf, FILTER_VALIDATE_IP)) {
            return;
        }
        // Preserve the edge ip for debugging; make the real client authoritative.
        $_SERVER['CF_EDGE_IP']  = $remote;
        $_SERVER['REMOTE_ADDR'] = $cf;
    }

    /** True when $ip falls inside any Cloudflare published range (v4 or v6). */
    public static function isCloudflareIp(string $ip): bool
    {
        if (strpos($ip, ':') !== false) {
            foreach (self::CLOUDFLARE_V6 as $cidr) {
                if (self::inRangeV6($ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }
        foreach (self::CLOUDFLARE_V4 as $cidr) {
            if (self::inRangeV4($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function inRangeV4(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits === 0) {
            return true;
        }
        // 32-bit mask; guard the shift for the /0 edge already handled above.
        $mask = -1 << (32 - $bits);
        return ((int) $ipLong & $mask) === ((int) $subnetLong & $mask);
    }

    private static function inRangeV6(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== 16 || strlen($subnetBin) !== 16) {
            return false;
        }
        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        // Compare whole bytes covered by the prefix.
        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        // Compare the partial trailing byte, if any.
        if ($rem !== 0) {
            $mask = ~(0xFF >> $rem) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }
}
