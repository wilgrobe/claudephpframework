<?php
// database/migrations/2026_06_04_000000_add_user_created_index_to_notifications.php
//
// Perf (scan 2026-06-04, M2) — the notification list page runs
//   SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50
// (NotificationService::getAll). The existing idx_user_read (user_id, read_at)
// covers the WHERE but NOT the ORDER BY, so MySQL filesorts the user's entire
// notification history before applying LIMIT — and that history grows unbounded
// (no per-user cap). Add a (user_id, created_at) index so the query walks the
// index backward and stops at LIMIT N with no filesort. The unread bell-badge
// path (getUnread) keeps using idx_user_read. Idempotent + table-guarded so it's
// safe on central, every tenant DB, and fresh installs.
return new class extends \Core\Database\Migration {
    public function up(): void
    {
        $tbl = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'notifications' LIMIT 1",
            []
        );
        if (!$tbl) return;

        $exists = $this->db->fetchOne(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'notifications'
               AND index_name = 'idx_user_created' LIMIT 1",
            []
        );
        if ($exists) return;

        $this->db->query("ALTER TABLE notifications ADD KEY idx_user_created (user_id, created_at)");
    }

    public function down(): void
    {
        try {
            $this->db->query("ALTER TABLE notifications DROP INDEX idx_user_created");
        } catch (\Throwable) { /* ignore */ }
    }
};
