-- ============================================================
-- PHP Framework v2 — Consolidated Install Schema
-- Merges: schema.sql, 2fa_migration.sql,
--         security_fixes_migration.sql, performance_indexes_migration.sql
--
-- Idempotent: drops existing tables before recreating.
-- WARNING: This WILL destroy existing data in every listed table.
-- ============================================================

CREATE DATABASE IF NOT EXISTS phpframework
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE phpframework;

SET FOREIGN_KEY_CHECKS = 0;

-- ── Drop all tables (reverse-dependency order, FK checks off) ───────────────
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS two_factor_challenges;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS message_log;
-- The `integrations` table was retired — credentials now live in .env.
-- Kept as a defensive drop here so migrating installs clean up the old table.
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

-- ============================================================
-- USERS  (2FA columns + TOTP replay protection merged;
--         superadmin_mode removed per security_fixes_migration)
-- ============================================================
CREATE TABLE users (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username                  VARCHAR(50)  NULL UNIQUE,
    email                     VARCHAR(255) NOT NULL UNIQUE,
    `password`                VARCHAR(255) NULL COMMENT 'NULL for OAuth-only accounts',
    first_name                VARCHAR(100),
    last_name                 VARCHAR(100),
    avatar                    VARCHAR(500),
    bio                       TEXT,
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
);

-- Social OAuth providers linked to users
CREATE TABLE user_oauth (
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
);

-- ============================================================
-- SYSTEM ROLES  (global, admin-managed)
-- ============================================================
CREATE TABLE roles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL UNIQUE,
    slug          VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    is_system     TINYINT(1) DEFAULT 0 COMMENT 'System roles cannot be deleted',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PERMISSIONS
-- ============================================================
CREATE TABLE permissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL UNIQUE,
    slug          VARCHAR(150) NOT NULL UNIQUE,
    module        VARCHAR(80)  NOT NULL,
    `description` TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module)
);

-- Role ↔ Permission
CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    INDEX idx_role_permissions_perm_id (permission_id),
    FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- User ↔ global system Role
CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    INDEX idx_user_roles_role_id (role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- ============================================================
-- GROUPS
-- ============================================================
CREATE TABLE `groups` (
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
);

-- Group-scoped roles (group_owner, group_admin, manager, editor, member, custom)
CREATE TABLE group_roles (
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
);

-- Permissions that can be toggled per custom group role
CREATE TABLE group_role_permissions (
    group_role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    granted       TINYINT(1) DEFAULT 1,
    PRIMARY KEY (group_role_id, permission_id),
    FOREIGN KEY (group_role_id) REFERENCES group_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- User ↔ Group membership
CREATE TABLE user_groups (
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
);

-- Owner-removal approval workflow
CREATE TABLE group_owner_removal_requests (
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
);

-- ============================================================
-- GROUP INVITATIONS
-- ============================================================
CREATE TABLE group_invitations (
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
);

-- ============================================================
-- CONTENT OWNERSHIP
-- ============================================================
CREATE TABLE content_items (
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
);

-- Transfer requests: individual → group ownership
CREATE TABLE content_transfer_requests (
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
);

-- ============================================================
-- SEO & PERSISTENT LINKS
-- ============================================================
CREATE TABLE seo_links (
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
);

-- ============================================================
-- MENUS
-- ============================================================
CREATE TABLE menus (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL UNIQUE,
    location      VARCHAR(100) NOT NULL COMMENT 'header, footer, sidebar, etc.',
    `description` TEXT,
    is_active     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menus_location (location)
);

CREATE TABLE menu_items (
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
);

-- ============================================================
-- PUBLIC STATIC PAGES
-- ============================================================
CREATE TABLE pages (
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
);

-- ============================================================
-- FAQ
-- ============================================================
CREATE TABLE faq_categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(200) NOT NULL,
    slug          VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT,
    sort_order    INT DEFAULT 0,
    is_public     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE faqs (
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
);

-- ============================================================
-- SETTINGS
-- ============================================================
CREATE TABLE settings (
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
);

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE notifications (
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
);

-- ============================================================
-- API / INTEGRATION CONFIGURATIONS
-- ============================================================
-- Integration credentials now live entirely in .env (see .env.example
-- for the full list of supported providers and env vars). The admin UI
-- at /admin/integrations is a read-only status dashboard that reads
-- from env. No table is required; the old `integrations` table and its
-- encryption-at-rest code path were removed.

-- ============================================================
-- EMAIL / SMS LOG
-- ============================================================
CREATE TABLE message_log (
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
);

-- ============================================================
-- SESSIONS
-- ============================================================
CREATE TABLE sessions (
    id            VARCHAR(128) PRIMARY KEY,
    user_id       INT UNSIGNED NULL,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    payload       LONGTEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_activity (last_activity)
);

-- ============================================================
-- AUDIT LOG
-- (user_agent capped at 500 chars per security_fixes_migration)
-- ============================================================
CREATE TABLE audit_log (
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
);

-- ============================================================
-- PASSWORD RESETS
-- (token column = VARCHAR(64) for sha256 hex per security_fixes_migration)
-- ============================================================
CREATE TABLE password_resets (
    email      VARCHAR(255) NOT NULL,
    token      VARCHAR(64)  NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    INDEX idx_token (token)
);

-- ============================================================
-- 2FA — PENDING OTP CHALLENGES
-- ============================================================
CREATE TABLE two_factor_challenges (
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
);

-- ============================================================
-- EMAIL VERIFICATION (random-token based)
-- ============================================================
CREATE TABLE email_verifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL UNIQUE,
    token      VARCHAR(64) NOT NULL COMMENT 'SHA-256 of the plain random token',
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token   (token),
    INDEX idx_expires (expires_at)
);

-- ============================================================
-- LOGIN ATTEMPTS (rate-limiting)
-- ============================================================
CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key  VARCHAR(80) NOT NULL COMMENT 'sha256 of type:ip or type:email',
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NULL COMMENT 'Set when hard lockout triggered',
    INDEX idx_key_time (attempt_key, attempted_at),
    INDEX idx_locked   (attempt_key, locked_until)
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- System permissions
INSERT INTO permissions (`name`, slug, module, `description`) VALUES
-- Users
('View Users',           'users.view',         'users',        'View user list and profiles'),
('Create Users',         'users.create',       'users',        'Create new user accounts'),
('Edit Users',           'users.edit',         'users',        'Edit user accounts'),
('Delete Users',         'users.delete',       'users',        'Delete user accounts'),
('Manage Users',         'users.manage',       'users',        'Full user management'),
-- Roles
('View Roles',           'roles.view',         'roles',        'View roles'),
('Create Roles',         'roles.create',       'roles',        'Create new roles'),
('Edit Roles',           'roles.edit',         'roles',        'Edit roles'),
('Delete Roles',         'roles.delete',       'roles',        'Delete roles'),
-- Groups
('View Groups',          'groups.view',        'groups',       'View groups'),
('Create Groups',        'groups.create',      'groups',       'Create new groups'),
('Edit Groups',          'groups.edit',        'groups',       'Edit groups'),
('Delete Groups',        'groups.delete',      'groups',       'Delete groups'),
('Manage Group Members', 'groups.members',     'groups',       'Add/remove group members'),
-- Content
('View Content',         'content.view',       'content',      'View content'),
('Create Content',       'content.create',     'content',      'Create content'),
('Edit Content',         'content.edit',       'content',      'Edit any content'),
('Delete Content',       'content.delete',     'content',      'Delete content'),
('Publish Content',      'content.publish',    'content',      'Publish/unpublish content'),
-- Pages
('Manage Pages',         'pages.manage',       'pages',        'Create/edit/delete static pages'),
-- Menus
('Manage Menus',         'menus.manage',       'menus',        'Create and manage navigation menus'),
-- FAQ
('Manage FAQ',           'faq.manage',         'faq',          'Manage FAQ entries'),
-- (Site settings + integrations are superadmin-only and enforced via
-- RequireSuperadmin middleware / Auth::isSuperAdmin() — no permission
-- rows are needed for them.)
-- Reports/Audit
('View Reports',         'reports.view',       'reports',      'Access reports'),
('View Audit Log',       'audit.view',         'audit',        'View system audit log'),
-- Notifications
('Send Notifications',   'notifications.send', 'notifications','Broadcast notifications');

-- System global roles
INSERT INTO roles (`name`, slug, `description`, is_system) VALUES
('Super Admin',  'super-admin', 'Full system access with emulation capability', 1),
('Administrator','admin',       'Full administrative access',                   1),
('Editor',       'editor',      'Create and edit content',                      0),
('Viewer',       'viewer',      'Read-only access',                             0);

-- Super Admin gets all permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

-- Admin gets all except superadmin-specific
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
 WHERE slug NOT IN ('users.delete','roles.delete','audit.view');

-- Editor
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions
 WHERE module IN ('content','faq')
    OR slug IN ('users.view','groups.view','pages.manage');

-- Viewer
INSERT INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions
 WHERE slug LIKE '%.view';

-- Seed groups
INSERT INTO `groups` (`name`, slug, `description`, is_public) VALUES
('Engineering', 'engineering', 'Engineering team', 0),
('Marketing',   'marketing',   'Marketing team',   0),
('Management',  'management',  'Management team',  0);

-- Built-in group roles for each group (is_system=1)
INSERT INTO group_roles (group_id, `name`, slug, `description`, base_role, is_system) VALUES
(1,'Group Owner','group_owner','Full ownership of the group',       'group_owner',1),
(1,'Group Admin','group_admin','Administrative access within group','group_admin',1),
(1,'Manager',    'manager',    'Manage members and content',        'manager',    1),
(1,'Editor',     'editor',     'Create and edit group content',     'editor',     1),
(1,'Member',     'member',     'Standard group member',             'member',     1),
(2,'Group Owner','group_owner','Full ownership of the group',       'group_owner',1),
(2,'Group Admin','group_admin','Administrative access within group','group_admin',1),
(2,'Manager',    'manager',    'Manage members and content',        'manager',    1),
(2,'Editor',     'editor',     'Create and edit group content',     'editor',     1),
(2,'Member',     'member',     'Standard group member',             'member',     1),
(3,'Group Owner','group_owner','Full ownership of the group',       'group_owner',1),
(3,'Group Admin','group_admin','Administrative access within group','group_admin',1),
(3,'Manager',    'manager',    'Manage members and content',        'manager',    1),
(3,'Editor',     'editor',     'Create and edit group content',     'editor',     1),
(3,'Member',     'member',     'Standard group member',             'member',     1);

-- Seed admin user (password: Admin@123)
INSERT INTO users (username, email, `password`, first_name, last_name, is_active, is_superadmin, email_verified_at) VALUES
('admin',  'admin@example.com',  '$2y$12$HFVsCqoJuxXGuRkIZfNsDeUYwsaj0RDwpDVbvld4ETM40dCr.30ru', 'System', 'Admin',  1, 1, NOW()),
('editor', 'editor@example.com', '$2y$12$HFVsCqoJuxXGuRkIZfNsDeUYwsaj0RDwpDVbvld4ETM40dCr.30ru', 'Jane',   'Editor', 1, 0, NOW()),
('viewer', 'viewer@example.com', '$2y$12$HFVsCqoJuxXGuRkIZfNsDeUYwsaj0RDwpDVbvld4ETM40dCr.30ru', 'John',   'Viewer', 1, 0, NOW());

INSERT INTO user_roles (user_id, role_id) VALUES (1,1),(2,3),(3,4);

-- Admin is group_owner of Engineering, editor is editor, viewer is member of Marketing
INSERT INTO user_groups (user_id, group_id, group_role_id) VALUES
(1, 1, 1),
(2, 1, 4),
(3, 2, 5);

-- Default site settings
INSERT INTO settings (`scope`, `key`, `value`, `type`, is_public) VALUES
-- Core site identity
('site', 'site_name',           'My Application',               'string',  1),
('site', 'site_tagline',        'Built with PHP Framework v2',  'string',  1),
('site', 'contact_email',       'hello@example.com',            'string',  0),
('site', 'allow_registration',  'true',                         'boolean', 1),
('site', 'require_email_verify','true',                         'boolean', 0),
('site', 'default_group_role',  'member',                       'string',  0),
('site', 'maintenance_mode',    'false',                        'boolean', 0),

-- Group membership policy. Defaults preserve the prior behavior — users
-- may belong to multiple groups, and any signed-in user may create a new
-- group. Toggle via /admin/settings/groups.
('site', 'single_group_only',   'false','boolean', 0),
('site', 'allow_group_creation','true', 'boolean', 0),

-- Appearance. Defaults mirror the CSS custom properties declared in
-- layout/header.php :root — any value here overrides that baseline at
-- render time, so a missing / empty setting falls back cleanly.
-- Toggle via /admin/settings/appearance.
('site', 'layout_orientation',  'sidebar',   'string', 1),
('site', 'color_primary',       '#4f46e5',   'string', 1),
('site', 'color_primary_dark',  '#3730a3',   'string', 1),
('site', 'color_secondary',     '#0ea5e9',   'string', 1),
('site', 'color_success',       '#10b981',   'string', 1),
('site', 'color_danger',        '#ef4444',   'string', 1),
('site', 'color_warning',       '#f59e0b',   'string', 1),
('site', 'color_info',          '#3b82f6',   'string', 1),

-- Footer configuration. Admins tune these via /admin/settings/footer.
-- {{year}} is substituted at render time so the copyright ticks over
-- automatically without anyone having to edit the setting each January.
('site', 'footer_enabled',       'true',                            'boolean', 1),
('site', 'footer_logo_text',     '🚀 My Application',              'string',  1),
('site', 'footer_tagline',       '',                                'string',  1),
('site', 'footer_copyright',     '© {{year}} My Application',       'string',  1),
('site', 'footer_powered_by',    'Powered by PHP Framework v2',     'string',  1),
('site', 'footer_show_menu',     'true',                            'boolean', 1),
('site', 'footer_menu_location', 'footer',                          'string',  1);

-- Default menus
INSERT INTO menus (`name`, location, `description`) VALUES
('Main Navigation', 'header', 'Primary site navigation'),
('Footer Links',    'footer', 'Footer navigation links');

INSERT INTO menu_items (menu_id, parent_id, label, url, sort_order, visibility) VALUES
(1, NULL, 'Home',      '/',                      1, 'always'),
(1, NULL, 'Dashboard', '/dashboard',             2, 'logged_in'),
(1, NULL, 'Groups',    '/groups',                3, 'logged_in'),
(1, NULL, 'Admin',     '/admin',                 4, 'role'),
(2, NULL, 'FAQ',       '/faq',                   1, 'always'),
-- Pages are served at /{slug} directly (the /page/{slug} legacy route
-- still 301-redirects here for backward compat with old bookmarks).
(2, NULL, 'Privacy',   '/privacy-policy',        2, 'always'),
(2, NULL, 'Terms',     '/terms-of-service',      3, 'always');

UPDATE menu_items SET condition_value = 'admin' WHERE label = 'Admin';

-- Default pages
INSERT INTO pages (title, slug, `body`, `status`, is_public) VALUES
('Privacy Policy',   'privacy-policy',   '<h1>Privacy Policy</h1><p>Your privacy matters to us.</p>',                     'published', 1),
('Terms of Service', 'terms-of-service', '<h1>Terms of Service</h1><p>By using this service you agree to these terms.</p>','published', 1),
('About Us',         'about',            '<h1>About Us</h1><p>Welcome to our platform.</p>',                              'published', 1);

-- Default FAQ
INSERT INTO faq_categories (`name`, slug, sort_order) VALUES
('General', 'general', 1),
('Account', 'account', 2);

INSERT INTO faqs (category_id, question, answer, sort_order, is_public) VALUES
(1, 'What is this platform?',           'This is a multi-group collaboration platform.',                                         1, 1),
(2, 'How do I reset my password?',      'Click "Forgot Password" on the login page to receive a reset link.',                    1, 1),
(2, 'Can I belong to multiple groups?', 'Yes! You can be a member of multiple groups and have different roles in each.',        2, 1);
