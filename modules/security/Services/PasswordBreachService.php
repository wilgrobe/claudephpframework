<?php
// modules/security/Services/PasswordBreachService.php
namespace Modules\Security\Services;

use Core\Database\Database;

/**
 * HIBP "Have I Been Pwned" Pwned Passwords k-anonymity client.
 *
 * Protocol (https://haveibeenpwned.com/API/v3#PwnedPasswords):
 *   1. SHA-1 the password.
 *   2. Send the first 5 hex chars (uppercase) as a GET to
 *      https://api.pwnedpasswords.com/range/{prefix}
 *   3. Response body is newline-delimited SUFFIX:COUNT — a few hundred
 *      35-char hash suffixes that share that prefix, with the count
 *      of breaches each appears in.
 *   4. We look for our suffix in the response. Match → password is
 *      known to be breached. No match → safe.
 *
 * The full SHA-1 NEVER leaves the server. The 5-char prefix tells HIBP
 * approximately 1-in-65000 of the keyspace; they can't tell which
 * password we're checking.
 *
 * Caching: responses are deterministic per prefix. We cache for 24h
 * to keep popular-password queries off the network. The cache stores
 * only the public HIBP dataset, not anything user-specific.
 *
 * Failure mode: HIBP unreachable / timeout / DNS fail → return false
 * (i.e. "not breached / unknown"). Fail-open is the right call here:
 * blocking signups because HIBP is down would be a self-inflicted
 * outage. The setting `password_breach_check_block` controls whether
 * a confirmed match blocks the action; an unknown answer never
 * blocks.
 */
class PasswordBreachService
{
    public const HIBP_API_BASE  = 'https://api.pwnedpasswords.com/range/';
    public const CACHE_TTL_SECS = 86400;          // 24h
    public const HTTP_TIMEOUT   = 3;              // seconds — fail fast

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Check whether a plaintext password appears in any known breach.
     *
     * @return array{breached: bool, count: int, source: string}
     *     breached  true if the password is known compromised
     *     count     number of breach corpora the password appears in
     *               (0 for unknown / not breached)
     *     source    'cache' | 'api' | 'unknown' — telemetry only
     */
    public function check(string $password): array
    {
        if ($password === '') {
            return ['breached' => false, 'count' => 0, 'source' => 'unknown'];
        }

        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        // Try cache first.
        $payload = $this->cacheGet($prefix);
        $source  = 'cache';

        if ($payload === null) {
            $payload = $this->fetchFromHibp($prefix);
            if ($payload === null) {
                // Network failure — fail open.
                return ['breached' => false, 'count' => 0, 'source' => 'unknown'];
            }
            $this->cachePut($prefix, $payload);
            $source = 'api';
        }

        $count = $this->scanForSuffix($payload, $suffix);
        return [
            'breached' => $count > 0,
            'count'    => $count,
            'source'   => $source,
        ];
    }

    /** Whether the breach-check feature is on per site setting. */
    public function isEnabled(): bool
    {
        return (bool) (setting('password_breach_check_enabled', true) ?? true);
    }

    /** Whether a confirmed breach should BLOCK or just warn. */
    public function shouldBlockOnBreach(): bool
    {
        return (bool) (setting('password_breach_check_block', true) ?? true);
    }

    /**
     * One-shot helper for callers in AuthController etc. Returns:
     *   - null on no-block (either disabled, not breached, or warn-only mode)
     *   - human-readable error string on block
     *
     * The caller adds the string to its validation errors. The
     * service deliberately doesn't choose error key / field — that's
     * the caller's job since they own the form layout.
     */
    public function validateOrError(string $password): ?string
    {
        if (!$this->isEnabled()) return null;

        $result = $this->check($password);
        if (!$result['breached']) return null;

        if (!$this->shouldBlockOnBreach()) return null;

        // Conservative phrasing — never echoes the password or the
        // count back to the user (which could leak info if they're
        // brute-forcing a breach corpus). Just enough to explain.
        return 'This password has been seen in a known data breach. '
             . 'Please choose a different one — even if you\'ve never used it on another site.';
    }

    // ── HTTP + cache internals ───────────────────────────────────────

    /**
     * Fetch the HIBP /range/{prefix} response. Returns the raw body
     * or null on any failure (timeout, DNS, non-2xx, etc.).
     */
    private function fetchFromHibp(string $prefix): ?string
    {
        $url = self::HIBP_API_BASE . $prefix;

        // Prefer cURL when available — better timeout control.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => 'cphpfw-security-module/1.0',
                CURLOPT_HTTPHEADER     => [
                    // Add-Padding tells HIBP to randomly pad the
                    // response so an on-path attacker can't infer the
                    // suffix from response length alone. Free privacy
                    // bump.
                    'Add-Padding: true',
                ],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $code < 200 || $code >= 300) return null;
            return $body;
        }

        // Fallback: file_get_contents with stream context. Less
        // controllable timeout-wise but works without cURL.
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT,
                'header'        => "User-Agent: cphpfw-security-module/1.0\r\n"
                                 . "Add-Padding: true\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) && $body !== '' ? $body : null;
    }

    private function cacheGet(string $prefix): ?string
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT payload FROM password_breach_cache
                 WHERE prefix = ? AND expires_at > NOW()",
                [$prefix]
            );
            return $row ? (string) $row['payload'] : null;
        } catch (\Throwable) {
            // Migration hasn't run yet — proceed without cache.
            return null;
        }
    }

    private function cachePut(string $prefix, string $payload): void
    {
        try {
            $this->db->query("
                REPLACE INTO password_breach_cache (prefix, payload, fetched_at, expires_at)
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
            ", [$prefix, $payload, self::CACHE_TTL_SECS]);
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal — we just lose the
            // efficiency gain on subsequent checks.
            error_log('PasswordBreachService cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Walk the newline-delimited SUFFIX:COUNT body looking for our
     * suffix. Returns the count if found, 0 otherwise. HIBP responses
     * use uppercase hex; we already uppercased our suffix.
     */
    private function scanForSuffix(string $payload, string $suffix): int
    {
        // Lines look like: 0018A45C4D1DEF81644B54AB7F969B88D65:5
        // The needle includes the trailing colon to avoid a false
        // match on a longer line that happens to share the prefix.
        $needle = $suffix . ':';
        $pos    = strpos($payload, $needle);
        if ($pos === false) return 0;

        // Read forward to the next \n (or end of body) for the count.
        $end = strpos($payload, "\n", $pos);
        $line = $end === false ? substr($payload, $pos) : substr($payload, $pos, $end - $pos);
        $parts = explode(':', $line, 2);
        return isset($parts[1]) ? (int) trim($parts[1]) : 1;
    }

    /**
     * Sweep called by the retention sweeper (declared via
     * retentionRules() in module.php) so the table doesn't grow forever.
     */
    public function purgeExpired(): int
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT prefix FROM password_breach_cache WHERE expires_at <= NOW()"
            );
            $count = count($rows);
            if ($count > 0) {
                $this->db->query("DELETE FROM password_breach_cache WHERE expires_at <= NOW()");
            }
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }
}
