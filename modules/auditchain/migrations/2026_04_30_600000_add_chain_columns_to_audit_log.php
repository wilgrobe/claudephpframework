<?php
// modules/auditchain/migrations/2026_04_30_600000_add_chain_columns_to_audit_log.php
use Core\Database\Migration;

/**
 * Adds the HMAC-chain columns to the existing audit_log table.
 *
 * Each row is anchored on the previous row of the SAME calendar day,
 * giving us per-day chains. A break or replacement on day X stops
 * propagating at midnight UTC; verification can run per-day in
 * parallel; archived days don't drag the cost up.
 *
 *   prev_hash  — hex of the prior row's row_hash, or the day's genesis
 *                hash for the first row of the day.
 *   row_hash   — HMAC-SHA256(APP_KEY, prev_hash || canonical(row)) hex.
 *
 * NULL on either column on rows older than this migration is fine
 * — verification skips rows where row_hash IS NULL (pre-immutability
 * legacy data) and only requires the chain on rows that have it.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->addColumnIfMissing('audit_log', 'prev_hash',
            "CHAR(64) NULL COMMENT 'Hex SHA-256 of prior row in same day, or the day genesis hash'");
        $this->addColumnIfMissing('audit_log', 'row_hash',
            "CHAR(64) NULL COMMENT 'Hex HMAC-SHA256(APP_KEY, prev_hash || canonicalised row)'");

        // Index lets per-day chain walks ORDER BY id WHERE DATE(created_at) = ?
        $exists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'audit_log'
               AND index_name = 'idx_chain_walk'"
        );
        if ($exists === 0) {
            $this->db->query("CREATE INDEX idx_chain_walk ON audit_log (created_at, id)");
        }
    }

    public function down(): void
    {
        // Don't drop. Removing the chain columns would erase tamper-
        // detection evidence on every existing row.
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        if ($exists > 0) return;
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};
