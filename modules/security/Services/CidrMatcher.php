<?php
// modules/security/Services/CidrMatcher.php
namespace Modules\Security\Services;

/**
 * Tiny IPv4 + IPv6 CIDR matcher with no external dependencies.
 *
 *   matches('192.168.1.5', ['192.168.0.0/16'])  → true
 *   matches('10.0.0.1',    ['192.168.0.0/16'])  → false
 *   matches('203.0.113.5', ['203.0.113.5'])     → true   (bare IP = /32)
 *   matches('::1',         ['::1/128'])         → true
 *   matches('2001:db8::1', ['2001:db8::/32'])   → true
 *
 * Bare IPs without a `/N` are treated as exact-match (/32 v4, /128 v6).
 * IPv4-in-IPv6 (::ffff:1.2.3.4) is normalised before matching.
 *
 * Used by AdminIpAllowlistMiddleware to gate /admin/* against the
 * configured allowlist. Designed to be cheap — pure inet_pton +
 * bitstring compare, no DNS lookups.
 */
class CidrMatcher
{
    /**
     * @param string                $ip       The IP being checked
     * @param string[]|string       $cidrList Array of CIDR strings, OR a
     *                                        single comma/newline-separated string
     */
    public static function matches(string $ip, array|string $cidrList): bool
    {
        $entries = is_array($cidrList) ? $cidrList : self::parseList($cidrList);
        $ip      = self::normalise($ip);
        $ipBin   = @inet_pton($ip);
        if ($ipBin === false) return false;

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            if (self::matchOne($ipBin, $entry)) return true;
        }
        return false;
    }

    /**
     * Split a textual list (comma or newline-separated) into an array
     * of trimmed entries. Lines starting with `#` are treated as
     * comments + skipped — handy for admin notes inside the setting.
     *
     * @return string[]
     */
    public static function parseList(string $text): array
    {
        $parts = preg_split('/[\s,]+/', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || str_starts_with($p, '#')) continue;
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Validate that every entry parses as a valid CIDR or bare IP.
     * Returns null on success, or a human error message describing
     * the first bad entry.
     */
    public static function validate(string $text): ?string
    {
        foreach (self::parseList($text) as $entry) {
            if (self::cidrToBits($entry) === null) {
                return 'Not a valid IP / CIDR: "' . $entry . '"';
            }
        }
        return null;
    }

    private static function matchOne(string $ipBin, string $entry): bool
    {
        $bits = self::cidrToBits($entry);
        if ($bits === null) return false;
        [$netBin, $prefixBits] = $bits;

        // Both IPs must be the same family (4 or 16 bytes after pton).
        if (strlen($ipBin) !== strlen($netBin)) return false;

        // Compare $prefixBits bits. We compare full bytes first, then
        // the partial trailing byte if the prefix isn't byte-aligned.
        $fullBytes = intdiv($prefixBits, 8);
        $remBits   = $prefixBits % 8;

        if ($fullBytes > 0
            && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remBits === 0) return true;

        // Compare partial-byte: shift the mask, AND both, compare.
        $mask  = chr((0xFF << (8 - $remBits)) & 0xFF);
        $ipB   = $ipBin[$fullBytes]   ?? "\x00";
        $netB  = $netBin[$fullBytes]  ?? "\x00";
        return ($ipB & $mask) === ($netB & $mask);
    }

    /**
     * Parse a CIDR-or-bare-IP string into [networkBin, prefixBits].
     * Returns null on parse failure.
     */
    private static function cidrToBits(string $entry): ?array
    {
        $entry = trim($entry);
        if (str_contains($entry, '/')) {
            [$ip, $bits] = explode('/', $entry, 2);
            $bits = (int) $bits;
        } else {
            $ip   = $entry;
            $bits = -1; // sentinel: pick /32 or /128 based on family
        }

        $bin = @inet_pton(self::normalise($ip));
        if ($bin === false) return null;
        $maxBits = strlen($bin) === 4 ? 32 : 128;

        if ($bits === -1) $bits = $maxBits;
        if ($bits < 0 || $bits > $maxBits) return null;
        return [$bin, $bits];
    }

    private static function normalise(string $ip): string
    {
        // ::ffff:192.168.1.1 → 192.168.1.1 so an IPv4 client behind a
        // dual-stack proxy still matches an IPv4 CIDR.
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return substr($ip, 7);
        }
        return $ip;
    }
}
