<?php
// database/migrations/2026_04_22_100020_seed_retry_messages_scheduled_task.php
use Core\Database\Migration;

/**
 * Register the existing `retry-messages` command as a scheduled_task.
 *
 * The retry worker (MessageRetryService) already works well and is out of
 * scope for this refactor. We just bridge it into the new scheduler so the
 * single cron hitting `php artisan schedule:run` every minute covers it
 * alongside anything new.
 *
 * Idempotent — uses INSERT IGNORE on the unique `name` column so re-running
 * this migration (or fresh-install over an already-seeded DB) does nothing.
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
            'retry-messages',
            \Core\Queue\Jobs\CallCommandJob::class,
            json_encode(['command' => 'retry-messages', 'args' => ['20']]),
            '* * * * *',  // every minute
            'default',
        ]);
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM scheduled_tasks WHERE name = ?", ['retry-messages']);
    }
};
