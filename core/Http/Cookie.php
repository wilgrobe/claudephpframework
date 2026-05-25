<?php
// core/Http/Cookie.php
namespace Core\Http;

/**
 * Phase 43.197b H3 — central cookie setter with TRUST_PROXY-aware
 * Secure detection and HttpOnly-by-default.
 *
 * Pre-fix callers used `setcookie(..., ['secure' => !empty($_SERVER['HTTPS'])])`
 * which evaluates to false behind any TLS-terminating reverse proxy
 * (Cloudflare, Caddy, Apache mod_proxy) unless X-Forwarded-Proto is
 * honored. Real prod deploys per `docs/deploy-*.md` terminate TLS
 * upstream — the cookie ships Secure=0, allowing a network attacker
 * on the LAN/coffee-shop/ISP path to read + replay it on the first
 * plaintext request.
 *
 * Centralizing in one helper lets every cookie-setting caller pick
 * up the proxy-aware Secure detection automatically, and defaults
 * HttpOnly=true unless the caller explicitly opts out (e.g. a JS-
 * readable consent cookie).
 *
 * Usage:
 *   Cookie::set('theme_pref', $pref);                    // defaults
 *   Cookie::set('ccc', $v, ['httponly' => false]);       // JS-readable
 *   Cookie::set('x', $v, ['expires' => time()+3600]);    // 1h expiry
 */
final class Cookie
{
    /**
     * Drop-in setcookie replacement. Honors the same option keys
     * (expires / path / domain / secure / httponly / samesite) but
     * auto-derives Secure from X-Forwarded-Proto when TRUST_PROXY=1,
     * and defaults HttpOnly=true.
     *
     * @return bool setcookie's return value
     */
    public static function set(string $name, string $value, array $options = []): bool
    {
        $defaults = [
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        // Caller-supplied options override the defaults — except for
        // 'secure': if the caller passed `false` but the request IS
        // secure-by-proxy, we force-upgrade to true. Operators should
        // never deliberately ship Secure=0 on a TLS-terminated origin.
        $opts = $options + $defaults;
        if (self::isSecureRequest()) {
            $opts['secure'] = true;
        }
        return setcookie($name, $value, $opts);
    }

    /**
     * True if the current request reached PHP over HTTPS, either
     * directly (HTTPS=on) or via a trusted reverse proxy
     * (X-Forwarded-Proto: https when TRUST_PROXY=1 in env).
     */
    public static function isSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443) return true;
        $trustProxy = (string) ($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?? '');
        if ($trustProxy !== '' && $trustProxy !== '0' && $trustProxy !== 'false') {
            $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($fwd === 'https') return true;
        }
        return false;
    }
}
