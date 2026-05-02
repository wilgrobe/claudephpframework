<?php
// core/Services/SentryService.php
namespace Core\Services;

/**
 * Lightweight HTTP-only Sentry client.
 *
 * No SDK dependency — posts directly to Sentry's store endpoint using the
 * configured DSN. Enough for error/exception capture; does NOT implement
 * performance tracing, breadcrumbs, or the fuller envelope protocol.
 *
 * Enabled when SENTRY_DSN is set. Otherwise every method is a no-op.
 *
 * The bootstrap (public/index.php) calls captureException() from its
 * exception handler, so any uncaught throwable automatically reaches
 * Sentry when configured — application code rarely needs to call this
 * directly.
 */
class SentryService
{
    /** Cache parsed DSN so we don't re-parse on every capture. */
    private static ?array $parsedDsn = null;
    private static bool   $initAttempted = false;

    /**
     * Hook handler to be called early in bootstrap. Memoizes the parsed DSN;
     * safe to call repeatedly.
     */
    public static function init(): void
    {
        if (self::$initAttempted) return;
        self::$initAttempted = true;
        self::parseDsn();
    }

    public static function isEnabled(): bool
    {
        self::parseDsn();
        return self::$parsedDsn !== null;
    }

    /**
     * Send a throwable to Sentry. Silent when disabled or on any transport
     * error — we don't want exception-handling itself to throw.
     */
    public static function captureException(\Throwable $e): void
    {
        if (!self::isEnabled()) return;

        try {
            $payload = self::buildExceptionPayload($e);
            self::send($payload);
        } catch (\Throwable $_) {
            // Swallow — Sentry delivery failure must never mask the original error.
        }
    }

    /**
     * Send a log-line-style message to Sentry.
     *
     * @param string $level  one of: fatal, error, warning, info, debug
     */
    public static function captureMessage(string $message, string $level = 'info', array $context = []): void
    {
        if (!self::isEnabled()) return;

        try {
            $payload = [
                'message'     => $message,
                'level'       => in_array($level, ['fatal','error','warning','info','debug'], true) ? $level : 'info',
                'extra'       => $context,
            ];
            self::send(self::baseEvent() + $payload);
        } catch (\Throwable $_) {
            // see captureException()
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function parseDsn(): void
    {
        if (self::$parsedDsn !== null) return;
        $dsn = trim((string) ($_ENV['SENTRY_DSN'] ?? ''));
        if ($dsn === '') return;

        // DSN format: https://<public_key>@<host>/<project_id>
        // Accept both with and without a path prefix in case Sentry ever adds one.
        $parts = parse_url($dsn);
        if (!$parts || empty($parts['host']) || empty($parts['user']) || empty($parts['path'])) {
            error_log('[sentry] invalid SENTRY_DSN — ignored.');
            return;
        }
        $projectId = ltrim($parts['path'], '/');
        $scheme    = $parts['scheme'] ?? 'https';
        $port      = !empty($parts['port']) ? ':' . $parts['port'] : '';

        self::$parsedDsn = [
            'public_key' => $parts['user'],
            'host'       => $parts['host'] . $port,
            'scheme'     => $scheme,
            'project_id' => $projectId,
            'endpoint'   => "$scheme://{$parts['host']}$port/api/$projectId/store/",
        ];
    }

    private static function baseEvent(): array
    {
        $event = [
            'event_id'    => bin2hex(random_bytes(16)),
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'platform'    => 'php',
            'server_name' => gethostname() ?: 'unknown',
            'environment' => (string) ($_ENV['SENTRY_ENVIRONMENT'] ?? ($_ENV['APP_ENV'] ?? 'production')),
            'release'     => (string) ($_ENV['APP_VERSION'] ?? ''),
            'request'     => self::requestContext(),
            'tags'        => [
                'php_version' => PHP_VERSION,
            ],
        ];

        // Attach the authenticated user if available. Wrapped so a broken
        // Auth singleton can never prevent Sentry from reporting the
        // original exception — the whole point of this service is resilience
        // under abnormal conditions.
        $user = self::userContext();
        if ($user !== null) {
            $event['user'] = $user;
        }

        return $event;
    }

    /**
     * Pull auth + request metadata for Sentry's user object.
     * Returns null when no authenticated user is present (CLI, guests).
     *
     * Includes id + email + ip_address by config choice — see
     * auto-memory reference_sentry_user_context for the privacy decision.
     */
    private static function userContext(): ?array
    {
        try {
            if (!class_exists(\Core\Auth\Auth::class)) return null;
            $u = \Core\Auth\Auth::getInstance()->user();
        } catch (\Throwable $_) {
            return null;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!is_array($u)) {
            // No session user, but an IP might still be useful for rate-limit
            // / abuse analysis. Sentry accepts user-less events with just an
            // ip_address; emit that only when we actually have one.
            return $ip !== '' ? ['ip_address' => $ip] : null;
        }

        $ctx = [];
        if (isset($u['id']))    $ctx['id']    = (int) $u['id'];
        if (!empty($u['email'])) $ctx['email'] = (string) $u['email'];
        if ($ip !== '')          $ctx['ip_address'] = $ip;

        return $ctx ?: null;
    }

    private static function buildExceptionPayload(\Throwable $e): array
    {
        $frames = [];
        foreach (array_reverse($e->getTrace()) as $t) {
            $frames[] = [
                'filename' => $t['file']     ?? '[internal]',
                'lineno'   => $t['line']     ?? 0,
                'function' => ($t['class']   ?? '') . ($t['type'] ?? '') . ($t['function'] ?? ''),
            ];
        }
        // Last frame = where it was thrown
        $frames[] = [
            'filename' => $e->getFile(),
            'lineno'   => $e->getLine(),
            'function' => '[throw]',
        ];

        return self::baseEvent() + [
            'level'     => 'error',
            'exception' => [
                'values' => [[
                    'type'        => get_class($e),
                    'value'       => $e->getMessage(),
                    'stacktrace'  => ['frames' => $frames],
                ]],
            ],
        ];
    }

    private static function requestContext(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $url    = isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])
            ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
            : '';
        return [
            'url'     => $url,
            'method'  => $method,
            'headers' => [
                'User-Agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ],
        ];
    }

    /**
     * POST the payload to Sentry using file_get_contents so we don't pull in
     * Guzzle just for one endpoint. 2s timeout keeps a Sentry outage from
     * slowing the site's 500 page.
     */
    private static function send(array $payload): void
    {
        $dsn = self::$parsedDsn;
        if ($dsn === null) return;

        $auth = sprintf(
            'Sentry sentry_version=7, sentry_key=%s, sentry_client=claudephpframework-sentry/1.0',
            $dsn['public_key']
        );

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) return;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Sentry-Auth: $auth\r\n",
            'content'       => $body,
            'timeout'       => 2.0,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($dsn['endpoint'], false, $ctx);
    }
}
