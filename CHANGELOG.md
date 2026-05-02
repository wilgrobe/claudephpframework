# Changelog

All notable changes to PHP Framework v2 are documented here.

---

## [2.4.0] — Security Hardening + Features

### Security — Critical fixes
- **OAuth 2FA bypass fixed** — `attemptOAuth()` now runs the same `two_factor_enabled/confirmed` check as `attempt()`, returning `'2fa_required'` before completing login. A linked social provider can no longer bypass TOTP or OTP 2FA.
- **Open redirect patched** — Added `Auth::safeRedirect()` which validates the `intended` session value starts with exactly one `/`. Rejects `//evil.com`, `http://...`, and null-byte injections. Applied to all three post-login redirect points (password login, OAuth callback, 2FA challenge completion).
- **TOTP replay attack prevented** — `verifyTotpForUser()` now persists the last accepted TOTP counter in `users.totp_last_counter`. Codes whose counter is ≤ stored value are rejected — each code is effectively single-use within the ±30-second tolerance window.
- **Stored XSS via `<img>` tag patched** — `sanitizeHtml()` rewritten from regex scrubbing to `DOMDocument`-based allowlist. `<img>` removed entirely. Only `<a href>` (http/https/relative) and `<a title>` allowed as attributes. All other attributes stripped. Output re-sanitized at render time in public page view.
- **`superadmin_mode` no longer persisted to DB** — Mode tracked in `$_SESSION` only. Destroying all sessions revokes active superadmin privileges without any DB state to reset. DB column zeroed and scheduled for removal via migration.

### Security — High fixes
- **SSRF via broadcast host** — `pusherBroadcast()` calls `isSafeExternalHost()` before any outbound request. Blocks RFC-1918 private ranges, loopback (`127.x`, `0.0.0.0`), link-local (`169.254.x.x`), and AWS/GCP metadata endpoints.
- **Password reset timing oracle** — Changed from `password_verify($token, bcryptHash)` to `hash_equals(sha256($submitted), sha256Stored)`. Eliminates the fast/slow path email-enumeration leak. All existing bcrypt tokens invalidated on migration.
- **Login rate limiting** — New `RateLimiter` class tracks failed attempts per `sha256(ip)` and `sha256(email)` in `login_attempts` table. After 5 failures: remaining count shown. After 10: 15-minute hard lockout. Applied to `/login` and `/auth/2fa/challenge`.
- **View path traversal** — `View::resolvePath()` validates names against `/^[a-zA-Z0-9_.]+$/`, rejects `..`, and asserts resolved path via `realpath()` stays inside `BASE_PATH/app/Views`.
- **Challenge ID removed from HTML** — 2FA challenge ID no longer rendered in the HTML form. Read exclusively from `$_SESSION['2fa_challenge_id']` on verification.
- **HSTS header** — Added `Strict-Transport-Security: max-age=31536000; includeSubDomains` in `public/index.php` when `APP_ENV=production`. Removed deprecated `X-XSS-Protection`.

### Security — Medium fixes
- **OAuth access tokens encrypted** — Stored via `sodium_crypto_secretbox` in `user_oauth.token`.
- **Integration config real encryption** — Base64 stub replaced with full libsodium secretbox. Legacy base64 records detected and migrated transparently on next admin save.
- **Email verification random tokens** — Replaced `hash(email . created_at . key)` (guessable) with `bin2hex(random_bytes(32))` stored in new `email_verifications` table with 24-hour expiry and single-use enforcement.
- **Recovery codes SHA-256** — Changed from bcrypt (800ms worst-case) to `SHA-256 + hash_equals()`. Sufficient for 40-bit random codes; eliminates latency.
- **Stale 2FA challenge cleanup** — `SessionCleanupService` purges expired rows. Composite index added.

### Security — Low/Additional fixes
- **User-Agent truncated** — `substr(strip_tags($ua), 0, 500)` applied before audit log storage. DB column narrowed to `VARCHAR(500)`.
- **JSON XSSI** — `Response::json()` prefixes `)]}',\n` and adds `Cache-Control: no-store`.
- **IP spoofing protection** — `Request::ip()` only trusts `X-Forwarded-For` when `TRUSTED_PROXY` env var is set.
- **State-changing GET routes** — Owner removal and content transfer approval changed to POST with CSRF. GET shows confirmation page only.
- **OAuth config decryption** — `AuthController::getOAuthConfig()` now routes through `IntegrationService::config()` for proper libsodium decryption.
- **Production error handler** — Custom `set_exception_handler` and `set_error_handler` in `public/index.php`. Stack traces logged, generic 500 page returned to clients.
- **`Cache-Control: no-store`** on all `Response::view()` responses.
- **Password strength enforced** — Minimum raised from 8 to 12 characters. `password_strength` rule requires uppercase + lowercase + digit + special character. Applied to registration, password reset, admin user creation, and profile edit.

