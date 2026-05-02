# Claude PHP Framework v2

A production-ready PHP 8.1+ MVC framework featuring multi-group membership, social OAuth, superadmin emulation, two-factor authentication (Email / SMS / TOTP), content ownership, secure file uploads, full-text search, menus, FAQ, pages, notifications, and libsodium-encrypted API integrations — with a thorough security hardening pass.

\---

## Requirements

|Dependency|Minimum|
|-|-|
|PHP|8.1+|
|MySQL / MariaDB|8.0+ / 10.6+|
|Extensions|`ext-sodium` `ext-dom` `ext-pdo` `ext-pdo\_mysql` `ext-gd` `ext-json` `ext-mbstring` `ext-openssl`|
|Composer|2.x|
|Web server|Nginx (recommended) or Apache|

\---

## Quick Start

```bash
cp .env.example .env
# Edit .env — set DB credentials, APP\_URL, APP\_KEY (32+ random chars)

composer install

mysql -u root -p < database/schema.sql
mysql -u root -p < database/2fa\_migration.sql
mysql -u root -p < database/security\_fixes\_migration.sql

# Point web server document root to /public
# Review nginx.conf.example

# Cron: purge stale sessions/tokens every 15 min
echo "\*/15 \* \* \* \* www-data php /var/www/claudephpframework/artisan cleanup" | sudo tee /etc/cron.d/claudephpframework
```

**Default admin:** `admin@example.com` / `Admin@123` — change immediately.

\---

## Architecture

```
claudephpframework/
├── app/
│   ├── Controllers/        17 controllers including Admin/ subdirectory
│   ├── Middleware/         Auth, CSRF, Guest, RequireAdmin, TwoFactor
│   ├── Models/             Group, Role, User
│   └── Views/              58 PHP templates
├── core/
│   ├── Auth/               Auth.php, RateLimiter.php, TwoFactorService.php
│   ├── Database/           PDO wrapper (forced prepared statements)
│   ├── Http/               Request (SSRF-safe IP), Response, View
│   ├── SEO/                SeoManager (persistent slugs + 301 redirects)
│   ├── Services/           8 services: File, Integration, Mail, Menu,
│   │                       Notification, SessionCleanup, Settings, SMS
│   └── Validation/         Validator with XSS sanitization + password\_strength
├── database/
│   ├── schema.sql
│   ├── 2fa\_migration.sql
│   └── security\_fixes\_migration.sql
├── public/
│   ├── .htaccess           Full security headers, caching, compression
│   ├── .well-known/        security.txt (RFC 9116)
│   ├── assets/css/app.css
│   ├── index.php           Production error handler, HSTS, CSP
│   └── robots.txt
├── artisan                 CLI task runner (cleanup command)
├── routes/web.php          \~300 lines
└── nginx.conf.example      TLS 1.3, rate limiting zones, CVE-2013-4547 fix
```

\---

## Security Hardening Summary

Every finding from a full security audit has been remediated:

|#|Finding|Fix|
|-|-|-|
|C1|OAuth bypasses 2FA|`attemptOAuth()` runs full 2FA check; returns `'2fa\_required'`|
|C2|Open redirect via `intended`|`Auth::safeRedirect()` validates path starts with `/\[^/]`|
|C3|`superadmin\_mode` persisted in DB|Session-only; destroying sessions revokes privilege instantly|
|C4|TOTP replay within ±30s window|`totp\_last\_counter` stored per user; counter must be strictly greater|
|C5|XSS via `<img>` in sanitizeHtml|DOMDocument allowlist; `<img>` removed; `href` validated to http(s) only|
|H1|SSRF via broadcast host config|`isSafeExternalHost()` blocks RFC-1918, loopback, metadata endpoints|
|H2|Password reset timing oracle|SHA-256 token + `hash\_equals()` — eliminates bcrypt fast/slow path|
|H3|No rate limiting|`RateLimiter`: 5 → warning; 10 → 15-min lockout; tracks IP + email|
|H4|View path traversal|`realpath()` assertion that resolved path stays in views directory|
|H5|CSP `unsafe-inline`|Documented; `form-action` + `base-uri` added; inline script migration noted|
|H6|Challenge ID in HTML form|Removed from form; read from `$\_SESSION` only|
|H7|HSTS header missing|Added in `public/index.php` for `APP\_ENV=production`|
|M1|OAuth tokens plaintext|`sodium\_crypto\_secretbox` encryption in `user\_oauth.token`|
|M2|Integration config base64 stub|Real libsodium secretbox with nonce + legacy migration path|
|M3|Stale 2FA challenges accumulate|Index + periodic cleanup in `SessionCleanupService`|
|M4|Email verification guessable|Random `bin2hex(random\_bytes(32))` stored in `email\_verifications` table|
|M5|Recovery code bcrypt latency|SHA-256 + `hash\_equals()` — constant-time, sub-millisecond|
|L1|User-Agent unbounded in audit log|`substr(strip\_tags($ua), 0, 500)` before storage|
|L2|JSON XSSI|Responses prefixed `)]}',\\n` + `Cache-Control: no-store`|
|+|IP spoofing in rate limiter|`X-Forwarded-For` only trusted with `TRUSTED\_PROXY` env var|
|+|State-changing GET routes|All mutations POST-only; GET shows confirmation page|
|+|OAuth config raw base64|`AuthController` now routes through `IntegrationService::config()`|
|+|Production error disclosure|Custom exception handler; no stack traces in output|
|+|Cache-Control on auth pages|`no-store` on every `Response::view()`|
|+|Password strength|Min 12 chars + uppercase/lowercase/digit/special; real-time meter|

