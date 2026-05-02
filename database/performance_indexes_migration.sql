-- database/performance_indexes_migration.sql
--
-- Performance migration: add indexes on foreign-key columns that are joined
-- or filtered on every request, plus FULLTEXT indexes for search queries.
--
-- All statements use IF NOT EXISTS where supported. On MySQL versions that
-- do not support IF NOT EXISTS on CREATE INDEX, run the individual CREATE
-- INDEX statements and ignore "Duplicate key name" errors.

-- ── Auth hot path (loaded on every authenticated request) ────────────────────
CREATE INDEX idx_user_roles_user_id        ON user_roles (user_id);
CREATE INDEX idx_user_roles_role_id        ON user_roles (role_id);
CREATE INDEX idx_role_permissions_role_id  ON role_permissions (role_id);
CREATE INDEX idx_role_permissions_perm_id  ON role_permissions (permission_id);
CREATE INDEX idx_user_groups_user_id       ON user_groups (user_id);
CREATE INDEX idx_user_groups_group_id      ON user_groups (group_id);

-- ── Group invitations (token lookups on accept/decline) ──────────────────────
CREATE INDEX idx_group_invitations_token   ON group_invitations (token);
CREATE INDEX idx_group_invitations_group   ON group_invitations (group_id);

-- ── Notifications (dashboard queries) ────────────────────────────────────────
CREATE INDEX idx_notifications_user_id     ON notifications (user_id);
CREATE INDEX idx_notifications_unread      ON notifications (user_id, read_at);

-- ── Audit log (admin queries by actor) ───────────────────────────────────────
CREATE INDEX idx_audit_log_actor_user_id   ON audit_log (actor_user_id);
CREATE INDEX idx_audit_log_created_at      ON audit_log (created_at);

-- ── Menus (rendered on every page via helper) ────────────────────────────────
CREATE INDEX idx_menus_location            ON menus (location);
CREATE INDEX idx_menu_items_menu_id        ON menu_items (menu_id);
CREATE INDEX idx_menu_items_parent_id      ON menu_items (parent_id);

-- ── FULLTEXT indexes for MATCH(...) AGAINST() queries in routes/web.php ──────
-- Wrap in conditional DDL: these require the MyISAM or InnoDB (>= 5.6) engine
-- with FULLTEXT support. If your column names differ, adjust accordingly.
ALTER TABLE content_items ADD FULLTEXT INDEX ft_content_search (title, body);
ALTER TABLE pages         ADD FULLTEXT INDEX ft_pages_search   (title, body);
ALTER TABLE faqs          ADD FULLTEXT INDEX ft_faqs_search    (question, answer);
