<?php
// modules/tooltip/migrations/2026_05_30_500000_create_tooltip_tables.php
use Core\Database\Migration;

/**
 * Schema for the tooltip module — managed, reusable help bubbles.
 *
 *   tooltips            — the content store. `content_html` holds the RAW
 *                         source (plain / markdown / html per content_format);
 *                         it is rendered + sanitised at read time so a format
 *                         change re-renders without a migration. `trigger` is a
 *                         MySQL reserved word — always backtick it in raw SQL.
 *   tooltip_categories  — organisation.
 *   tooltip_overrides   — per-page / per-route / per-role content variants
 *                         (the `per-page-overrides` submodule).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS tooltip_categories (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(200) NOT NULL,
                slug        VARCHAR(120) NOT NULL,
                description VARCHAR(500) NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_cat_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS tooltips (
                id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug               VARCHAR(80) NOT NULL,
                label              VARCHAR(200) NOT NULL,
                content_html       TEXT NULL,
                content_format     ENUM('plain','markdown','html') NOT NULL DEFAULT 'markdown',
                category_id        INT UNSIGNED NULL,
                placement          ENUM('top','right','bottom','left','auto') NOT NULL DEFAULT 'auto',
                theme              ENUM('default','dark','light','accent','warning','info') NOT NULL DEFAULT 'default',
                `trigger`          ENUM('hover','click','focus','manual') NOT NULL DEFAULT 'hover',
                max_width_px       SMALLINT UNSIGNED NOT NULL DEFAULT 280,
                show_delay_ms      SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                hide_delay_ms      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                is_active          TINYINT(1) NOT NULL DEFAULT 1,
                view_count         INT UNSIGNED NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NULL,
                updated_by_user_id INT UNSIGNED NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_slug (slug),
                KEY idx_category (category_id),
                KEY idx_active (is_active),
                CONSTRAINT fk_tt_category FOREIGN KEY (category_id) REFERENCES tooltip_categories (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS tooltip_overrides (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tooltip_id   BIGINT UNSIGNED NOT NULL,
                scope        ENUM('page','route','user_role') NOT NULL,
                scope_value  VARCHAR(200) NOT NULL,
                content_html TEXT NULL,
                is_active    TINYINT(1) NOT NULL DEFAULT 1,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_tooltip_scope (tooltip_id, scope, is_active),
                CONSTRAINT fk_tov_tooltip FOREIGN KEY (tooltip_id) REFERENCES tooltips (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        foreach (['tooltip_overrides', 'tooltips', 'tooltip_categories'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS $t");
        }
    }
};
