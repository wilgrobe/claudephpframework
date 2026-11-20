<?php
use Core\Database\Migration;

/**
 * seo:indexnow, daily at 07:15 UTC: tells Bing and the other IndexNow engines about pages that are new or changed
 * since the last run. No table: what was sent lives in the setting seo.indexnow.sent. The command itself sends
 * nothing from a local/test host, a hidden site, the Builder apex, or a site that turned IndexNow off.
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->db->fetchColumn("SHOW TABLES LIKE 'scheduled_tasks'") && !$this->db->fetchColumn('SELECT 1 FROM scheduled_tasks WHERE name = ?', ['seo:indexnow'])) {
            $this->db->query(
                "INSERT INTO scheduled_tasks (name, class, payload, schedule_expression, queue, enabled, created_at)
                 VALUES (?, 'Core\\\\Queue\\\\Jobs\\\\CallCommandJob', ?, ?, 'default', 1, NOW())",
                ['seo:indexnow', json_encode(['command' => 'seo:indexnow', 'args' => []]), '15 7 * * *']
            );
        }
    }

    public function down(): void
    {
        if ($this->db->fetchColumn("SHOW TABLES LIKE 'scheduled_tasks'")) { $this->db->query("DELETE FROM scheduled_tasks WHERE name = 'seo:indexnow'"); }
    }
};
