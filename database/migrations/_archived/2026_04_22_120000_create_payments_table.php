<?php
// database/migrations/2026_04_22_120000_create_payments_table.php
use Core\Database\Migration;

/**
 * `payments` — audit trail for every gateway call PaymentsService makes.
 *
 * One row per gateway operation (charge / chargeCustomer / refund /
 * createCustomer / attach / list / detach). The row records what we asked
 * for, what came back (normalized), and the full raw provider response so
 * incidents can be reconstructed without re-hitting the API.
 *
 * Card data is never stored here — only opaque provider tokens (pm_xxx /
 * card_id / payment_method_token). That matches the design principle: the
 * merchant provider holds the PCI-scoped data, we hold references.
 *
 * NOTE on retention: for high-volume apps, `response_json` can grow.
 * Consider adding a retention job (purge rows older than N days where
 * ok=1) once the table is loaded; leave failures long-term for forensics.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE payments (
                id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                gateway          VARCHAR(32)  NOT NULL,
                operation        VARCHAR(32)  NOT NULL,
                user_id          INT UNSIGNED NULL,
                gateway_id       VARCHAR(191) NOT NULL DEFAULT '',
                customer_ref     VARCHAR(191) NULL,
                source_ref       VARCHAR(191) NULL,
                amount_cents     INT UNSIGNED NULL,
                currency         VARCHAR(8)   NULL,
                ok               TINYINT(1)   NOT NULL DEFAULT 0,
                status           VARCHAR(64)  NOT NULL DEFAULT '',
                error            VARCHAR(500) NULL,
                request_json     JSON         NULL,
                response_json    JSON         NULL,
                created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                KEY idx_gateway_id (gateway, gateway_id),
                KEY idx_user (user_id, created_at),
                KEY idx_failed (ok, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS payments");
    }
};
