-- ============================================================
-- 2FA Migration — run after schema.sql
-- ============================================================

-- Add 2FA columns to users table
ALTER TABLE users
    ADD COLUMN two_factor_enabled    TINYINT(1)   DEFAULT 0      AFTER is_superadmin,
    ADD COLUMN two_factor_method     ENUM('email','sms','totp')
                                     NULL                          AFTER two_factor_enabled,
    ADD COLUMN two_factor_secret     VARCHAR(64)  NULL             AFTER two_factor_method,
    ADD COLUMN two_factor_confirmed  TINYINT(1)   DEFAULT 0
                COMMENT '1 = TOTP secret has been verified by user'
                                                                  AFTER two_factor_secret,
    ADD COLUMN two_factor_recovery_codes TEXT NULL
                COMMENT 'JSON array of bcrypt-hashed recovery codes'
                                                                  AFTER two_factor_confirmed;

-- Pending OTP challenges (email & SMS codes)
CREATE TABLE two_factor_challenges (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    `code`        VARCHAR(10)  NOT NULL            COMMENT 'bcrypt hash of the 6-digit code',
    method      ENUM('email','sms','totp') NOT NULL,
    attempts    TINYINT UNSIGNED DEFAULT 0,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_method (user_id, method),
    INDEX idx_expires (expires_at)
);