\---

## Groups \& Roles

Users can belong to multiple groups with different roles in each group.

**Built-in hierarchy (highest to lowest):**
`group\_owner › group\_admin › manager › editor › member`

**Custom roles:** owners/admins can create custom roles based on `manager`, `editor`, or `member`, with individual permission toggles.

**Multi-owner workflow:** Removing an owner requires the target to approve via a CSRF-protected confirmation page (email link → GET preview → POST action).

\---

## Two-Factor Authentication

|Method|Details|
|-|-|
|Email OTP|6-digit code, 10-min expiry, 5-attempt limit, bcrypt stored|
|SMS OTP|Same as email, delivered via Twilio/Vonage|
|TOTP|RFC 6238 pure PHP (no library); works with Google/Microsoft Authenticator, Authy|

Recovery codes: 8 single-use SHA-256-hashed codes, regeneratable with password confirmation.

\---

## File Uploads

```php
$uploader = new \\Core\\Services\\FileUploadService();
$path     = $uploader->uploadImage($\_FILES\['avatar'], 'avatars', 2\_097\_152);
$url      = $uploader->url($path);
```

Security: MIME by `mime\_content\_type()`, GD re-encoding strips metadata/polyglots, 8000×8000 decompression bomb limit, random filenames, files stored outside web root, served through `UploadsController` with path traversal assertion.

\---

## Integrations

Configure via **Admin → Integrations**. Credentials encrypted with `sodium\_crypto\_secretbox`.

|Category|Type strings|
|-|-|
|Email|`email\_smtp` `email\_sendgrid` `email\_mailgun` `email\_ses`|
|SMS|`sms\_twilio` `sms\_vonage`|
|AI|`ai\_openai` `ai\_anthropic` `ai\_gemini`|
|Broadcasting|`broadcast\_pusher` `broadcast\_ably` `broadcast\_soketi`|
|OAuth|`oauth\_google` `oauth\_microsoft` `oauth\_apple` `oauth\_facebook` `oauth\_linkedin`|
|Storage|`storage\_s3` `storage\_gcs`|

\---

## Helpers

```php
e($value)             // htmlspecialchars — use on all view output
csrf\_field()          // Hidden CSRF input
config('app.name')    // Dot-notation config access
setting('site\_name')  // DB-scoped site setting
menu('header')        // Visibility-filtered menu tree
auth()                // Auth::getInstance()
old('email')          // Repopulate form fields
str\_slug($text)       // URL-safe slug
asset('/img/x.png')   // Absolute asset URL
```

\---

## Pre-Launch Checklist

* \[ ] `APP\_KEY` is 32+ random characters, not the example value
* \[ ] `APP\_ENV=production`, `APP\_DEBUG=false`
* \[ ] Default admin password changed
* \[ ] All three SQL migrations run
* \[ ] HTTPS configured; HSTS uncommented in `.htaccess`
* \[ ] `TRUSTED\_PROXY=` set only if behind a load balancer
* \[ ] Cron cleanup job added (`\*/15 \* \* \* \*`)
* \[ ] At least one email integration configured
* \[ ] `security.txt` updated with your contact email
* \[ ] `robots.txt` reviewed for your URL structure
* \[ ] `nginx.conf.example` rate limiting zones enabled