### New Features
- **File uploads** — `FileUploadService` with MIME detection, GD re-encoding, dimension limits, random filenames. `UploadsController` serves from outside web root with path traversal check.
- **Avatar upload** — Profile edit form supports photo upload with client-side preview.
- **Password strength meter** — Real-time 4-level bar + 5-item checklist in register, reset, and profile edit views.
- **Notifications** — `NotificationsController` with index, mark-read, mark-all-read, and unread count endpoints. Notification page with type icons and inline AJAX mark-read.
- **User search API** — `GET /api/users/search?q=` for group invite flow. Group invite form now has live autocomplete instead of manual user ID entry.
- **Site-wide search** — `GET /search` queries `content_items`, `pages`, and `faqs` via MySQL FULLTEXT. Search bar in topbar.
- **Sitemap** — `SitemapController` generates `/sitemap.xml` from published pages, public groups, and static routes.
- **`artisan` CLI** — `php artisan cleanup` purges 5 tables. Designed for crontab every 15 minutes.
- **`SessionCleanupService`** — Single-call cleanup for sessions, 2FA challenges, login attempts, email verifications, password resets.
- **Accessibility** — Skip-to-content link, `id="main-content"` on `<main>`, focus-visible rings in CSS.
- **`public/assets/css/app.css`** — External stylesheet with full component styles, responsive breakpoints, print styles, utility classes.
- **`security.txt`** — RFC 9116 compliant `public/.well-known/security.txt`.
- **`robots.txt`** — Blocks all admin/auth/private routes from crawlers.
- **nginx.conf.example** — Complete rewrite with TLS 1.3, OCSP stapling stubs, rate limiting zones, CVE-2013-4547 fix, `.well-known` passthrough, `server_tokens off`.
- **`.htaccess`** — Updated with all security headers, asset expiry, gzip, `ServerSignature Off`.
- **`composer.json`** — All required PHP extensions declared (`ext-sodium`, `ext-dom`, `ext-gd`, etc.).

### Database migrations added
- `database/2fa_migration.sql` — 2FA columns and `two_factor_challenges` table
- `database/security_fixes_migration.sql` — `login_attempts`, `email_verifications`, `totp_last_counter`, audit_log VARCHAR limit, password_resets token format, FULLTEXT indexes

---

## [2.3.0] — Two-Factor Authentication

- Email OTP, SMS OTP, and TOTP (RFC 6238 pure PHP — no library) methods
- Recovery codes (8 single-use codes per user)
- QR code provisioning URI for authenticator apps
- 2FA challenge middleware intercepts partially-authenticated sessions
- Disable flow requires current password confirmation
- Recovery code regeneration with password confirmation
- Audit log entries for 2FA events

---

## [2.2.0] — Groups, OAuth, Content

- Multi-group membership with per-group roles
- Built-in group roles: owner, admin, manager, editor, member
- Custom group roles with per-permission toggles
- Multi-owner groups with approval-required removal workflow
- Group invitations by email, SMS, or direct user
- OAuth social login: Google, Microsoft, Apple, Facebook, LinkedIn
- Content items with user or group ownership
- Content ownership transfer with approval workflow
- Superadmin mode toggle with user emulation (fully audited)

---

## [2.1.0] — Core Framework

- MVC router with middleware chains
- PDO database wrapper with forced prepared statements
- Session management with CSRF protection
- Multi-method validator with XSS sanitization
- Persistent SEO slug registry with 301 redirects
- Menu management with conditional visibility (role, permission, group, page)
- FAQ management with full-text search
- Static page management
- Scoped settings (site/page/function/group)
- Notification system (in-app, email, SMS channels)
- Audit log with superadmin emulation tracking
- libsodium-encrypted integration config storage
- Email/SMS message log

---

## [2.0.0] — Initial v2

- PHP 8.1 minimum
- Namespaced autoloading via Composer
- Groups, roles, permissions
- Email/password auth with bcrypt
- Admin panel for users, roles, groups
