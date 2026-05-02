<?php
// modules/pages/migrations/2026_04_25_320000_create_page_layouts_tables.php
use Core\Database\Migration;

/**
 * Page composer schema (Batch 2 of the content-blocks rollout).
 *
 *   page_layouts             — one row per page that uses a custom layout.
 *                              Pages without a layout fall back to rendering
 *                              `pages.body` as today (back-compat).
 *   page_block_placements    — block instances dropped into a layout cell.
 *
 * Layouts and placements both cascade-delete with the parent page so we
 * don't have orphan rows when a page is removed.
 *
 * Range constraints (rows 1..6, cols 1..4) match the spec Will set: full
 * pages can be up to 4 wide × 6 tall. Width / height JSON arrays are
 * percentages whose sum must approximately equal (100 - gap_total) on
 * the consumer side; the schema only enforces the array structure, not
 * the percentage math (controllers validate).
 */
return new class extends Migration {
    public function up(): void
    {
        // NOTE on backticks: `rows` is a reserved word in MySQL 8+
        // (used in window functions / OVER clauses). Backtick it
        // everywhere it appears as an identifier — including the
        // column definition, not just the CHECK constraint. Same
        // gotcha that bit the polls module's `rank` column.
        $this->db->query("
            CREATE TABLE IF NOT EXISTS page_layouts (
                page_id        INT UNSIGNED NOT NULL PRIMARY KEY,
                `rows`         TINYINT UNSIGNED NOT NULL DEFAULT 2,
                cols           TINYINT UNSIGNED NOT NULL DEFAULT 2,
                col_widths     JSON NOT NULL,
                row_heights    JSON NOT NULL,
                gap_pct        TINYINT UNSIGNED NOT NULL DEFAULT 3,
                max_width_px   SMALLINT UNSIGNED NOT NULL DEFAULT 1280,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_page_layouts_page
                    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
                CONSTRAINT chk_page_layouts_rows  CHECK (`rows` BETWEEN 1 AND 6),
                CONSTRAINT chk_page_layouts_cols  CHECK (cols BETWEEN 1 AND 4),
                CONSTRAINT chk_page_layouts_gap   CHECK (gap_pct BETWEEN 0 AND 20),
                CONSTRAINT chk_page_layouts_width CHECK (max_width_px BETWEEN 320 AND 4096)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS page_block_placements (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                page_id     INT UNSIGNED NOT NULL,
                row_index   TINYINT UNSIGNED NOT NULL,
                col_index   TINYINT UNSIGNED NOT NULL,
                sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                block_key   VARCHAR(128) NOT NULL,
                settings    JSON NULL,
                visible_to  ENUM('any','auth','guest') NOT NULL DEFAULT 'any',
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_page_block_placements_page
                    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
                INDEX idx_page_block_placements_page_cell (page_id, row_index, col_index, sort_order),
                INDEX idx_page_block_placements_key (block_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS page_block_placements");
        $this->db->query("DROP TABLE IF EXISTS page_layouts");
    }
};
