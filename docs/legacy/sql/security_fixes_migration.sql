-- ============================================================
-- Security Fixes Migration
-- Run after schema.sql and 2fa_migration.sql
-- Addresses all findings from the security evaluation
-- ============================================================

-- ── Fix: TOTP replay protection ───────────────────────────────────────────────
-- Track last accepted TOTP counter per user so codes can't be reused
-- within the ±1 period tolerance window.
ALTER TABLE users
    ADD COLUMN totp_last_counter BIGINT UNSIGNED NULL
        COMMENT 'Last accepted TOTP counter value — prevents replay attacks'
        AFTER two_factor_recovery_codes;

-- ── Fix: superadmin_mode — session-only, not persisted ───────────────────────
-- The superadmin_mode DB column is removed. Active mode is now tracked
-- exclusively in the PHP session so revoking sessions also revokes the privilege.
-- Step 1: Zero out any currently-set values immediately.
UPDATE users SET superadmin_mode = 0 WHERE superadmin_mode = 1;

-- Step 2: Drop the column once the updated Auth.php is deployed.
-- Run this AFTER deploying the code that no longer reads/writes this column.
ALTER TABLE users DROP COLUMN IF EXISTS superadmin_mode;

-- ── Fix: Email verification — random tokens ───────────────────────────────────
-- Replaces deterministic hash(email . created_at . key) tokens with random
-- tokens stored securely. Old approach was guessable if created_at was known.
CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL UNIQUE,
    token      VARCHAR(64) NOT NULL COMMENT 'SHA-256 of the plain random token',
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
);

-- ── Fix: Login rate limiting ──────────────────────────────────────────────────
-- Tracks failed login attempts per IP and email to enforce lockouts.
-- Records are pruned automatically by the RateLimiter class (~1% of requests).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key  VARCHAR(80) NOT NULL COMMENT 'sha256 of type:ip or type:email',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NULL COMMENT 'Set when hard lockout triggered',
    INDEX idx_key_time (attempt_key, attempted_at),
    INDEX idx_locked   (attempt_key, locked_until)
);

-- ── Fix: 2FA stale challenge cleanup index ────────────────────────────────────
-- Composite index makes the expires_at filter efficient for large tables.
-- The RateLimiter and TwoFactorService both query with user_id + expires_at.
ALTER TABLE two_factor_challenges
    ADD INDEX IF NOT EXISTS idx_uid_exp (user_id, expires_at);

-- Purge any existing expired challenges (one-time cleanup)
DELETE FROM two_factor_challenges
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- ── Fix: audit_log user_agent column — enforce max length ────────────────────
-- The PHP code now truncates to 500 chars before insert.
-- Cap the DB column too as an extra guard.
ALTER TABLE audit_log
    MODIFY COLUMN user_agent VARCHAR(500) NULL;

-- ── Fix: password_resets — update token format from bcrypt to sha256 ─────────
-- Existing bcrypt tokens in password_resets will fail the new hash_equals check.
-- Safest is to invalidate all current tokens (users will need to request a new reset).
DELETE FROM password_resets;
-- Also tighten token column to VARCHAR(64) (sha256 hex = 64 chars).
ALTER TABLE password_resets
    MODIFY COLUMN token VARCHAR(64) NOT NULL;

-- ── Fix: Integration config — column comment update ───────────────────────────
-- Config values are now encrypted with libsodium secretbox (not base64).
-- Existing base64-encoded rows will be transparently migrated on next admin save.
ALTER TABLE integrations
    MODIFY COLUMN config TEXT NOT NULL
        COMMENT 'libsodium secretbox encrypted JSON: base64(nonce[24] . ciphertext)';

-- ── Fix: Add FULLTEXT search to content_items ────────────────────────────────
ALTER TABLE content_items ADD FULLTEXT INDEX idx_content_search (title, body);

-- ── Fix: Add FULLTEXT search to pages ────────────────────────────────────────
ALTER TABLE pages ADD FULLTEXT INDEX idx_pages_search (title, body);
SELECT 'Security migration complete.' AS status;
SELECT
    (SELECT COUNT(*) FROM email_verifications) AS email_verifications_table_exists,
    (SELECT COUNT(*) FROM login_attempts)       AS login_attempts_table_exists,
    (SELECT COUNT(*) FROM password_resets)      AS password_resets_cleared;
