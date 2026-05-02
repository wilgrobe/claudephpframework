<?php
// modules/notifications/migrations/2026_05_01_920000_create_notification_preferences.php
use Core\Database\Migration;

/**
 * Per-user notification preferences. One row per (user, type, channel)
 * tuple; absence means "default" (which the service treats as enabled).
 *
 *   type     — registry key like 'social.followed', 'comment.reply',
 *              'messages.new', 'group.owner_removal_request'.
 *   channel  — 'in_app' | 'email' (matches NotificationService::send's
 *              channels CSV but split per-row so the prefs page can
 *              render a grid of independent toggles).
 *   enabled  — 1 = receive this type via this channel, 0 = suppress.
 *
 * Service-side gate (NotificationService::isAllowed) consults this
 * table on every send. Default-on means new notification types light
 * up for existing users without having to backfill rows.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE notification_preferences (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                type       VARCHAR(64)  NOT NULL,
                channel    VARCHAR(16)  NOT NULL,
                enabled    TINYINT(1)   NOT NULL DEFAULT 1,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_type_channel (user_id, type, channel),
                KEY idx_user (user_id),
                CONSTRAINT fk_npref_user
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS notification_preferences");
    }
};
