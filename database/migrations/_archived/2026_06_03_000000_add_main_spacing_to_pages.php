<?php
// database/migrations/2026_06_03_000000_add_main_spacing_to_pages.php
//
// Composer-redesign follow-up — per-page padding + margin control on
// the page main wrapper. Mirrors the section margin shape (margin_top_px
// / margin_bottom_px) plus horizontal + vertical padding. Default 0
// preserves existing page renders.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $cols = [
            'main_margin_top_px'    => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'main_margin_bottom_px' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'main_padding_x_px'     => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'main_padding_y_px'     => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($cols as $name => $type) {
            if (!$this->columnExists('pages', $name)) {
                $this->db->query("ALTER TABLE pages ADD COLUMN $name $type AFTER hide_title");
            }
        }
    }

    public function down(): void
    {
        foreach (['main_margin_top_px','main_margin_bottom_px','main_padding_x_px','main_padding_y_px'] as $col) {
            if ($this->columnExists('pages', $col)) {
                $this->db->query("ALTER TABLE pages DROP COLUMN $col");
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->db->fetchColumn("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ", [$table, $column]);
    }
};
