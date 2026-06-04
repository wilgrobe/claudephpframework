<?php
// database/migrations/2026_06_03_120000_add_scope_key_norm_unique_to_settings.php
//
// The settings table's UNIQUE(scope, scope_key, key) does NOT dedupe
// site-scope rows: scope_key is NULLable and MySQL treats every NULL as
// DISTINCT in a UNIQUE index, so duplicate (scope='site', scope_key=NULL,
// key=X) rows can slip in. When they do, a save updates one row while
// reads (SettingsService::warmBucket, ORDER-less) pick another — the
// classic "I saved it but the site shows the old value" bug.
//
// Fix without touching scope_key (so the ~30 `WHERE scope_key IS NULL`
// callers — every module's idempotent seed migration, SiteChromeService,
// etc. — keep working): a STORED generated column scope_key_norm =
// COALESCE(scope_key,'') and move the UNIQUE onto (scope, scope_key_norm,
// key). NULL rows collapse to '' for uniqueness purposes, so duplicates —
// including the null-scope_key kind — become impossible at the DB level.
//
// Idempotent. Dedupes first (keeping the lowest id per group — the row
// SettingsService::set() targets) so the UNIQUE can be added cleanly.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!$this->tableExists('settings')) return;

        // 1. Dedupe — keep the lowest id per (scope, COALESCE(scope_key,''), key).
        $this->db->query(
            "DELETE s1 FROM settings s1
               JOIN settings s2
                 ON s1.scope = s2.scope
                AND COALESCE(s1.scope_key, '') = COALESCE(s2.scope_key, '')
                AND s1.`key` = s2.`key`
                AND s1.id > s2.id"
        );

        // 2. Generated normalised column.
        if (!$this->columnExists('settings', 'scope_key_norm')) {
            $this->db->query(
                "ALTER TABLE `settings`
                   ADD COLUMN `scope_key_norm` varchar(255)
                     COLLATE utf8mb4_unicode_ci
                     GENERATED ALWAYS AS (COALESCE(`scope_key`, '')) STORED
                   AFTER `scope_key`"
            );
        }

        // 3. Drop the old NULL-porous UNIQUE.
        if ($this->indexExists('settings', 'uq_scope_key')) {
            $this->db->query("ALTER TABLE `settings` DROP INDEX `uq_scope_key`");
        }

        // 4. Add the dedupe-enforcing UNIQUE on the normalised column.
        if (!$this->indexExists('settings', 'uq_scope_key_norm')) {
            $this->db->query(
                "ALTER TABLE `settings`
                   ADD UNIQUE KEY `uq_scope_key_norm` (`scope`, `scope_key_norm`, `key`)"
            );
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('settings')) return;
        if ($this->indexExists('settings', 'uq_scope_key_norm')) {
            $this->db->query("ALTER TABLE `settings` DROP INDEX `uq_scope_key_norm`");
        }
        if ($this->columnExists('settings', 'scope_key_norm')) {
            $this->db->query("ALTER TABLE `settings` DROP COLUMN `scope_key_norm`");
        }
        if (!$this->indexExists('settings', 'uq_scope_key')) {
            $this->db->query("ALTER TABLE `settings` ADD UNIQUE KEY `uq_scope_key` (`scope`, `scope_key`, `key`)");
        }
    }

    private function tableExists(string $t): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
            [$t]
        ) > 0;
    }

    private function columnExists(string $t, string $c): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$t, $c]
        ) > 0;
    }

    private function indexExists(string $t, string $i): bool
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$t, $i]
        ) > 0;
    }
};
