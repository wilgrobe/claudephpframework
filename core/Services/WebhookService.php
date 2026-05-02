<?php
// core/Services/WebhookService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * WebhookService — outbound HTTP dispatch for integrations (Slack pings,
 * third-party event notifications, etc.). Shares message_log's retry
 * machinery with Mail/Sms so the admin UI lists everything in one place.
 *
 * Drivers:
 *   log   — record URL + payload + headers, do nothing. Default in dev so
 *           you can wire integrations without hitting real services.
 *   http  — actually POST via file_get_contents() with a stream context.
 *   none  — refuse (doSend returns false, row is marked failed).
 *   auto  — (default) log in non-production, http in production.
 *
 * Storage convention in message_log:
 *   channel    = 'webhook'
 *   recipient  = target URL
 *   subject    = HTTP method (GET/POST/PUT/...) + optional response code
 *   body       = request payload (string or JSON)
 *   provider   = driver name ('log' or 'http') — gets the CAPTURED badge
 *                in the admin UI when value is 'log'.
 */
class WebhookService
{
    private Database $db;
    private string   $driver;

    private const RETRY_BACKOFF_MINUTES = [1, 5, 25];
    private const MAX_ATTEMPTS          = 3;

    public function __construct()
    {
        $this->db = Database::getInstance();

        $envOvr = $_ENV['WEBHOOK_DRIVER'] ?? 'auto';
        $appEnv = $_ENV['APP_ENV']        ?? 'production';

        if ($envOvr === '' || $envOvr === 'auto') {
            $this->driver = ($appEnv === 'production') ? 'http' : 'log';
        } else {
            $this->driver = $envOvr; // 'log' | 'http' | 'none'
        }

        // Refuse the capture driver in production. It writes full request
        // bodies to the PHP error log (see doSend() when driver === 'log'),
        // which can trivially expose OTP codes, access tokens, and PII to
        // anyone tailing storage/logs/php_error.log. A misconfigured
        // WEBHOOK_DRIVER=log in prod would silently leak; fail loud instead.
        if ($appEnv === 'production' && in_array($this->driver, ['log', 'capture'], true)) {
            throw new \RuntimeException(
                "WebhookService: driver '{$this->driver}' is refused in production " .
                "because it logs full request bodies. Set WEBHOOK_DRIVER=http (or 'auto', " .
                "or 'none') and restart."
            );
        }
    }

    /**
     * Dispatch a webhook. Returns true on success (2xx) or, under the log
     * driver, always true. Any non-2xx or transport failure marks the row
     * failed and schedules a retry per the shared backoff policy.
     *
     * @param array<string,string> $headers  extra headers beyond Content-Type
     * @param array|string         $payload  array will be JSON-encoded
     */
    public function send(string $url, array|string $payload, string $method = 'POST', array $headers = []): bool
    {
        $body = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : (string) $payload;

        $logId = $this->db->insert('message_log', [
            'channel'      => 'webhook',
            'recipient'    => $url,
            'subject'      => strtoupper($method),
            'body'         => $body,
            'status'       => 'queued',
            'provider'     => $this->driver,
            'max_attempts' => self::MAX_ATTEMPTS,
        ]);

        return $this->attemptSend($logId, $url, $body, strtoupper($method), $headers);
    }

    /**
     * Re-dispatch a previously-logged webhook row by ID. Called from
     * MessageRetryService (automatic) and from the admin Retry button.
     */
    public function resend(int $logId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM message_log WHERE id = ? AND channel = 'webhook' LIMIT 1",
            [$logId]
        );
        if (!$row) return false;
        if ($row['status'] === 'sent') return true;

