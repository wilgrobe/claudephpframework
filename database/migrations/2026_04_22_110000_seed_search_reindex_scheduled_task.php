<?php
// database/migrations/2026_04_22_110000_seed_search_reindex_scheduled_task.php
use Core\Database\Migration;

/**
 * Nightly full reindex as a safety net against drift between the local
 * tables and the hosted search index.
 *
 * Incremental indexing via SearchIndexer covers the normal save path; this
 * scheduled_task catches cases where a job was dropped (worker crashed
 * mid-drain, hosted provider was down long enough to exhaust retries, or
 * a record was written by a tool that bypassed the controller hooks — e.g.
 * a migration or a raw SQL edit).
 *
 * Runs at 03:00 daily to avoid overlap with normal traffic. Idempotent —
 * INSERT IGNORE on the unique `name` column.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            INSERT IGNORE INTO scheduled_tasks
                (name, class, payload, schedule_expression, queue, enabled, next_run_at)
            VALUES
                (?, ?, ?, ?, ?, 1, NOW())
        ", [
            'search-reindex-nightly',
            \Core\Queue\Jobs\CallCommandJob::class,
            json_encode(['command' => 'search:reindex', 'args' => ['all']]),
            '0 3 * * *',  // 03:00 daily
            'default',
        ]);
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM scheduled_tasks WHERE name = ?", ['search-reindex-nightly']);
    }
};
