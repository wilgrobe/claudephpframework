<?php
// database/migrations/2026_04_22_100000_create_jobs_table.php
use Core\Database\Migration;

/**
 * `jobs` — the work queue for Core\Queue\DatabaseQueue.
 *
 * One row per dispatched job. Rows are picked up by `php artisan queue:work`
 * (or the drain phase of `php artisan schedule:run`) via an atomic reservation
 * — `UPDATE ... SET reserved_by = ? WHERE reserved_by IS NULL AND id = ?` —
 * so parallel workers can't double-claim a row.
 *
 * Lifecycle:
 *   pending  → running  → completed   (happy path)
 *                     ↘  failed      (attempts exhausted; manual retry possible)
 *                     ↘  pending     (transient failure; backoff via available_at)
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE jobs (
                id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                queue            VARCHAR(64)  NOT NULL DEFAULT 'default',
                class            VARCHAR(191) NOT NULL,
                payload          JSON         NOT NULL,
                status           ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
                attempts         INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts     INT UNSIGNED NOT NULL DEFAULT 3,
                available_at     DATETIME     NOT NULL,
                reserved_at      DATETIME     NULL,
                reserved_by      VARCHAR(64)  NULL,
                last_error       TEXT         NULL,
                completed_at     DATETIME     NULL,
                created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                -- The dequeue predicate: status+queue+available_at. Composite
                -- index matches the WHERE so the worker doesn't scan the table
                -- as the queue grows.
                KEY idx_ready (status, queue, available_at),
                KEY idx_reserved_by (reserved_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS jobs");
    }
};