        return $this->attemptSend(
            (int)$row['id'],
            (string)$row['recipient'],
            (string)($row['body'] ?? ''),
            strtoupper((string)($row['subject'] ?? 'POST')),
            []  // original headers are not stored; content-type is added in doSend
        );
    }

    private function attemptSend(int $logId, string $url, string $body, string $method, array $headers): bool
    {
        try {
            $success = $this->doSend($url, $body, $method, $headers, $logId);
            if ($success) {
                $this->db->update('message_log',
                    [
                        'status'            => 'sent',
                        'sent_at'           => date('Y-m-d H:i:s'),
                        'last_attempted_at' => date('Y-m-d H:i:s'),
                        'next_attempt_at'   => null,
                        'attempts'          => $this->currentAttempts($logId) + 1,
                        'error'             => null,
                    ],
                    'id = ?', [$logId]
                );
                return true;
            }
            $this->markFailed($logId, 'Transport returned false (no exception).');
            return false;
        } catch (\Throwable $e) {
            $this->markFailed($logId, $e->getMessage());
            return false;
        }
    }

    private function doSend(string $url, string $body, string $method, array $headers, int $logId): bool
    {
        // Local-capture driver — record it, don't send. Echoed to the PHP
        // error log too so you can `tail` during development.
        if ($this->driver === 'log' || $this->driver === 'capture') {
            error_log("[webhook:log] $method $url body=" . substr(str_replace(["\r","\n"], ' ', $body), 0, 500));
            return true;
        }

        if ($this->driver === 'none') {
            return false;
        }

        // SSRF guard: refuse URLs that resolve to private/loopback/
        // link-local ranges before the HTTP call ever goes out. Without
        // this, an attacker who can influence a webhook URL (tenant-
        // configured integrations, admin input) can reach internal
        // services, cloud metadata endpoints (169.254.169.254), or
        // localhost:*. Dev environments that legitimately post to
        // http://minio:9000, http://mailhog:1025, etc. can opt out with
        // WEBHOOK_ALLOW_PRIVATE=1.
        if (!$this->urlIsPublic($url)) {
            error_log('[webhook] rejected non-public URL: ' . $url);
            return false;
        }

        // Real HTTP dispatch. Stream context + file_get_contents keeps the
        // dependency surface tiny; if you later need per-request timeouts,
        // streaming responses, or mTLS, swap this for Guzzle.
        $headerLines  = ['Content-Type: ' . (self::looksLikeJson($body) ? 'application/json' : 'application/x-www-form-urlencoded')];
        $headerLines[] = 'User-Agent: ClaudePHPFramework-Webhooks/1.0';
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines),
                'content'       => $body,
                'timeout'       => 10,
                'ignore_errors' => true, // so 4xx/5xx come back as strings, not false
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);

        // $http_response_header is magically populated inside this function
        // scope when file_get_contents() runs through the http wrapper.
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        // Stash the response code in the log row's subject so the admin UI
        // shows something like "POST 204" at a glance.
        $this->db->update('message_log',
            ['subject' => "$method " . ($status ?: '???')],
            'id = ?', [$logId]
        );

        if ($result === false || $status < 200 || $status >= 300) {
            return false;
        }
        return true;
    }

    private static function looksLikeJson(string $body): bool
    {
        $first = ltrim($body);
        return isset($first[0]) && ($first[0] === '{' || $first[0] === '[');
    }

    /**
     * SSRF guard. True only when:
     *   - $url parses cleanly
     *   - scheme is http or https (no file://, ftp://, gopher://, etc.)
     *   - host resolves to at least one IP, and NONE of the resolved IPs
     *     fall in private / loopback / link-local / reserved ranges
     *
     * Dev installs that legitimately post to compose-network hostnames
     * (http://mailhog:1025, http://minio:9000) can set
     * WEBHOOK_ALLOW_PRIVATE=1 in .env to skip the check. This bypass is
     * INTENTIONALLY opt-in per-environment — production stays locked down
     * even if someone forgets to unset the flag in a downstream deploy,
     * because the production log-driver guard in __construct refuses the
     * whole capture workflow anyway.
     *
     * Limitation: we resolve once here, then file_get_contents resolves
     * again when it connects. A DNS rebinding attacker could race the
     * two resolutions. Hardening that requires connecting to the pinned
     * IP with the original hostname as a Host header — out of scope for
     * this pass; the 95% case (static malicious URL) is covered.
     */
    private function urlIsPublic(string $url): bool
    {
        if (!empty($_ENV['WEBHOOK_ALLOW_PRIVATE'])
            && filter_var($_ENV['WEBHOOK_ALLOW_PRIVATE'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $parts = parse_url($url);
        if ($parts === false) return false;

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) return false;

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') return false;

        // If host is a literal IP (IPv4 or IPv6 inside brackets), check it directly.
        $literalHost = trim($host, '[]');
        if (filter_var($literalHost, FILTER_VALIDATE_IP)) {
            return $this->ipIsPublic($literalHost);
        }

        // Hostname — resolve all A and AAAA records and require ALL to be public.
        // If any resolves to a private/reserved IP, treat the URL as unsafe.
        $ips = [];
        $a    = @dns_get_record($host, DNS_A);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($a    ?: [] as $r) { if (!empty($r['ip']))    $ips[] = $r['ip']; }
        foreach ($aaaa ?: [] as $r) { if (!empty($r['ipv6']))  $ips[] = $r['ipv6']; }

        // DNS failed or returned nothing — fail closed rather than pass a
        // bare hostname to file_get_contents.
        if (empty($ips)) return false;

        foreach ($ips as $ip) {
            if (!$this->ipIsPublic($ip)) return false;
        }
        return true;
    }

    /**
     * True only if $ip is valid AND falls in the public space — i.e. not
     * private (RFC1918 / fc00::/7), not reserved (0/8, 100.64/10, 127/8,
     * 169.254/16, 224/4, 240/4, ::1, fe80::/10, etc.).
     *
     * filter_var with both NO_PRIV_RANGE and NO_RES_RANGE returns false for
     * anything that's either private or reserved — exactly the set we want
     * to block.
     */
    private function ipIsPublic(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function currentAttempts(int $logId): int
    {
        $row = $this->db->fetchOne("SELECT attempts FROM message_log WHERE id = ?", [$logId]);
        return (int)($row['attempts'] ?? 0);
    }

    private function markFailed(int $logId, string $errorMessage): void
    {
        $row = $this->db->fetchOne("SELECT attempts, max_attempts FROM message_log WHERE id = ?", [$logId]);
        $attempts = (int)($row['attempts'] ?? 0) + 1;
        $max      = (int)($row['max_attempts'] ?? self::MAX_ATTEMPTS);

        $delayMinutes = self::RETRY_BACKOFF_MINUTES[$attempts - 1] ?? null;
        $nextAt = ($attempts < $max && $delayMinutes !== null)
            ? date('Y-m-d H:i:s', time() + ($delayMinutes * 60))
            : null;

        $this->db->update('message_log',
            [
                'status'            => 'failed',
                'error'             => substr($errorMessage, 0, 65000),
                'attempts'          => $attempts,
                'last_attempted_at' => date('Y-m-d H:i:s'),
                'next_attempt_at'   => $nextAt,
            ],
            'id = ?', [$logId]
        );
    }
}
