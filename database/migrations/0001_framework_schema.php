<?php
// database/migrations/0001_framework_schema.php
//
// Phase 37 consolidated framework schema — replaces the original baseline
// + 8 follow-up DDL migrations with one canonical CREATE TABLE set.
//
// Absorbed migrations (UPs only; the data/seed half lives in
// 0500_framework_data.php):
//   2026_04_20_000000_create_baseline_tables.php     — 29 base tables + INSERT seeds
//   2026_04_22_100000_create_jobs_table.php          — `jobs`
//   2026_04_22_100010_create_scheduled_tasks_table.php — `scheduled_tasks`
//   2026_04_22_120000_create_payments_table.php      — `payments`
//   2026_04_25_300000_create_module_status_table.php — `module_status`
//   2026_04_25_330000_create_system_layouts_tables.php — `system_layouts` + `system_block_placements`
//   2026_04_28_500000_add_theme_preference_to_users.php — column inlined into users CREATE
//   2026_05_02_100000_module_status_add_unlicensed_state.php — ENUM widened in CREATE
//   2026_05_02_300000_add_placement_type_and_slot_to_block_placements.php
//                                                    — columns inlined into system_block_placements CREATE
//   2026_05_02_310000_add_discoverability_to_system_layouts.php
//                                                    — columns + idx inlined into system_layouts CREATE
//   2026_05_02_600000_add_chromed_url_to_system_layouts.php
//                                                    — column inlined into system_layouts CREATE
//
// Naming convention (Phase 37):
//   0001_*  framework / central root schema
//   0010_*  per-module schema (modules/<slug>/migrations/)
//   0500_*  framework / central root data
//   0510_*  per-module data
//   0999_*  builder-side finalize (cross-module admin grants, etc.)
//
// Idempotency: every CREATE TABLE uses IF NOT EXISTS so a re-run against
// a partially-populated DB doesn't blow up. ALTERs aren't re-applied here
// — if you need to extend a base table later, add a fresh dated migration
// in addition to (not in place of) this consolidated file.

use Core\Database\Migration;

