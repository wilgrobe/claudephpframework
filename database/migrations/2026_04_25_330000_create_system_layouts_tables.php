<?php
// database/migrations/2026_04_25_330000_create_system_layouts_tables.php
use Core\Database\Migration;

/**
 * System-layout tables — page composer support for surfaces that aren't
 * rows in the `pages` table (dashboard, future admin-home, /admin/modules,
 * etc). Same shape as `page_layouts` / `page_block_placements`, keyed by
 * a string `name` instead of a page_id FK.
 *
 * The composer view (app/Views/partials/page_composer.php) doesn't care
 * which storage shape a layout came from — it just consumes the
 * `['layout' => ..., 'placements' => ...]` envelope. So we get a single
 * renderer driving both content pages AND system surfaces.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS system_layouts (
                name           VARCHAR(64) NOT NULL PRIMARY KEY,
                `rows`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
                cols           TINYINT UNSIGNED NOT NULL DEFAULT 1,
                col_widths     JSON NOT NULL,
                row_heights    JSON NOT NULL,
                gap_pct        TINYINT UNSIGNED NOT NULL DEFAULT 3,
                max_width_px   SMALLINT UNSIGNED NOT NULL DEFAULT 1280,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_system_layouts_rows  CHECK (`rows` BETWEEN 1 AND 6),
                CONSTRAINT chk_system_layouts_cols  CHECK (cols BETWEEN 1 AND 4),
                CONSTRAINT chk_system_layouts_gap   CHECK (gap_pct BETWEEN 0 AND 20),
                CONSTRAINT chk_system_layouts_width CHECK (max_width_px BETWEEN 320 AND 4096)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS system_block_placements (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                system_name   VARCHAR(64) NOT NULL,
                row_index     TINYINT UNSIGNED NOT NULL,
                col_index     TINYINT UNSIGNED NOT NULL,
                sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                block_key     VARCHAR(128) NOT NULL,
                settings      JSON NULL,
                visible_to    ENUM('any','auth','guest') NOT NULL DEFAULT 'any',
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_system_block_placements_layout
                    FOREIGN KEY (system_name) REFERENCES system_layouts(name) ON DELETE CASCADE,
                INDEX idx_sysblock_cell (system_name, row_index, col_index, sort_order),
                INDEX idx_sysblock_key (block_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS system_block_placements");
        $this->db->query("DROP TABLE IF EXISTS system_layouts");
    }
};
