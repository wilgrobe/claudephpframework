<?php
// core/Services/SentryService.php
namespace Core\Services;

/**
 * Sentry error-reporting facade.
 *
 * Two code paths behind one stable API:
 *
 *   1. Official SDK (sentry/sentry) — used whenever the package is installed
 *      (the normal production path since it's in composer.json). Gives the
 *      full envelope protocol, fatal-error capture via a shutdown handler,
 *      source-context stack frames, breadcrumbs, and optional performance
 *      tracing (SENTRY_TRACES_SAMPLE_RATE).
 *
 *   2. Hand-rolled HTTP fallback — used when the SDK isn't installed (a
 *      framework-only checkout that skipped `composer install`, or a stripped
 *      deploy). Posts directly to the legacy /store/ endpoint. Error/exception
 *      capture only; no tracing or breadcrumbs.
 *
 * Either way: enabled when SENTRY_DSN is set, every method a no-op otherwise.
 * init() is hooked from core/bootstrap.php so both the web (public/index.php)
 * and CLI (artisan, queue worker, cron) paths initialize Sentry. The web
 * exception handler in public/index.php also calls captureException() directly,
 * so any uncaught throwable reaches Sentry when configured.
 *
 * Privacy posture (preserved across both paths): we attach the authenticated
 * user's id + email + ip_address and nothing else — send_default_pii stays
 * false so the SDK doesn't auto-collect cookies / headers / request bodies.
 * See auto-memory reference_sentry_user_context for the rationale.
 */
class SentryService
{
    /** Cache parsed DSN so we don't re-parse on every capture (HTTP fallback only). */
    private static ?array $parsedDsn = null;
    private static bool   $initAttempted = false;

    /** True once \Sentry\init() has run successfully (DSN set + SDK installed). */
    private static bool $usingSdk = false;

    /**
     * Initialize Sentry. Memoized + safe to call repeatedly — bootstrap.php
     * calls it for every request/command; public/index.php calls it again
     * before the container is built (so its exception handler is armed even
     * if bootstrap later throws). Both calls collapse to one real init.
     */
    public static function init(): void
    {
        if (self::$initAttempted) return;
        self::$initAttempted = true;

        $dsn = trim((string) ($_ENV['SENTRY_DSN'] ?? ''));
        if ($dsn === '') return;   // disabled

        // Prefer the official SDK when present.
        if (function_exists('\Sentry\init')) {
            try {
                self::initSdk($dsn);
                self::$usingSdk = true;
                return;
            } catch (\Throwable $e) {
                // SDK init failed (bad option, transport build error). Fall
                // through to the hand-rolled path rather than losing reporting.
                error_log('[sentry] SDK init failed, falling back to HTTP: ' . $e->getMessage());
            }
        }

        // Fallback: hand-rolled HTTP client.
        self::parseDsn();
    }

    public static function isEnabled(): bool
    {
        self::init();
        return self::$usingSdk || self::$parsedDsn !== null;
    }

    /**
     * Send a throwable to Sentry. Silent when disabled or on any transport
     * error — we don't want exception-handling itself to throw.
     */
    public static function captureException(\Throwable $e): void
    {
        self::init();

        if (self::$usingSdk) {
            try { \Sentry\captureException($e); } catch (\Throwable $_) {}
            return;
        }

        if (self::$parsedDsn === null) return;

        try {
            self::send(self::buildExceptionPayload($e));
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
        self::init();

        if (self::$usingSdk) {
            try {
                $severity = self::toSeverity($level);
                if ($context !== []) {
                    \Sentry\withScope(static function ($scope) use ($message, $severity, $context): void {
                        foreach ($context as $k => $v) {
                            $scope->setExtra((string) $k, $v);
                        }
                        \Sentry\captureMessage($message, $severity);
                    });
                } else {
                    \Sentry\captureMessage($message, $severity);
                }
            } catch (\Throwable $_) {}
            return;
        }

        if (self::$parsedDsn === null) return;

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

    /**
     * Flush any buffered events to Sentry. The SDK's transport can defer the
     * actual HTTP send; long-running CLI processes (queue worker, scheduler)
     * should call this after a capture or before exit so events aren't held
     * until the process dies. No-op on the HTTP fallback (it sends inline) and
     * when Sentry is disabled.
     */
    public static function flush(): void
    {
        if (!self::$usingSdk) return;
        try { \Sentry\flush(); } catch (\Throwable $_) {}
    }

    // ── SDK path ──────────────────────────────────────────────────────────────

    private static function initSdk(string $dsn): void
    {
        $tracesRaw = $_ENV['SENTRY_TRACES_SAMPLE_RATE'] ?? '0.0';
        $traces    = is_numeric($tracesRaw) ? max(0.0, min(1.0, (float) $tracesRaw)) : 0.0;
        $release   = trim((string) ($_ENV['APP_VERSION'] ?? ''));

        $options = [
            'dsn'                   => $dsn,
            'environment'           => (string) ($_ENV['SENTRY_ENVIRONMENT'] ?? ($_ENV['APP_ENV'] ?? 'production')),
            'release'               => $release !== '' ? $release : null,
            'traces_sample_rate'    => $traces,
            // We attach user context (id/email/ip) deliberately in before_send;
            // keep auto-PII off so cookies / headers / request bodies aren't
            // collected. See class docblock + reference_sentry_user_context.
            'send_default_pii'      => false,
            'attach_stacktrace'     => true,
            'max_request_body_size' => 'medium',
            'before_send'           => static function (\Sentry\Event $event): ?\Sentry\Event {
                try {
                    $user = self::userContext();
                    if ($user !== null) {
                        $event->setUser(\Sentry\UserDataBag::createFromArray($user));
                    }
                } catch (\Throwable $_) {
                    // Never let user-context enrichment drop the event.
                }
                return $event;
            },
        ];

        if (defined('BASE_PATH')) {
            // Don't blame framework/vendor frames as the app's own — keeps the
            // "in app" stack frames pointing at our code.
            $options['in_app_exclude'] = [BASE_PATH . '/vendor'];
        }

        \Sentry\init($options);
    }

    private static function toSeverity(string $level): \Sentry\Severity
    {
        return match ($level) {
            'fatal'   => \Sentry\Severity::fatal(),
            'error'   => \Sentry\Severity::error(),
            'warning' => \Sentry\Severity::warning(),
            'debug'   => \Sentry\Severity::debug(),
            default   => \Sentry\Severity::info(),
        };
    }

    // ── HTTP fallback internals ────────────────────────────────────────────────

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