return new class extends Migration {

    public function up(): void
    {
        $this->runScript($this->upSql());
    }

    public function down(): void
    {
        $this->runScript($this->downSql());
    }

    private function upSql(): string
    {
        return <<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- USERS (2FA columns + TOTP replay protection + theme_preference)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username                  VARCHAR(50)  NULL UNIQUE,
    email                     VARCHAR(255) NOT NULL UNIQUE,
    `password`                VARCHAR(255) NULL COMMENT 'NULL for OAuth-only accounts',
    first_name                VARCHAR(100),
    last_name                 VARCHAR(100),
    avatar                    VARCHAR(500),
    bio                       TEXT,
    theme_preference          ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    is_active                 TINYINT(1) DEFAULT 1,
    is_superadmin             TINYINT(1) DEFAULT 0,
    two_factor_enabled        TINYINT(1) DEFAULT 0,
    two_factor_method         ENUM('email','sms','totp') NULL,
    two_factor_secret         VARCHAR(64) NULL,
    two_factor_confirmed      TINYINT(1) DEFAULT 0
                              COMMENT '1 = TOTP secret has been verified by user',
    two_factor_recovery_codes TEXT NULL
                              COMMENT 'JSON array of bcrypt-hashed recovery codes',
    totp_last_counter         BIGINT UNSIGNED NULL
                              COMMENT 'Last accepted TOTP counter — prevents replay attacks',
    email_verified_at         TIMESTAMP NULL,
    phone                     VARCHAR(30)  NULL,
    phone_verified_at         TIMESTAMP NULL,
    last_login_at             TIMESTAMP NULL,
    created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social OAuth providers linked to users
CREATE TABLE IF NOT EXISTS user_oauth (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    provider         ENUM('google','microsoft','apple','facebook','linkedin') NOT NULL,
    provider_id      VARCHAR(255) NOT NULL,
    token            TEXT NULL,
    refresh_token    TEXT NULL,
    token_expires_at TIMESTAMP NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_id (provider, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SYSTEM ROLES (global, admin-managed)
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL UNIQUE,
    slug          VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    is_system     TINYINT(1) DEFAULT 0 COMMENT 'System roles cannot be deleted',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PERMISSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS permissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL UNIQUE,
    slug          VARCHAR(150) NOT NULL UNIQUE,
    module        VARCHAR(80)  NOT NULL,
    `description` TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    INDEX idx_role_permissions_perm_id (permission_id),
    FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    INDEX idx_user_roles_role_id (role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- GROUPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `groups` (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL,
    slug          VARCHAR(150) NOT NULL UNIQUE,
    `description` TEXT,
    avatar        VARCHAR(500),
    is_public     TINYINT(1) DEFAULT 0 COMMENT '1=joinable without invite',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_roles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id      INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL,
    slug          VARCHAR(100) NOT NULL,
    `description` TEXT,
    base_role     ENUM('group_owner','group_admin','manager','editor','member') DEFAULT 'member'
                  COMMENT 'The built-in role this custom role inherits from',
    is_system     TINYINT(1) DEFAULT 0 COMMENT 'Built-in group roles cannot be deleted',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_role_slug (group_id, slug),
    FOREIGN KEY (group_id)   REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_role_permissions (
    group_role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    granted       TINYINT(1) DEFAULT 1,
    PRIMARY KEY (group_role_id, permission_id),
    FOREIGN KEY (group_role_id) REFERENCES group_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_groups (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    group_id      INT UNSIGNED NOT NULL,
    group_role_id INT UNSIGNED NOT NULL COMMENT 'Their role within this group',
    joined_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    invited_by    INT UNSIGNED NULL,
    UNIQUE KEY uq_user_group (user_id, group_id),
    INDEX idx_user_groups_group_id (group_id),
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (group_id)      REFERENCES `groups`(id)    ON DELETE CASCADE,
    FOREIGN KEY (group_role_id) REFERENCES group_roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (invited_by)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_owner_removal_requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id       INT UNSIGNED NOT NULL,
    requested_by   INT UNSIGNED NOT NULL COMMENT 'Owner who initiated the removal',
    target_user_id INT UNSIGNED NOT NULL COMMENT 'Owner being removed',
    new_role_id    INT UNSIGNED NULL COMMENT 'Role to switch to on approval; NULL = full removal',
    `status`       ENUM('pending','approved','rejected') DEFAULT 'pending',
    notified_at    TIMESTAMP NULL,
    resolved_at    TIMESTAMP NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id)       REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_invitations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id      INT UNSIGNED NOT NULL,
    invited_by    INT UNSIGNED NOT NULL,
    email         VARCHAR(255) NULL COMMENT 'For email-based invites',
    phone         VARCHAR(30)  NULL COMMENT 'For SMS invites',
    user_id       INT UNSIGNED NULL COMMENT 'Set when existing user is invited',
    token         VARCHAR(128) NOT NULL UNIQUE,
    group_role_id INT UNSIGNED NULL COMMENT 'Role to assign on accept',
    `status`      ENUM('pending','accepted','expired','cancelled') DEFAULT 'pending',
    expires_at    TIMESTAMP NOT NULL,
    accepted_at   TIMESTAMP NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id)      REFERENCES `groups`(id)    ON DELETE CASCADE,
    FOREIGN KEY (invited_by)    REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE SET NULL,
    FOREIGN KEY (group_role_id) REFERENCES group_roles(id) ON DELETE SET NULL,
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_group_invitations_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CONTENT OWNERSHIP
-- ============================================================
CREATE TABLE IF NOT EXISTS content_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(500) NOT NULL,
    slug            VARCHAR(500) NOT NULL,
    `body`          LONGTEXT,
    `type`          VARCHAR(80)  DEFAULT 'post',
    `status`        ENUM('draft','published','archived') DEFAULT 'draft',
    owner_type      ENUM('user','group') DEFAULT 'user',
    owner_user_id   INT UNSIGNED NULL,
    owner_group_id  INT UNSIGNED NULL,
    created_by      INT UNSIGNED NULL,
    seo_title       VARCHAR(255),
    seo_description VARCHAR(500),
    seo_keywords    VARCHAR(500),
    canonical_url   VARCHAR(1000),
    published_at    TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id)  REFERENCES users(id)    ON DELETE SET NULL,
    FOREIGN KEY (owner_group_id) REFERENCES `groups`(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)     REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_slug   (slug(191)),
    INDEX idx_type   (`type`),
    INDEX idx_status (`status`),
    FULLTEXT INDEX ft_content_search (title, `body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_transfer_requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id     INT UNSIGNED NOT NULL,
    requested_by   INT UNSIGNED NOT NULL,
    from_type      ENUM('user','group') NOT NULL,
    to_type        ENUM('user','group') NOT NULL,
    to_group_id    INT UNSIGNED NULL,
    to_user_id     INT UNSIGNED NULL,
    `status`       ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    TIMESTAMP NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id)   REFERENCES content_items(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)  REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEO & PERSISTENT LINKS
-- ============================================================
CREATE TABLE IF NOT EXISTS seo_links (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `path`      VARCHAR(1000) NOT NULL COMMENT 'The URL path this slug maps to',
    slug        VARCHAR(1000) NOT NULL COMMENT 'Permanent slug/vanity URL',
    target_type VARCHAR(80)   NULL COMMENT 'content, page, group, etc.',
    target_id   INT UNSIGNED  NULL,
    redirect_to VARCHAR(1000) NULL COMMENT 'If set, issues a 301 redirect',
    is_active   TINYINT(1) DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MENUS
-- ============================================================
CREATE TABLE IF NOT EXISTS menus (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL UNIQUE,
    location      VARCHAR(100) NOT NULL COMMENT 'header, footer, sidebar, etc.',
    `description` TEXT,
    is_active     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id         INT UNSIGNED NOT NULL,
    parent_id       INT UNSIGNED NULL COMMENT 'NULL = top level',
    label           VARCHAR(255) NOT NULL,
    url             VARCHAR(1000) NULL COMMENT 'NULL for unlinked submenu parents',
    icon            VARCHAR(100) NULL,
    target          VARCHAR(20) DEFAULT '_self',
    sort_order      INT DEFAULT 0,
    visibility      ENUM('always','logged_in','logged_out','role','permission','group') DEFAULT 'always',
    condition_value VARCHAR(255) NULL COMMENT 'role slug, permission slug, or group slug',
    show_on_pages   TEXT NULL COMMENT 'JSON array of page slugs; NULL=all pages',
    is_active       TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_id)   REFERENCES menus(id)      ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_menu_parent (menu_id, parent_id),
    INDEX idx_menu_items_parent_id (parent_id),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PUBLIC STATIC PAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS pages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(500) NOT NULL,
    slug            VARCHAR(500) NOT NULL UNIQUE,
    `body`          LONGTEXT,
    layout          VARCHAR(100) DEFAULT 'default',
    `status`        ENUM('draft','published') DEFAULT 'draft',
    is_public       TINYINT(1) DEFAULT 1 COMMENT '1=visible to guests',
    seo_title       VARCHAR(255),
    seo_description VARCHAR(500),
    seo_keywords    VARCHAR(500),
    sort_order      INT DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    published_at    TIMESTAMP NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_slug   (slug(191)),
    INDEX idx_status (`status`),
    FULLTEXT INDEX ft_pages_search (title, `body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FAQ
-- ============================================================
CREATE TABLE IF NOT EXISTS faq_categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(200) NOT NULL,
    slug          VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT,
    sort_order    INT DEFAULT 0,
    is_public     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS faqs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    question    TEXT NOT NULL,
    answer      LONGTEXT NOT NULL,
    sort_order  INT DEFAULT 0,
    is_public   TINYINT(1) DEFAULT 1,
    is_active   TINYINT(1) DEFAULT 1,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES faq_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)          ON DELETE SET NULL,
    FULLTEXT INDEX ft_faqs_search (question, answer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scope`     ENUM('site','page','function','group') DEFAULT 'site',
    scope_key   VARCHAR(255) NULL COMMENT 'page slug, function name, group id, etc.',
    `key`       VARCHAR(255) NOT NULL,
    `value`     LONGTEXT,
    `type`      ENUM('string','integer','boolean','json','text') DEFAULT 'string',
    is_public   TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scope_key (`scope`, scope_key, `key`),
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id         CHAR(36) PRIMARY KEY COMMENT 'UUID',
    user_id    INT UNSIGNED NOT NULL,
    `type`     VARCHAR(150) NOT NULL,
    title      VARCHAR(500),
    `body`     TEXT,
    `data`     JSON,
    `channel`  SET('in_app','email','sms') DEFAULT 'in_app',
    read_at    TIMESTAMP NULL,
    sent_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL / SMS / WEBHOOK LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS message_log (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel`          ENUM('email','sms','webhook') NOT NULL,
    recipient          VARCHAR(255) NOT NULL,
    `subject`          VARCHAR(500) NULL,
    `body`             TEXT,
    `status`           ENUM('queued','sent','failed') DEFAULT 'queued',
    provider           VARCHAR(80),
    provider_id        VARCHAR(255),
    `error`            TEXT NULL,
    attempts           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 3,
    next_attempt_at    TIMESTAMP NULL DEFAULT NULL,
    last_attempted_at  TIMESTAMP NULL DEFAULT NULL,
    sent_at            TIMESTAMP NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_channel_status (`channel`, `status`),
    INDEX idx_recipient (recipient),
    INDEX idx_retry_queue (`status`, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SESSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) PRIMARY KEY,
    user_id       INT UNSIGNED NULL,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    payload       LONGTEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id    INT UNSIGNED NULL COMMENT 'Who performed the action',
    emulated_user_id INT UNSIGNED NULL COMMENT 'Non-null when superadmin is emulating',
    superadmin_mode  TINYINT(1) DEFAULT 0,
    `action`         VARCHAR(150) NOT NULL,
    model            VARCHAR(100) NULL,
    model_id         INT UNSIGNED NULL,
    old_values       JSON NULL,
    new_values       JSON NULL,
    ip_address       VARCHAR(45),
    user_agent       VARCHAR(500) NULL,
    notes            TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id)    REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (emulated_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_actor      (actor_user_id),
    INDEX idx_action     (`action`),
    INDEX idx_model      (model, model_id),
    INDEX idx_audit_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSWORD RESETS
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    email      VARCHAR(255) NOT NULL,
    token      VARCHAR(64)  NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2FA — PENDING OTP CHALLENGES
-- ============================================================
CREATE TABLE IF NOT EXISTS two_factor_challenges (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    `code`     VARCHAR(255) NOT NULL COMMENT 'bcrypt hash of the 6-digit code',
    method     ENUM('email','sms','totp') NOT NULL,
    attempts   TINYINT UNSIGNED DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_method (user_id, method),
    INDEX idx_expires     (expires_at),
    INDEX idx_uid_exp     (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL VERIFICATION
-- ============================================================
CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL UNIQUE,
    token      VARCHAR(64) NOT NULL COMMENT 'SHA-256 of the plain random token',
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token   (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LOGIN ATTEMPTS (rate-limiting)
-- ============================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key  VARCHAR(80) NOT NULL COMMENT 'sha256 of type:ip or type:email',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NULL COMMENT 'Set when hard lockout triggered',
    INDEX idx_key_time (attempt_key, attempted_at),
    INDEX idx_locked   (attempt_key, locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- JOBS (work queue for Core\Queue\DatabaseQueue)
-- ============================================================
CREATE TABLE IF NOT EXISTS jobs (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue            VARCHAR(64)  NOT NULL DEFAULT 'default',
    class            VARCHAR(191) NOT NULL,
    payload          JSON         NOT NULL,
    status           ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    attempts         INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts     INT UNSIGNED NOT NULL DEFAULT 3,
    available_at     DATETIME     NOT NULL,
    reserved_at      DATETIME     NULL,
    reserved_by      VARCHAR(64)  NULL,
    last_error       TEXT         NULL,
    completed_at     DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ready (status, queue, available_at),
    KEY idx_reserved_by (reserved_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SCHEDULED TASKS (declarative recurring work)
-- ============================================================
CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(120) NOT NULL,
    class                VARCHAR(191) NOT NULL,
    payload              JSON         NOT NULL,
    schedule_expression  VARCHAR(120) NOT NULL,
    queue                VARCHAR(64)  NOT NULL DEFAULT 'default',
    enabled              TINYINT(1)   NOT NULL DEFAULT 1,
    next_run_at          DATETIME     NULL,
    last_run_at          DATETIME     NULL,
    last_run_status      VARCHAR(32)  NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name),
    KEY idx_due (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PAYMENTS (gateway-call audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway          VARCHAR(32)  NOT NULL,
    operation        VARCHAR(32)  NOT NULL,
    user_id          INT UNSIGNED NULL,
    gateway_id       VARCHAR(191) NOT NULL DEFAULT '',
    customer_ref     VARCHAR(191) NULL,
    source_ref       VARCHAR(191) NULL,
    amount_cents     INT UNSIGNED NULL,
    currency         VARCHAR(8)   NULL,
    ok               TINYINT(1)   NOT NULL DEFAULT 0,
    status           VARCHAR(64)  NOT NULL DEFAULT '',
    error            VARCHAR(500) NULL,
    request_json     JSON         NULL,
    response_json    JSON         NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gateway_id (gateway, gateway_id),
    KEY idx_user (user_id, created_at),
    KEY idx_failed (ok, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MODULE STATUS (runtime state per discovered module)
-- ============================================================
CREATE TABLE IF NOT EXISTS module_status (
    module_name   VARCHAR(64) NOT NULL,
    state         ENUM('active','disabled_dependency','disabled_admin','disabled_unlicensed')
                  NOT NULL DEFAULT 'active',
    missing_deps  JSON NULL,
    notice        TEXT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (module_name),
    INDEX idx_module_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SYSTEM LAYOUTS (page-composer for non-page surfaces:
-- dashboard, search, /account/data, profile, etc.)
-- ============================================================
CREATE TABLE IF NOT EXISTS system_layouts (
    name           VARCHAR(64) NOT NULL PRIMARY KEY,
    friendly_name  VARCHAR(255) NULL,
    module         VARCHAR(64) NULL,
    category       VARCHAR(64) NULL,
    description    TEXT NULL,
    chromed_url    VARCHAR(255) NULL,
    `rows`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    cols           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    col_widths     JSON NOT NULL,
    row_heights    JSON NOT NULL,
    gap_pct        TINYINT UNSIGNED NOT NULL DEFAULT 3,
    max_width_px   SMALLINT UNSIGNED NOT NULL DEFAULT 1280,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_system_layouts_module_category (module, category),
    CONSTRAINT chk_system_layouts_rows  CHECK (`rows` BETWEEN 1 AND 6),
    CONSTRAINT chk_system_layouts_cols  CHECK (cols BETWEEN 1 AND 4),
    CONSTRAINT chk_system_layouts_gap   CHECK (gap_pct BETWEEN 0 AND 20),
    CONSTRAINT chk_system_layouts_width CHECK (max_width_px BETWEEN 320 AND 4096)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_block_placements (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    system_name     VARCHAR(64) NOT NULL,
    row_index       TINYINT UNSIGNED NOT NULL,
    col_index       TINYINT UNSIGNED NOT NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    block_key       VARCHAR(128) NOT NULL,
    placement_type  ENUM('block','content_slot') NOT NULL DEFAULT 'block',
    slot_name       VARCHAR(64) NULL,
    settings        JSON NULL,
    visible_to      ENUM('any','auth','guest') NOT NULL DEFAULT 'any',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_system_block_placements_layout
        FOREIGN KEY (system_name) REFERENCES system_layouts(name) ON DELETE CASCADE,
    INDEX idx_sysblock_cell (system_name, row_index, col_index, sort_order),
    INDEX idx_sysblock_key (block_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
SQL;
    }

    private function downSql(): string
    {
        return <<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS system_block_placements;
DROP TABLE IF EXISTS system_layouts;
DROP TABLE IF EXISTS module_status;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS scheduled_tasks;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS two_factor_challenges;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS message_log;
DROP TABLE IF EXISTS integrations;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS faqs;
DROP TABLE IF EXISTS faq_categories;
DROP TABLE IF EXISTS pages;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS menus;
DROP TABLE IF EXISTS seo_links;
DROP TABLE IF EXISTS content_transfer_requests;
DROP TABLE IF EXISTS content_items;
DROP TABLE IF EXISTS group_invitations;
DROP TABLE IF EXISTS group_owner_removal_requests;
DROP TABLE IF EXISTS user_groups;
DROP TABLE IF EXISTS group_role_permissions;
DROP TABLE IF EXISTS group_roles;
DROP TABLE IF EXISTS `groups`;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS user_oauth;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;
SQL;
    }

    /**
     * Split a multi-statement SQL script on ';' followed by a newline and
     * run each statement through Database::query(). Skips empty/comment-
     * only statements. None of the consolidated DDL contains ';' inside
     * quoted values, so naive splitting is safe here.
     */
    private function runScript(string $sql): void
    {
        $statements = preg_split('/;\s*\r?\n/', $sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $nonComment = preg_replace('/^\s*--[^\n]*$/m', '', $stmt);
            if (trim($nonComment) === '') continue;
            $this->db->query($stmt);
        }
    }
};
