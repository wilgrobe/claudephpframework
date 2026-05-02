<?php
// core/Response.php
namespace Core;

class Response
{
    public function __construct(
        private string $body    = '',
        private int    $status  = 200,
        private array  $headers = []
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }

    public function withFlash(string $type, string $message): self
    {
        Session::flash($type, $message);
        return $this;
    }

    public static function redirect(string $url, int $code = 302): self
    {
        $r = new self('', $code, ['Location' => $url]);
        return $r;
    }

    public static function view(string $view, array $data = []): self
    {
        $content = View::render($view, $data);
        return new self($content, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            // Prevent sensitive page content from being cached in browser history or proxies
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * Like view() but allows caching for public, non-sensitive pages.
     */
    public static function publicView(string $view, array $data = [], int $maxAge = 300): self
    {
        $content = View::render($view, $data);
        return new self($content, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => "public, max-age=$maxAge",
        ]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        // SECURITY: Prefix JSON responses with )]}', to prevent XSSI (Cross-Site Script
        // Inclusion). JavaScript consumers must strip the first 6 characters before
        // calling JSON.parse(), or use the safeJson() helper below.
        // This is standard practice (used by Angular, Google APIs, etc.).
        $body = ")]}',\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            $body,
            $status,
            [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Pure JSON response for public APIs. No XSSI prefix — server-to-server
     * and mobile clients expect a valid JSON document starting with `{`.
     *
     * Use this for /api/v1/* endpoints. Use json() for browser-consumed
     * same-origin responses that could otherwise be stolen via <script> tag
     * inclusion by a malicious cross-origin page.
     */
    public static function apiJson(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            $body,
            $status,
            [
                'Content-Type'           => 'application/json',
                'Cache-Control'          => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    // ── Mutators ──────────────────────────────────────────────────────────────

    /** Fluent header setter — overwrites any prior value for $name. */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        // Case-insensitive match so callers don't have to know the canonical
        // capitalization we used internally. HTTP header names are case-insensitive.
        $lower = strtolower($name);
        foreach ($this->headers as $k => $_) {
            if (strtolower((string) $k) === $lower) return true;
        }
        return false;
    }

    public function status(): int { return $this->status; }
}
