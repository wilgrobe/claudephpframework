<?php
// core/Services/ErrorRecorder.php
namespace Core\Services;

/**
 * Errors recorded on the site's own server — grouped, counted, one redacted sample each.
 *
 * This is what lets the owner's Ops Assistant replace Sentry: every throwable that reaches
 * SentryService::captureException() (the production exception handler, a queue job's final failure, an explicit
 * capture) and every fatal error caught at shutdown is recorded here whether or not SENTRY_DSN is set. The
 * site's pulse then reports new and recurring errors to Ops.
 *
 *   error_events   one row per distinct error (fingerprint): count, first/last seen, and a sample —
 *                  class, message, file:line, a short trace WITHOUT argument values, the URL path (no query
 *                  values), the signed-in user's id, and where it ran (web / cli / queue).
 *
 * Redaction: emails, long tokens and key=value secrets are masked in messages; traces drop argument values;
 * URLs keep only the path and query KEYS. Never a request body, header or cookie.
 *
 * When the database itself is the error, the event goes to storage/logs/error_events.jsonl and is folded into
 * the table the next time the recorder can reach it (drainFallback, called by the pulse). Nothing here may ever
 * throw: recording an error must not cause another.
 */
final class ErrorRecorder
{
    public const TABLE = 'error_events';
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private static bool $registered = false;
    private static bool $busy = false;

