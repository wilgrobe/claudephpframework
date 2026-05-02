<?php
// core/Services/CaptchaService.php
namespace Core\Services;

/**
 * CAPTCHA abstraction covering Cloudflare Turnstile, Google reCAPTCHA v2,
 * and hCaptcha. Which provider is active is driven by CAPTCHA_PROVIDER
 * in .env; when the provider is "none" (or site/secret keys are missing),
 * every method degrades gracefully — widget() renders nothing and
 * verify() returns true.
 *
 * Turnstile is the recommended default: free, privacy-friendly, no
 * intrusive challenges for most users, drop-in compatible with v2
 * reCAPTCHA's siteverify API contract.
 *
 * Typical wiring:
 *   View:   <?= captcha_widget() ?>   // inside your <form>
 *   Action: if (!captcha_verify($request->post('cf-turnstile-response',
 *                     $request->post('g-recaptcha-response', '')))) { deny }
 */
class CaptchaService
{
    /** True when a provider is selected AND has site + secret keys. */
    public static function isEnabled(): bool
    {
        return IntegrationConfig::enabled('captcha');
    }

    /**
     * Render the widget's HTML. Includes the provider's JS on-demand.
     * Returns '' when disabled so callers don't need to null-check.
     */
    public static function widget(): string
    {
        if (!self::isEnabled()) return '';

        $cfg  = IntegrationConfig::config('captcha');
        $site = htmlspecialchars((string) ($cfg['site_key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $prov = (string) ($cfg['driver'] ?? 'none');

        switch ($prov) {
            case 'turnstile':
                return '<div class="cf-turnstile" data-sitekey="' . $site . '"></div>'
                    . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            case 'recaptcha':
                return '<div class="g-recaptcha" data-sitekey="' . $site . '"></div>'
                    . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
            case 'hcaptcha':
                return '<div class="h-captcha" data-sitekey="' . $site . '"></div>'
                    . '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
        }
        return '';
    }

    /**
     * Verify a token against the active provider's siteverify endpoint.
     * Returns true when disabled so callers can gate unconditionally.
     */
    public static function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (!self::isEnabled()) return true;
        if (!$token) return false;

        $cfg    = IntegrationConfig::config('captcha');
        $secret = (string) ($cfg['secret_key'] ?? '');
        $prov   = (string) ($cfg['driver'] ?? 'none');
        if ($secret === '') return false;

        $endpoints = [
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
            'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
        ];
        $url = $endpoints[$prov] ?? null;
        if (!$url) return false;

        $payload = ['secret' => $secret, 'response' => $token];
        if ($remoteIp) $payload['remoteip'] = $remoteIp;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($payload),
            'timeout'       => 4.0,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return false;

        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['success']);
    }

    /**
     * Convenience for controllers: pull the right field name out of $_POST
     * regardless of which provider is active. Returns the token string or
     * null if missing.
     */
    public static function tokenFromRequest(array $post): ?string
    {
        // All three providers use different field names; check all.
        foreach (['cf-turnstile-response', 'g-recaptcha-response', 'h-captcha-response'] as $k) {
            if (!empty($post[$k])) return (string) $post[$k];
        }
        return null;
    }
}
