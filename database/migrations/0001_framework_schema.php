<?php
// database/migrations/0001_framework_schema.NEW.php
//
// Consolidated schema baseline — REGENERATED from the live working DB
// (claudephpbuilder) on 2026-06-03 via SHOW CREATE TABLE so it exactly matches the
// known-good schema (every accreted column included). Creates the base
// tables; data/seed lives in the sibling *_data migration.
//
// 38 tables.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->query('CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'NULL for OAuth-only accounts\',
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `theme_preference` enum(\'system\',\'light\',\'dark\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'system\',
  `is_active` tinyint(1) DEFAULT \'1\',
  `is_superadmin` tinyint(1) DEFAULT \'0\',
  `two_factor_enabled` tinyint(1) DEFAULT \'0\',
  `two_factor_method` enum(\'email\',\'sms\',\'totp\') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_secret` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_confirmed` tinyint(1) DEFAULT \'0\' COMMENT \'1 = TOTP secret has been verified by user\',
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci COMMENT \'JSON array of bcrypt-hashed recovery codes\',
  `totp_last_counter` bigint unsigned DEFAULT NULL COMMENT \'Last accepted TOTP counter — prevents replay attacks\',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deletion_requested_at` datetime DEFAULT NULL COMMENT \'Set when the user clicks Delete my account\',
  `deletion_grace_until` datetime DEFAULT NULL COMMENT \'30-day window during which the user can cancel the erasure\',
  `deletion_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'One-time signed cancel link sent to the user email\',
  `processing_restricted_at` datetime DEFAULT NULL COMMENT \'GDPR Art. 18 — non-essential writes blocked while non-NULL\',
  `deleted_at` datetime DEFAULT NULL COMMENT \'Set by PurgeUserJob after the registry has run\',
  `date_of_birth` date DEFAULT NULL COMMENT \'Optional birthdate; populated when COPPA / age-gate signup is on\',
  `two_factor_failed_attempts` smallint unsigned NOT NULL DEFAULT \'0\' COMMENT \'Rolling counter of consecutive failed 2FA attempts\',
  `two_factor_locked_until` datetime DEFAULT NULL COMMENT \'When set, 2FA verification refuses until this passes\',
  `stripe_customer_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Phase 10 — Stripe Customer id (cus_…) for user-level wallet\',
  `stripe_default_payment_method_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'pm_… used for off-session auto-recharge\',
  `stripe_default_card_brand` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_default_card_last4` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_default_card_exp_month` tinyint unsigned DEFAULT NULL,
  `stripe_default_card_exp_year` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `stripe_customer_id` (`stripe_customer_id`),
  KEY `idx_email` (`email`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_deletion_grace_until` (`deletion_grace_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) DEFAULT \'0\' COMMENT \'System roles cannot be deleted\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_role_permissions_perm_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role_id` (`role_id`),
  CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_customer_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT \'0\' COMMENT \'1=joinable without invite\',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `stripe_customer_id` (`stripe_customer_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_slug` (`slug`),
  CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `group_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `base_role` enum(\'group_owner\',\'group_admin\',\'manager\',\'editor\',\'member\') COLLATE utf8mb4_unicode_ci DEFAULT \'member\' COMMENT \'The built-in role this custom role inherits from\',
  `is_system` tinyint(1) DEFAULT \'0\' COMMENT \'Built-in group roles cannot be deleted\',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_role_slug` (`group_id`,`slug`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `group_roles_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_roles_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `group_role_permissions` (
  `group_role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  `granted` tinyint(1) DEFAULT \'1\',
  PRIMARY KEY (`group_role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `group_role_permissions_ibfk_1` FOREIGN KEY (`group_role_id`) REFERENCES `group_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `user_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `group_id` int unsigned NOT NULL,
  `group_role_id` int unsigned NOT NULL COMMENT \'Their role within this group\',
  `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `invited_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_group` (`user_id`,`group_id`),
  KEY `idx_user_groups_group_id` (`group_id`),
  KEY `group_role_id` (`group_role_id`),
  KEY `invited_by` (`invited_by`),
  CONSTRAINT `user_groups_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_groups_ibfk_3` FOREIGN KEY (`group_role_id`) REFERENCES `group_roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `user_groups_ibfk_4` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `group_invitations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `invited_by` int unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'For email-based invites\',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'For SMS invites\',
  `user_id` int unsigned DEFAULT NULL COMMENT \'Set when existing user is invited\',
  `token` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_role_id` int unsigned DEFAULT NULL COMMENT \'Role to assign on accept\',
  `status` enum(\'pending\',\'accepted\',\'expired\',\'cancelled\') COLLATE utf8mb4_unicode_ci DEFAULT \'pending\',
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `invited_by` (`invited_by`),
  KEY `user_id` (`user_id`),
  KEY `group_role_id` (`group_role_id`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_group_invitations_group` (`group_id`),
  CONSTRAINT `group_invitations_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_invitations_ibfk_2` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_invitations_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `group_invitations_ibfk_4` FOREIGN KEY (`group_role_id`) REFERENCES `group_roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `group_owner_removal_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `requested_by` int unsigned NOT NULL COMMENT \'Owner who initiated the removal\',
  `target_user_id` int unsigned NOT NULL COMMENT \'Owner being removed\',
  `new_role_id` int unsigned DEFAULT NULL COMMENT \'Role to switch to on approval; NULL = full removal\',
  `status` enum(\'pending\',\'approved\',\'rejected\') COLLATE utf8mb4_unicode_ci DEFAULT \'pending\',
  `notified_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `requested_by` (`requested_by`),
  KEY `target_user_id` (`target_user_id`),
  CONSTRAINT `group_owner_removal_requests_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_owner_removal_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_owner_removal_requests_ibfk_3` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci,
  `last_activity` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity` (`last_activity`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'SHA-256 of the plain random token\',
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `two_factor_challenges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'bcrypt hash of the 6-digit code\',
  `method` enum(\'email\',\'sms\',\'totp\') COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned DEFAULT \'0\',
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_method` (`user_id`,`method`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_uid_exp` (`user_id`,`expires_at`),
  CONSTRAINT `two_factor_challenges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `attempt_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'sha256 of type:ip or type:email\',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_until` timestamp NULL DEFAULT NULL COMMENT \'Set when hard lockout triggered\',
  PRIMARY KEY (`id`),
  KEY `idx_key_time` (`attempt_key`,`attempted_at`),
  KEY `idx_locked` (`attempt_key`,`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `user_oauth` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `provider` enum(\'google\',\'microsoft\',\'apple\',\'facebook\',\'linkedin\') COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` text COLLATE utf8mb4_unicode_ci,
  `refresh_token` text COLLATE utf8mb4_unicode_ci,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_id` (`provider`,`provider_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_oauth_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` enum(\'site\',\'page\',\'function\',\'group\') COLLATE utf8mb4_unicode_ci DEFAULT \'site\',
  `scope_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'page slug, function name, group id, etc.\',
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext COLLATE utf8mb4_unicode_ci,
  `type` enum(\'string\',\'integer\',\'boolean\',\'json\',\'text\') COLLATE utf8mb4_unicode_ci DEFAULT \'string\',
  `is_public` tinyint(1) DEFAULT \'0\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope_key` (`scope`,`scope_key`,`key`),
  KEY `idx_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `menus` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `handle` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'header, footer, sidebar, etc.\',
  `description` text COLLATE utf8mb4_unicode_ci,
  `format_settings` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `uniq_menu_handle` (`handle`),
  KEY `idx_menus_location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `menu_id` int unsigned NOT NULL,
  `parent_id` int unsigned DEFAULT NULL COMMENT \'NULL = top level\',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'NULL for unlinked submenu parents\',
  `kind` enum(\'link\',\'holder\') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'link\',
  `icon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT \'_self\',
  `sort_order` int DEFAULT \'0\',
  `visibility` enum(\'always\',\'logged_in\',\'logged_out\',\'role\',\'permission\',\'group\') COLLATE utf8mb4_unicode_ci DEFAULT \'always\',
  `condition_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'role slug, permission slug, or group slug\',
  `show_on_pages` text COLLATE utf8mb4_unicode_ci COMMENT \'JSON array of page slugs; NULL=all pages\',
  `is_active` tinyint(1) DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_parent` (`menu_id`,`parent_id`),
  KEY `idx_menu_items_parent_id` (`parent_id`),
  KEY `idx_sort` (`sort_order`),
  CONSTRAINT `menu_items_ibfk_1` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_items_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci,
  `background_image_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rendered_html` longtext COLLATE utf8mb4_unicode_ci COMMENT \'cached output of section layout; NULL = no layout, render body instead\',
  `layout` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT \'default\',
  `status` enum(\'draft\',\'published\') COLLATE utf8mb4_unicode_ci DEFAULT \'draft\',
  `origin` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT \'1\' COMMENT \'1=visible to guests\',
  `featured` tinyint(1) NOT NULL DEFAULT \'0\',
  `hide_title` tinyint(1) NOT NULL DEFAULT \'0\',
  `main_margin_top_px` tinyint unsigned NOT NULL DEFAULT \'0\',
  `main_margin_bottom_px` tinyint unsigned NOT NULL DEFAULT \'0\',
  `main_padding_x_px` smallint unsigned NOT NULL DEFAULT \'0\',
  `main_padding_y_px` tinyint unsigned NOT NULL DEFAULT \'0\',
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_image_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'OpenGraph social-share image for this page\',
  `seo_keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT \'0\',
  `created_by` int unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `font_url_body` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page override; NULL = inherit site-level branding\',
  `font_family_body` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page CSS font-family stack for body text\',
  `font_url_heading` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page override; NULL = inherit site-level branding\',
  `font_family_heading` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page CSS font-family stack for h1/h2/h3\',
  `font_url_mono` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page override; NULL = inherit site-level branding\',
  `font_family_mono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'per-page CSS font-family stack for code/pre/kbd\',
  `color_overrides` json DEFAULT NULL,
  `custom_css` text COLLATE utf8mb4_unicode_ci,
  `style_overrides` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `created_by` (`created_by`),
  KEY `idx_slug` (`slug`(191)),
  KEY `idx_status` (`status`),
  KEY `idx_featured` (`featured`),
  FULLTEXT KEY `ft_pages_search` (`title`,`body`),
  CONSTRAINT `pages_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `page_sections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT \'0\' COMMENT \'vertical position within the page (low = top)\',
  `slot` enum(\'top\',\'main\',\'bottom\') NOT NULL DEFAULT \'main\' COMMENT \'Phase 43.47 — main = own-page composer; top/bottom = injection slot around a dynamic module-rendered page\',
  `settings` json DEFAULT NULL COMMENT \'col_count, gap_px, padding_y_px, background (color|image|none), background_value\',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_page_sections_page_order` (`page_id`,`sort_order`),
  CONSTRAINT `page_sections_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `section_blocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT \'0\' COMMENT \'horizontal position within the section (low = left)\',
  `block_key` varchar(120) NOT NULL COMMENT \'matches BlockDescriptor::$key (e.g. siteblocks.hero)\',
  `settings` json DEFAULT NULL COMMENT \'per-placement settings; shape per the BlockDescriptor settingsSchema\',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_section_blocks_section_order` (`section_id`,`sort_order`),
  KEY `idx_section_blocks_block_key` (`block_key`),
  CONSTRAINT `section_blocks_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `page_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `faq_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int DEFAULT \'0\',
  `is_public` tinyint(1) DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `faqs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `question` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int DEFAULT \'0\',
  `is_public` tinyint(1) DEFAULT \'1\',
  `is_active` tinyint(1) DEFAULT \'1\',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  FULLTEXT KEY `ft_faqs_search` (`question`,`answer`),
  CONSTRAINT `faqs_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `faq_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faqs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `content_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci,
  `type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT \'post\',
  `status` enum(\'draft\',\'published\',\'archived\') COLLATE utf8mb4_unicode_ci DEFAULT \'draft\',
  `owner_type` enum(\'user\',\'group\') COLLATE utf8mb4_unicode_ci DEFAULT \'user\',
  `owner_user_id` int unsigned DEFAULT NULL,
  `owner_group_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `owner_user_id` (`owner_user_id`),
  KEY `owner_group_id` (`owner_group_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_slug` (`slug`(191)),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  FULLTEXT KEY `ft_content_search` (`title`,`body`),
  CONSTRAINT `content_items_ibfk_1` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `content_items_ibfk_2` FOREIGN KEY (`owner_group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `content_items_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `content_transfer_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `content_id` int unsigned NOT NULL,
  `requested_by` int unsigned NOT NULL,
  `from_type` enum(\'user\',\'group\') COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_type` enum(\'user\',\'group\') COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_group_id` int unsigned DEFAULT NULL,
  `to_user_id` int unsigned DEFAULT NULL,
  `status` enum(\'pending\',\'approved\',\'rejected\') COLLATE utf8mb4_unicode_ci DEFAULT \'pending\',
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `content_id` (`content_id`),
  KEY `requested_by` (`requested_by`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `content_transfer_requests_ibfk_1` FOREIGN KEY (`content_id`) REFERENCES `content_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `content_transfer_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `content_transfer_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'UUID\',
  `user_id` int unsigned NOT NULL,
  `type` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `channel` set(\'in_app\',\'email\',\'sms\') COLLATE utf8mb4_unicode_ci DEFAULT \'in_app\',
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int unsigned DEFAULT NULL COMMENT \'Who performed the action\',
  `emulated_user_id` int unsigned DEFAULT NULL COMMENT \'Non-null when superadmin is emulating\',
  `superadmin_mode` tinyint(1) DEFAULT \'0\',
  `action` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_id` int unsigned DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `prev_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Hex SHA-256 of prior row in same day, or the day genesis hash\',
  `row_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Hex HMAC-SHA256(APP_KEY, prev_hash || canonicalised row)\',
  PRIMARY KEY (`id`),
  KEY `emulated_user_id` (`emulated_user_id`),
  KEY `idx_actor` (`actor_user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_model` (`model`,`model_id`),
  KEY `idx_audit_log_created_at` (`created_at`),
  KEY `idx_chain_walk` (`created_at`,`id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`emulated_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `message_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `channel` enum(\'email\',\'sms\',\'webhook\') COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `status` enum(\'queued\',\'sent\',\'failed\') COLLATE utf8mb4_unicode_ci DEFAULT \'queued\',
  `provider` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `attempts` tinyint unsigned NOT NULL DEFAULT \'0\',
  `max_attempts` tinyint unsigned NOT NULL DEFAULT \'3\',
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `last_attempted_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_channel_status` (`channel`,`status`),
  KEY `idx_recipient` (`recipient`),
  KEY `idx_retry_queue` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `seo_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'The URL path this slug maps to\',
  `slug` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Permanent slug/vanity URL\',
  `target_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'content, page, group, etc.\',
  `target_id` int unsigned DEFAULT NULL,
  `redirect_to` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'If set, issues a 301 redirect\',
  `is_active` tinyint(1) DEFAULT \'1\',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(64) NOT NULL DEFAULT \'default\',
  `class` varchar(191) NOT NULL,
  `payload` json NOT NULL,
  `status` enum(\'pending\',\'running\',\'completed\',\'failed\') NOT NULL DEFAULT \'pending\',
  `attempts` int unsigned NOT NULL DEFAULT \'0\',
  `max_attempts` int unsigned NOT NULL DEFAULT \'3\',
  `available_at` datetime NOT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `reserved_by` varchar(64) DEFAULT NULL,
  `last_error` text,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ready` (`status`,`queue`,`available_at`),
  KEY `idx_reserved_by` (`reserved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `scheduled_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `class` varchar(191) NOT NULL,
  `payload` json NOT NULL,
  `schedule_expression` varchar(120) NOT NULL,
  `queue` varchar(64) NOT NULL DEFAULT \'default\',
  `enabled` tinyint(1) NOT NULL DEFAULT \'1\',
  `next_run_at` datetime DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `last_run_status` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`),
  KEY `idx_due` (`enabled`,`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `gateway` varchar(32) NOT NULL,
  `operation` varchar(32) NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `gateway_id` varchar(191) NOT NULL DEFAULT \'\',
  `customer_ref` varchar(191) DEFAULT NULL,
  `source_ref` varchar(191) DEFAULT NULL,
  `amount_cents` int unsigned DEFAULT NULL,
  `currency` varchar(8) DEFAULT NULL,
  `ok` tinyint(1) NOT NULL DEFAULT \'0\',
  `status` varchar(64) NOT NULL DEFAULT \'\',
  `error` varchar(500) DEFAULT NULL,
  `request_json` json DEFAULT NULL,
  `response_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gateway_id` (`gateway`,`gateway_id`),
  KEY `idx_user` (`user_id`,`created_at`),
  KEY `idx_failed` (`ok`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `module_status` (
  `module_name` varchar(64) NOT NULL,
  `state` enum(\'active\',\'disabled_dependency\',\'disabled_admin\',\'disabled_unlicensed\') NOT NULL DEFAULT \'active\',
  `missing_deps` json DEFAULT NULL,
  `notice` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`module_name`),
  KEY `idx_module_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `system_layouts` (
  `name` varchar(64) NOT NULL,
  `friendly_name` varchar(255) DEFAULT NULL,
  `module` varchar(64) DEFAULT NULL,
  `category` varchar(64) DEFAULT NULL,
  `description` text,
  `chromed_url` varchar(255) DEFAULT NULL,
  `rows` tinyint unsigned NOT NULL DEFAULT \'1\',
  `cols` tinyint unsigned NOT NULL DEFAULT \'1\',
  `col_widths` json NOT NULL,
  `row_heights` json NOT NULL,
  `gap_pct` tinyint unsigned NOT NULL DEFAULT \'3\',
  `max_width_px` smallint unsigned NOT NULL DEFAULT \'1280\',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`),
  KEY `idx_system_layouts_module_category` (`module`,`category`),
  CONSTRAINT `chk_system_layouts_cols` CHECK ((`cols` between 1 and 4)),
  CONSTRAINT `chk_system_layouts_gap` CHECK ((`gap_pct` between 0 and 20)),
  CONSTRAINT `chk_system_layouts_rows` CHECK ((`rows` between 1 and 6)),
  CONSTRAINT `chk_system_layouts_width` CHECK ((`max_width_px` between 320 and 4096))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `system_block_placements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `system_name` varchar(64) NOT NULL,
  `row_index` tinyint unsigned NOT NULL,
  `col_index` tinyint unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT \'0\',
  `block_key` varchar(128) NOT NULL,
  `placement_type` enum(\'block\',\'content_slot\') NOT NULL DEFAULT \'block\',
  `slot_name` varchar(64) DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `visible_to` enum(\'any\',\'auth\',\'guest\') NOT NULL DEFAULT \'any\',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sysblock_cell` (`system_name`,`row_index`,`col_index`,`sort_order`),
  KEY `idx_sysblock_key` (`block_key`),
  CONSTRAINT `fk_system_block_placements_layout` FOREIGN KEY (`system_name`) REFERENCES `system_layouts` (`name`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $this->db->query('CREATE TABLE IF NOT EXISTS `health_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `pinned_by_user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->query('DROP TABLE IF EXISTS `health_notes`');
            $this->db->query('DROP TABLE IF EXISTS `system_block_placements`');
            $this->db->query('DROP TABLE IF EXISTS `system_layouts`');
            $this->db->query('DROP TABLE IF EXISTS `module_status`');
            $this->db->query('DROP TABLE IF EXISTS `payments`');
            $this->db->query('DROP TABLE IF EXISTS `scheduled_tasks`');
            $this->db->query('DROP TABLE IF EXISTS `jobs`');
            $this->db->query('DROP TABLE IF EXISTS `seo_links`');
            $this->db->query('DROP TABLE IF EXISTS `message_log`');
            $this->db->query('DROP TABLE IF EXISTS `audit_log`');
            $this->db->query('DROP TABLE IF EXISTS `notifications`');
            $this->db->query('DROP TABLE IF EXISTS `content_transfer_requests`');
            $this->db->query('DROP TABLE IF EXISTS `content_items`');
            $this->db->query('DROP TABLE IF EXISTS `faqs`');
            $this->db->query('DROP TABLE IF EXISTS `faq_categories`');
            $this->db->query('DROP TABLE IF EXISTS `section_blocks`');
            $this->db->query('DROP TABLE IF EXISTS `page_sections`');
            $this->db->query('DROP TABLE IF EXISTS `pages`');
            $this->db->query('DROP TABLE IF EXISTS `menu_items`');
            $this->db->query('DROP TABLE IF EXISTS `menus`');
            $this->db->query('DROP TABLE IF EXISTS `settings`');
            $this->db->query('DROP TABLE IF EXISTS `user_oauth`');
            $this->db->query('DROP TABLE IF EXISTS `login_attempts`');
            $this->db->query('DROP TABLE IF EXISTS `two_factor_challenges`');
            $this->db->query('DROP TABLE IF EXISTS `email_verifications`');
            $this->db->query('DROP TABLE IF EXISTS `password_resets`');
            $this->db->query('DROP TABLE IF EXISTS `sessions`');
            $this->db->query('DROP TABLE IF EXISTS `group_owner_removal_requests`');
            $this->db->query('DROP TABLE IF EXISTS `group_invitations`');
            $this->db->query('DROP TABLE IF EXISTS `user_groups`');
            $this->db->query('DROP TABLE IF EXISTS `group_role_permissions`');
            $this->db->query('DROP TABLE IF EXISTS `group_roles`');
            $this->db->query('DROP TABLE IF EXISTS `groups`');
            $this->db->query('DROP TABLE IF EXISTS `user_roles`');
            $this->db->query('DROP TABLE IF EXISTS `role_permissions`');
            $this->db->query('DROP TABLE IF EXISTS `permissions`');
            $this->db->query('DROP TABLE IF EXISTS `roles`');
            $this->db->query('DROP TABLE IF EXISTS `users`');
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
};