    /** Arm the shutdown hook for fatals, and (CLI only) record uncaught exceptions. Idempotent. */
    public static function register(): void
    {
        if (self::$registered) return;
        self::$registered = true;
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if (!$e || !($e['type'] & self::FATAL)) return;
            self::recordRaw('fatal', 'FatalError', (string) $e['message'], (string) $e['file'], (int) $e['line'], '');
        });
        if (PHP_SAPI === 'cli') {
            // The web path sets its own handler in public/index.php, which reaches captureException() → record().
            $prev = set_exception_handler(null);
            set_exception_handler(static function (\Throwable $e) use ($prev): void {
                self::record($e, 'error');
                if (is_callable($prev)) { $prev($e); return; }
                fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n");
                exit(255);
            });
        }
    }

    /** Record a throwable. Never throws. */
    public static function record(\Throwable $e, string $level = 'error'): void
    {
        $level = $e instanceof \Error ? 'fatal' : $level;
        self::recordRaw($level, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), self::trace($e));
    }

    private static function recordRaw(string $level, string $class, string $message, string $file, int $line, string $trace): void
    {
        if (self::$busy) return;
        self::$busy = true;
        try {
            $ev = self::event($level, $class, $message, $file, $line, $trace, self::context(), gmdate('Y-m-d H:i:s'));
            if (!self::store($ev)) self::fallback($ev);
        } catch (\Throwable $_) {
            // never let recording fail loudly
        } finally {
            self::$busy = false;
        }
    }

    /**
     * The row for one occurrence. Pure.
     * @param array{url:?string,method:?string,user_id:?int,runtime:string} $ctx
     */
    public static function event(string $level, string $class, string $message, string $file, int $line, string $trace, array $ctx, string $at): array
    {
        $base = defined('BASE_PATH') ? rtrim(str_replace('\\', '/', (string) BASE_PATH), '/') . '/' : '';
        $rel = $base !== '' ? str_replace($base, '', str_replace('\\', '/', $file)) : $file;
        $msg = self::redact($message);
        return [
            'fingerprint' => self::fingerprint($class, $rel, $line, $msg),
            'level'       => in_array($level, ['fatal', 'error', 'warning'], true) ? $level : 'error',
            'class'       => mb_substr($class, 0, 190),
            'message'     => mb_substr($msg, 0, 1000),
            'file'        => mb_substr($rel, 0, 255),
            'line'        => $line,
            'trace'       => $trace !== '' ? mb_substr(str_replace($base, '', str_replace('\\', '/', $trace)), 0, 4000) : null,
            'url'         => $ctx['url'] !== null ? mb_substr($ctx['url'], 0, 500) : null,
            'method'      => $ctx['method'],
            'user_id'     => $ctx['user_id'],
            'runtime'     => $ctx['runtime'],
            'at'          => $at,
        ];
    }

    /**
     * One error, however often it happens: class, file, line and the message with its variable parts (numbers,
     * quoted values, ids) folded, so "Order 123 not found" and "Order 456 not found" are one row. Pure.
     */
    public static function fingerprint(string $class, string $file, int $line, string $message): string
    {
        $m = preg_replace(["/'[^']*'/", '/"[^"]*"/', '/\b[0-9a-f]{8,}\b/i', '/\d+/'], ["'?'", '"?"', 'H', 'N'], $message);
        return sha1($class . '|' . $file . '|' . $line . '|' . $m);
    }

    /** Mask what must never leave the server: emails, bearer/API tokens, key=value secrets. Pure. */
    public static function redact(string $s): string
    {
        $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $s);
        $s = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie)\b(\s*[=:]\s*)(\S+)/i', '$1$2[redacted]', $s);
        $s = preg_replace('/\b(sk|pk|rk|whsec|xox[bpa])_[A-Za-z0-9_]{8,}\b/', '[key]', $s);
        $s = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[token]', $s);
        return (string) $s;
    }

    /** The trace without argument values ("#0 file(12): Fn('secret')" → "#0 file(12): Fn()"). Pure. */
    public static function trace(\Throwable $e): string
    {
        $lines = [];
        foreach (explode("\n", $e->getTraceAsString()) as $l) {
            // "#0 file.php(12): Class->fn('secret')" → "#0 file.php(12): Class->fn()": only the call's arguments go.
            $lines[] = preg_replace('/^(#\d+ .*?: [^(]+)\(.*\)$/', '$1()', $l);
            if (count($lines) >= 25) break;
        }
        return implode("\n", $lines);
    }

    /** Where it ran: the path + query KEYS (never values), method, user id. */
    private static function context(): array
    {
        $argv = implode(' ', (array) ($_SERVER['argv'] ?? []));
        $runtime = PHP_SAPI === 'cli' ? (str_contains($argv, 'queue:work') ? 'queue' : 'cli') : 'web';
        $url = null; $method = null; $uid = null;
        if ($runtime === 'web') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
            parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
            $url = $path . ($q ? '?' . implode('&', array_map(fn ($k) => $k . '=…', array_keys($q))) : '');
            $method = substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 8) ?: null;
            try { $id = class_exists(\Core\Auth\Auth::class) ? \Core\Auth\Auth::getInstance()->id() : null; $uid = $id ? (int) $id : null; } catch (\Throwable $_) {}
        }
        return ['url' => $url, 'method' => $method, 'user_id' => $uid, 'runtime' => $runtime];
    }

    private static function store(array $ev): bool
    {
        try {
            if (!class_exists(\Core\Database\Database::class)) return false;
            $db = \Core\Database\Database::getInstance();
            $db->query('INSERT INTO ' . self::TABLE . ' (fingerprint, level, class, message, file, line, trace, url, method, user_id, runtime, count, first_seen, last_seen)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)
                        ON DUPLICATE KEY UPDATE count = count + 1, last_seen = VALUES(last_seen), level = VALUES(level), message = VALUES(message),
                            trace = VALUES(trace), url = VALUES(url), method = VALUES(method), user_id = VALUES(user_id), runtime = VALUES(runtime)',
                [$ev['fingerprint'], $ev['level'], $ev['class'], $ev['message'], $ev['file'], $ev['line'], $ev['trace'], $ev['url'], $ev['method'],
                 $ev['user_id'], $ev['runtime'], $ev['at'], $ev['at']]);
            return true;
        } catch (\Throwable $_) {
            return false;
        }
    }

    private static function fallbackPath(): ?string
    {
        if (!defined('BASE_PATH')) return null;
        $dir = (($_ENV['LOG_PATH'] ?? '') ?: BASE_PATH . '/storage/logs');
        return is_dir($dir) ? $dir . '/error_events.jsonl' : null;
    }

    private static function fallback(array $ev): void
    {
        $p = self::fallbackPath();
        if ($p === null) return;
        @file_put_contents($p, json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Fold events written while the database was unreachable into the table. Called by the pulse. @return int folded */
    public static function drainFallback(): int
    {
        $p = self::fallbackPath();
        if ($p === null || !is_file($p) || filesize($p) === 0) return 0;
        $tmp = $p . '.' . getmypid() . '.draining';
        if (!@rename($p, $tmp)) return 0;
        $n = 0;
        foreach (file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $ev = json_decode($line, true);
            if (is_array($ev) && isset($ev['fingerprint']) && self::store($ev)) $n++;
            elseif (is_array($ev)) self::fallback($ev);
        }
        @unlink($tmp);
        return $n;
    }
}
