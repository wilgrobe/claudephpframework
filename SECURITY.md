# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 2.x (latest) | ✅ |
| 1.x | ❌ End of life |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

**Email:** security@yourdomain.com  
**Response time:** We aim to acknowledge reports within 48 hours and provide a fix timeline within 7 days.

Please include:
- Description of the vulnerability and affected component
- Steps to reproduce or proof-of-concept
- Impact assessment (what an attacker could achieve)
- Your name/handle for credit (optional)

## Scope

**In scope:**
- Authentication and session management
- Authorization bypasses (privilege escalation, IDOR)
- Injection vulnerabilities (SQL, XSS, SSTI, command injection)
- Cryptographic weaknesses
- 2FA bypass mechanisms
- File upload vulnerabilities
- CSRF on state-changing operations
- SSRF in integration configurations
- Rate limiting bypasses

**Out of scope:**
- Denial-of-service attacks requiring authenticated access
- Social engineering
- Physical attacks
- Issues in third-party dependencies (report to the respective project)
- Vulnerabilities requiring `APP_DEBUG=true` to be enabled

## Disclosure Policy

We follow coordinated disclosure. Once a fix is released, we will:
1. Credit the reporter in `CHANGELOG.md` (with permission)
2. Publish details in `CHANGELOG.md` after affected users have had reasonable time to update

## Known Mitigations

The following security controls are implemented in this release:

- **SQL injection:** All queries use PDO prepared statements with `ATTR_EMULATE_PREPARES = false`
- **XSS:** Input sanitized via `htmlspecialchars()`. Rich text uses `DOMDocument` allowlisting
- **CSRF:** `hash_equals()` token comparison on all POST operations
- **Rate limiting:** IP + email based in `login_attempts` table
- **2FA:** TOTP replay prevention via `totp_last_counter`; OTP 5-attempt limit
- **Encryption:** libsodium `crypto_secretbox` for integration credentials and OAuth tokens
- **File uploads:** MIME detection by bytes, GD re-encoding, path traversal assertion
- **SSRF:** Outbound hosts validated against RFC-1918 and reserved ranges
- **Error handling:** Stack traces never exposed in production
