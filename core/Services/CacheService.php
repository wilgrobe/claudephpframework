<?php
// core/Services/CacheService.php
namespace Core\Services;

/**
 * Key-value cache with three backends:
 *
 *   file      (default) — filesystem cache under STORAGE_PATH/cache/.
 *                         Zero dependencies, works on any install.
 *   redis               — ext-redis + a reachable Redis / Valkey server.
 *                         Much faster under load and lets multiple PHP
 *                         processes share a single cache.
 *   memcached           — ext-memcached + one or more Memcached servers.
 *                         Simpler than Redis, widely deployed, popular
 *                         in front of MySQL.
 *
 * Selected via CACHE_DRIVER in .env. When the chosen driver can't be
 * initialized (extension missing, server unreachable), we fall back to
 * the file driver with a logged warning — the app stays up.
 *
 * All values are serialized before writing, so any PHP-native type (int,
 * string, array, object) round-trips faithfully.
 *
 * Typical usage via the global cache() helper:
 *   cache('key')                    // read
 *   cache('key', 'value', 300)      // write with 300s TTL
 *   cache()->remember('k', 3600, fn() => expensive_lookup())
 */
class CacheService
{
    /**
     * Class allowlist for unserialize(). The framework's cached payloads
     * are scalars, arrays, and stdClass (used by remember()'s sentinel)
     * - never user-defined classes. Setting allowed_classes to a narrow
     * list rather than `true` defends against PHP object-injection gadget
     * chains if the cache layer is ever compromised: a poisoned Redis or
     * a writable file-cache directory can no longer instantiate arbitrary
     * classes via __wakeup/__destruct/__toString chains.
     *
     * To cache a custom class, add its FQCN here AFTER auditing it for
     * unserialize-safety (no destructive __destruct, no eval-like __wakeup).
     */
    private const SAFE_CACHED_CLASSES = [\stdClass::class];

    private static ?CacheService $instance = null;

    private string      $driver;   // 'file', 'redis', or 'memcached'
    private ?\Redis     $redis     = null;
    private ?\Memcached $memcached = null;
    private string      $fileDir;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function __construct()
    {
        $this->fileDir = rtrim((string) ($_ENV['STORAGE_PATH'] ?? (BASE_PATH . '/storage')), '/') . '/cache';
        $this->driver  = $this->resolveDriver();
    }

    /**
     * Decide which backend to use. If CACHE_DRIVER=redis and connection
     * succeeds, we use Redis. Any failure (ext missing, refused connection,
     * auth failure) downgrades silently to file cache so the site keeps
     * working — cache misses are never fatal.
     */
    private function resolveDriver(): string
    {
        $requested = strtolower(trim((string) ($_ENV['CACHE_DRIVER'] ?? 'file')));

        if ($requested === 'redis') {
            if (!class_exists(\Redis::class)) {
                error_log('[cache] CACHE_DRIVER=redis but ext-redis is not installed. Falling back to file cache.');
                return 'file';
            }
            try {
                $this->redis = $this->connectRedis();
                return 'redis';
            } catch (\Throwable $e) {
                error_log('[cache] Redis connection failed (' . $e->getMessage() . '). Falling back to file cache.');
                $this->redis = null;
                return 'file';
            }
        }

        if ($requested === 'memcached') {
            if (!class_exists(\Memcached::class)) {
                error_log('[cache] CACHE_DRIVER=memcached but ext-memcached is not installed. Falling back to file cache.');
                return 'file';
            }
            try {
                $this->memcached = $this->connectMemcached();
                return 'memcached';
            } catch (\Throwable $e) {
                error_log('[cache] Memcached connection failed (' . $e->getMessage() . '). Falling back to file cache.');
                $this->memcached = null;
                return 'file';
            }
        }

        return 'file';
    }

    private function connectRedis(): \Redis
    {
        $client = new \Redis();

        // Prefer REDIS_URL when set (redis://user:pass@host:port/db).
        $url = trim((string) ($_ENV['REDIS_URL'] ?? ''));
        if ($url !== '') {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host'])) {
                throw new \RuntimeException("Invalid REDIS_URL: $url");
            }
            $host = $parts['host'];
            $port = (int) ($parts['port'] ?? 6379);
            $pass = $parts['pass'] ?? null;
            $db   = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;
        } else {
            $host = (string) ($_ENV['REDIS_HOST']     ?? '127.0.0.1');
            $port = (int)    ($_ENV['REDIS_PORT']     ?? 6379);
            $pass = (string) ($_ENV['REDIS_PASSWORD'] ?? '') ?: null;
            $db   = (int)    ($_ENV['REDIS_DB']       ?? 0);
        }

