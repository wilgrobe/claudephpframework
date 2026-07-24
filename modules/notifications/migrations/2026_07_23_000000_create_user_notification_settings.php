<?php
use Core\Database\Migration;

/**
 * Per-user notification delivery preference — a simple, generic setting the
 * framework stores and any module can honor:
 *   delivery_mode     each | digest | off   (individual notifications, one daily
 *                                            digest, or none)
 *   delivery_channel  both | email | sms | none   (none = on-site / in-app only)
 *
 * The framework only STORES it; honoring it is per-module (MarketOtter's plan
 * reminders read it). Defaults preserve today's behavior (each / both).
 */
return new class extends Migration {
    public function up(): void {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS user_notification_settings (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                delivery_mode VARCHAR(12) NOT NULL DEFAULT 'each',
                delivery_channel VARCHAR(8) NOT NULL DEFAULT 'both',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void {
        $this->db->query("DROP TABLE IF EXISTS user_notification_settings");
    }
};
