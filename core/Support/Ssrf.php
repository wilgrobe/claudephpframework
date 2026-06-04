<?php
// core/Support/Ssrf.php
namespace Core\Support;

/**
 * Canonical SSRF guard for outbound requests to user/admin-supplied URLs.
 *
 * Single source of truth (was: a guardSsrf() copy in automation + webhooks-
 * gateway, and several unguarded outbound sites in marketing/store/etc.).
 *
 * assertPublicUrl():
 *   - requires http/https scheme
 *   - rejects loopback / RFC-1918 / link-local (169.254.*, fe80:) / unique-
 *     local (fc/fd) / reserved ranges — checked against EVERY resolved A/AAAA,
 *     not just the hostname, so a public-looking name that resolves to an
 *     internal IP is refused (and an IMDS literal like 169.254.169.254 too)
 *   - returns the resolved IPs so the caller can PIN curl to them
 *
 * hardenCurl():
 *   - CURLOPT_FOLLOWLOCATION off (a 302→internal can't bypass the check)
 *   - CURLOPT_PROTOCOLS / REDIR_PROTOCOLS limited to http/https (no file://,
 *     gopher://, dict://)
 *   - CURLOPT_RESOLVE pins host→validated-IP so curl can't re-resolve to a
 *     DNS-rebind target between our check and its connect (TLS still verifies
 *     against the hostname — CURLOPT_RESOLVE only overrides the IP, SNI/Host/
 *     cert validation use the original host).
 */
final class Ssrf
{
    /**
     * @param string[] $extraAllowHosts hostnames exempt from the private-IP
     *                 reject (operator-configured internal endpoints)
     * @return array{scheme:string,host:string,port:int,ips:string[]}
     * @throws \RuntimeException on any unsafe condition
     */
    public static function assertPublicUrl(string $url, array $extraAllowHosts = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('SSRF guard: invalid URL');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("SSRF guard: scheme must be http or https (got '$scheme')");
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        // Strip the brackets parse_url keeps on IPv6 literals ([::1] → ::1) so
        // the literal + filter_var checks below see the bare address.
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new \RuntimeException('SSRF guard: missing host');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $allow = array_filter(array_map(static fn($h) => strtolower(trim((string) $h)), $extraAllowHosts));
        $allowed = in_array($host, $allow, true);

        // Cheap literal/pattern reject (no DNS) — also covers the case where a
        // resolver can't see an internal-only name.
        if (!$allowed) {
            if ($host === 'localhost' || $host === '0.0.0.0' || $host === '::1'
                || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
                throw new \RuntimeException("SSRF guard: refusing internal host ($host)");
            }
        }

        // Literal-IP host: validate directly, no resolution needed.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$allowed) self::assertPublicIp($host, $host);
            return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => [$host]];
        }

        $ips = self::resolve($host);
        if (!$allowed) {
            foreach ($ips as $ip) self::assertPublicIp($ip, $host);
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    private static function assertPublicIp(string $ip, string $host): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException("SSRF guard: refusing host '$host' (resolves to non-public IP $ip)");
        }
    }

    /** @return string[] resolved A/AAAA addresses (best-effort) */
    private static function resolve(string $host): array
    {
        $ips = [];
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                $ip = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
                if ($ip !== '') $ips[] = $ip;
            }
        }
        if (empty($ips)) {
            $a = @gethostbyname($host);
            if ($a !== $host && filter_var($a, FILTER_VALIDATE_IP)) $ips[] = $a;
        }
        return array_values(array_unique($ips));
    }

    /**
     * Harden a curl handle for a URL already validated by assertPublicUrl().
     * @param \CurlHandle|resource $ch
     * @param array{scheme:string,host:string,port:int,ips:string[]} $checked
     */
    public static function hardenCurl($ch, array $checked): void
    {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $host = (string) ($checked['host'] ?? '');
        $port = (int) ($checked['port'] ?? 0);
        $ips  = $checked['ips'] ?? [];
        if ($host !== '' && $port > 0 && !empty($ips)) {
            $entries = [];
            foreach ($ips as $ip) $entries[] = "$host:$port:$ip";
            curl_setopt($ch, CURLOPT_RESOLVE, $entries);
        }
    }

    /** Build a stream-context http option array that forbids redirects (for file_get_contents callers). */
    public static function streamHttpOpts(array $base = []): array
    {
        return array_merge($base, ['follow_location' => 0, 'max_redirects' => 0]);
    }

    /** Parse a comma-separated env var into a lowercased host allowlist. */
    public static function allowlistFromEnv(string $envKey): array
    {
        $env = (string) ($_ENV[$envKey] ?? getenv($envKey) ?: '');
        $out = [];
        foreach (explode(',', $env) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '') $out[] = $h;
        }
        return $out;
    }
}