        if (!$client->connect($host, $port, 1.0)) {
            throw new \RuntimeException("Could not connect to Redis at $host:$port");
        }
        if ($pass !== null && !$client->auth($pass)) {
            throw new \RuntimeException('Redis auth failed');
        }
        if ($db > 0) {
            $client->select($db);
        }
        return $client;
    }

    /**
     * Connect to one or more Memcached servers. MEMCACHED_SERVERS is a
     * comma-separated list of host:port[:weight] entries so admins can
     * configure a pool without extra boilerplate.
     */
    private function connectMemcached(): \Memcached
    {
        $client = new \Memcached();
        $client->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 1000); // ms

        $servers = [];
        foreach (explode(',', (string) ($_ENV['MEMCACHED_SERVERS'] ?? '127.0.0.1:11211')) as $spec) {
            $spec = trim($spec);
            if ($spec === '') continue;
            $parts = explode(':', $spec);
            $host  = $parts[0] ?? '127.0.0.1';
            $port  = (int) ($parts[1] ?? 11211);
            $w     = (int) ($parts[2] ?? 0);
            $servers[] = [$host, $port, $w ?: 0];
        }
        if (empty($servers)) {
            throw new \RuntimeException('MEMCACHED_SERVERS is empty.');
        }
        $client->addServers($servers);

        $user = (string) ($_ENV['MEMCACHED_USERNAME'] ?? '');
        $pass = (string) ($_ENV['MEMCACHED_PASSWORD'] ?? '');
        if ($user !== '') {
            $client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $client->setSaslAuthData($user, $pass);
        }

        // A trivial no-op probe catches DNS/firewall errors at service
        // construction time rather than at first get/set.
        $client->getVersion();
        if ($client->getResultCode() !== \Memcached::RES_SUCCESS) {
            throw new \RuntimeException('Memcached getVersion() failed: code ' . $client->getResultCode());
        }
        return $client;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->driver === 'redis') {
            $raw = $this->redis->get($this->prefix($key));
            if ($raw === false) return $default;
            $decoded = @unserialize($raw, ['allowed_classes' => self::SAFE_CACHED_CLASSES]);
            return $decoded === false && $raw !== serialize(false) ? $default : $decoded;
        }
        if ($this->driver === 'memcached') {
            $raw = $this->memcached->get($this->prefix($key));
            if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) return $default;
            if ($raw === false && $this->memcached->getResultCode() !== \Memcached::RES_SUCCESS) return $default;
            $decoded = @unserialize((string) $raw, ['allowed_classes' => self::SAFE_CACHED_CLASSES]);
            return $decoded === false && $raw !== serialize(false) ? $default : $decoded;
        }

        return $this->fileGet($key, $default);
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): void
    {
        if ($this->driver === 'redis') {
            $data = serialize($value);
            if ($ttlSeconds > 0) {
                $this->redis->setex($this->prefix($key), $ttlSeconds, $data);
            } else {
                $this->redis->set($this->prefix($key), $data);
            }
            return;
        }
        if ($this->driver === 'memcached') {
            // Memcached's set() accepts a Unix timestamp when ttl > 30 days;
            // values under 30 days are treated as a relative offset. We
            // always pass a relative offset (or 0 for "never expire") so
            // we stay in the simple case.
            $this->memcached->set($this->prefix($key), serialize($value), $ttlSeconds > 0 ? $ttlSeconds : 0);
            return;
        }
        $this->fileSet($key, $value, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        if ($this->driver === 'redis') {
            $this->redis->del($this->prefix($key));
            return;
        }
        if ($this->driver === 'memcached') {
            $this->memcached->delete($this->prefix($key));
            return;
        }
        @unlink($this->filePath($key));
    }

    /**
     * Return the cached value for $key; if missing, call $callback, store
     * the result for $ttl seconds, and return it. Classic read-through
     * cache pattern.
     */
    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $sentinel = new \stdClass();
        $hit      = $this->get($key, $sentinel);
        if ($hit !== $sentinel) return $hit;

        $value = $callback();
        $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    // ── File driver ──────────────────────────────────────────────────────────

    private function fileGet(string $key, mixed $default): mixed
    {
        $path = $this->filePath($key);
        if (!is_file($path)) return $default;
        $raw = @file_get_contents($path);
        if ($raw === false) return $default;

        $decoded = @unserialize($raw, ['allowed_classes' => self::SAFE_CACHED_CLASSES]);
        if (!is_array($decoded) || !array_key_exists('v', $decoded)) return $default;

        if ($decoded['exp'] > 0 && $decoded['exp'] < time()) {
            @unlink($path);
            return $default;
        }
        return $decoded['v'];
    }

    private function fileSet(string $key, mixed $value, int $ttlSeconds): void
    {
        if (!is_dir($this->fileDir)) {
            @mkdir($this->fileDir, 0775, true);
        }
        $payload = ['v' => $value, 'exp' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0];
        // Atomic write — rename is atomic on POSIX so concurrent readers
        // never observe a partial write.
        $tmp = tempnam($this->fileDir, 'cache_');
        if ($tmp === false) return;
        @file_put_contents($tmp, serialize($payload));
        @rename($tmp, $this->filePath($key));
    }

    private function filePath(string $key): string
    {
        return $this->fileDir . '/' . sha1($key) . '.cache';
    }

    private function prefix(string $key): string
    {
        // Isolate this app's keys when a shared Redis is used.
        $app = (string) ($_ENV['APP_NAME'] ?? 'cphpfw');
        return 'cache:' . preg_replace('/\s+/', '_', $app) . ':' . $key;
    }
}
