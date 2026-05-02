<?php
// database/migrations/2026_04_22_100010_create_scheduled_tasks_table.php
use Core\Database\Migration;

/**
 * `scheduled_tasks` — declarative recurring work.
 *
 * Each row is a rule: "run job class X with payload Y on schedule Z".
 * Rules live here forever; the *jobs* they produce go through the `jobs`
 * table and get cleaned up as they complete.
 *
 * On each `php artisan schedule:run` tick, the scheduler:
 *   1. Selects rows where enabled=1 AND next_run_at <= NOW().
 *   2. Enqueues a `jobs` row for each.
 *   3. Recomputes next_run_at from schedule_expression and writes it back.
 *
 * schedule_expression uses standard 5-field cron syntax
 * (parsed by dragonmantank/cron-expression). Examples:
 *   "* * * * *"    — every minute
 *   "*\/5 * * * *" — every 5 minutes
 *   "0 3 * * 1-5"  — 3am weekdays
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE scheduled_tasks (
                id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name                 VARCHAR(120) NOT NULL,
                class                VARCHAR(191) NOT NULL,
                payload              JSON         NOT NULL,
                schedule_expression  VARCHAR(120) NOT NULL,
                queue                VARCHAR(64)  NOT NULL DEFAULT 'default',
                enabled              TINYINT(1)   NOT NULL DEFAULT 1,
                next_run_at          DATETIME     NULL,
                last_run_at          DATETIME     NULL,
                last_run_status      VARCHAR(32)  NULL,
                created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_name (name),
                KEY idx_due (enabled, next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS scheduled_tasks");
    }
};
