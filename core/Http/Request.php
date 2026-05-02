<?php
// core/Http/Request.php
namespace Core\Http;

class Request
{
    private array  $params  = [];
    private string $path    = '/';

    private function __construct(
        private string $method,
        private array  $query,
        private array  $post,
        private array  $server,
        private array  $cookies,
        private array  $files
    ) {
        $this->path = '/' . ltrim(parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    }

    public static function capture(): static
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // SECURITY NOTE: HTML forms can only emit GET/POST, so a hidden
        // <input name="_method" value="DELETE"> on a POST form lets it
        // dispatch to a DELETE route. CSRF protection is preserved because
        // CsrfMiddleware validates every method in {POST,PUT,PATCH,DELETE}
        // — the effective method after override is what it sees.
        //
        // Only POST can be overridden, and only to the three state-changing
        // verbs. GET stays GET, and you can't upgrade GET to POST.
        if ($method === 'POST' && !empty($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new static(
            $method,
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES
        );
    }

    public function method(): string { return $this->method; }
    public function path(): string   { return $this->path; }

    /** True for methods safe to repeat without side effects (GET, HEAD, OPTIONS). */
    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function setPath(string $path): void { $this->path = $path; }
    public function setParams(array $params): void { $this->params = array_values($params); }

    public function param(int $index, mixed $default = null): mixed
    {
        return $this->params[$index] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) return $this->post;
        return $this->post[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function isPost(): bool { return $this->method === 'POST'; }
    public function isGet(): bool  { return $this->method === 'GET'; }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function ip(): string
    {
        // SECURITY: HTTP_X_FORWARDED_FOR is a client-supplied header and can be spoofed.
        // Only trust it when TRUSTED_PROXY is configured in the environment, indicating
        // the app sits behind a known reverse proxy (nginx, load balancer, etc.).
        // Without this guard, a client can set X-Forwarded-For: 127.0.0.1 to bypass
        // IP-based rate limiting.
        if (!empty($this->server['HTTP_X_FORWARDED_FOR']) && !empty($_ENV['TRUSTED_PROXY'])) {
            // XFF may be a comma-separated list: "client, proxy1, proxy2"
            // The leftmost IP is the original client; strip and validate it.
            $ips   = array_map('trim', explode(',', $this->server['HTTP_X_FORWARDED_FOR']));
            $first = $ips[0] ?? '';
            if (filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $first;
            }
        }

        // Default: use the direct connection IP (always trustworthy)
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        // "User attempted to upload something" — not "upload succeeded".
        // The old semantics silently hid file-too-big / partial-upload errors
        // from the handler, so oversized uploads vanished without an error
        // message. Now we return true whenever a file slot exists and isn't
        // UPLOAD_ERR_NO_FILE; downstream FileUploadService translates the
        // specific error code into a readable exception the caller shows to
        // the user.
        return isset($this->files[$key])
            && ($this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }
}
