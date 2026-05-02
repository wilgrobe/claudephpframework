<?php
// core/Services/NotificationService.php
namespace Core\Services;

use Core\Database\Database;

class NotificationService
{
    /**
     * Catalog of known notification types — drives the per-channel
     * preferences UI at /profile/notifications and provides a friendly
     * label + group for each type. Adding a new type to this list makes
     * it appear on the prefs page immediately; absence means "send by
     * default with no UI control" (used for system-critical types).
     *
     * Channels: 'in_app' is the bell-badge; 'email' rides MailService.
     * Both are gated independently per-user via notification_preferences.
     */
    public const TYPES = [
        'social.followed' => [
            'label'    => 'Someone follows you',
            'group'    => 'Social',
            'channels' => ['in_app', 'email'],
        ],
        'comment.reply' => [
            'label'    => 'Someone replies to your comment',
            'group'    => 'Social',
            'channels' => ['in_app', 'email'],
        ],
        'messages.new' => [
            'label'    => 'You receive a direct message',
            'group'    => 'Messaging',
            'channels' => ['in_app', 'email'],
        ],
        'group.owner_removal_request' => [
            'label'    => 'Group owner-removal request',
            'group'    => 'Groups',
            'channels' => ['in_app', 'email'],
        ],
    ];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Send an in-app notification (and optionally email/SMS).
     *
     * Per-channel suppression: a notification_preferences row with
     * enabled=0 for (user, type, in_app) skips the in-app insert
     * entirely (returns empty string). Same gate applies per-channel
     * so a user can opt out of email reminders while keeping the bell
     * badge ticking, or vice versa. Default-on when no row exists.
     */
    public function send(int $userId, string $type, string $title, string $body, array $data = [], string $channels = 'in_app'): string
    {
        // Filter the requested channels by the user's prefs. We always
        // record the actually-dispatched channels in the row's `channel`
        // column so audit / debugging shows the effective decision.
        $requested = array_filter(array_map('trim', explode(',', $channels)), 'strlen');
        $allowed   = array_values(array_filter($requested,
            fn($ch) => $this->isAllowed($userId, $type, $ch)
        ));

        // If every requested channel is suppressed, return without
        // inserting anything. The caller doesn't need to know — the
        // preferences page is the single source of truth.
        if (empty($allowed)) return '';

        // For now `in_app` is the only channel that writes a row in
        // `notifications`; the email/SMS dispatch (when a sibling
        // channel is included) is a side effect handled by callers
        // that wire their own MailService::send call. The pref gate
        // above already vetoed those — so this always-insert path
        // only fires when in_app survived the filter.
        if (!in_array('in_app', $allowed, true)) return '';

        $uuid = $this->uuid4();
        $this->db->insert('notifications', [
            'id'         => $uuid,
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'data'       => json_encode($data),
            'channel'    => implode(',', $allowed),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /**
     * Is this user opted in to receive $type via $channel?
     * Default true (no preference row = receive by default). Returns
     * false only when the user explicitly toggled the channel off.
     *
     * Wrapped in try/catch so a missing notification_preferences table
     * (e.g. migration not applied) fails open — better to keep sending
     * than to silently drop everything because a table is absent.
     */
    public function isAllowed(int $userId, string $type, string $channel): bool
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT enabled FROM notification_preferences
                  WHERE user_id = ? AND type = ? AND channel = ? LIMIT 1",
                [$userId, $type, $channel]
            );
            if (!$row) return true;
            return (int) $row['enabled'] === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Bulk-set preferences for a user from a posted form. $prefs is the
     * shape {type => {channel => bool}}. Anything in TYPES that isn't
     * present in the input is treated as enabled (default-on). Used by
     * the profile preferences POST handler.
     */
    public function setPreferences(int $userId, array $prefs): void
    {
        foreach (self::TYPES as $type => $meta) {
            foreach ($meta['channels'] as $ch) {
                $on = !empty($prefs[$type][$ch]);
                $this->db->query(
                    "INSERT INTO notification_preferences (user_id, type, channel, enabled)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)",
                    [$userId, $type, $ch, $on ? 1 : 0]
                );
            }
        }
    }

    /**
     * Read preferences for one user as a flat map {type => {channel => bool}}.
     * Missing entries default to true.
     */
    public function preferencesFor(int $userId): array
    {
        $out = [];
        foreach (self::TYPES as $type => $meta) {
            $out[$type] = [];
            foreach ($meta['channels'] as $ch) {
                $out[$type][$ch] = $this->isAllowed($userId, $type, $ch);
            }
        }
        return $out;
    }

    public function markRead(string $notificationId, int $userId): void
    {
        $this->db->update('notifications',
            ['read_at' => date('Y-m-d H:i:s')],
            'id = ? AND user_id = ?',
            [$notificationId, $userId]
        );
    }

    /**
     * Permanently delete a notification. Guarded by canDelete() — callers
     * should verify before invoking, but the owner filter here is a
     * second line of defense against cross-user deletes.
     */
    public function delete(string $notificationId, int $userId): void
    {
        $this->db->delete('notifications', 'id = ? AND user_id = ?', [$notificationId, $userId]);
    }

    /**
     * True when the notification is safe to dismiss: it's been read AND
     * any action it represents (transfer request, invitation, owner
     * removal) has been resolved one way or another.
     *
     * Plain informational notifications with no linked action fall through
     * to "just needs to be read" via actionResolved() returning true.
     */
    public function canDelete(array $notification): bool
    {
        if (empty($notification['read_at'])) return false;
        return $this->actionResolved($notification);
    }

    /**
     * True when the action this notification points at is no longer
     * pending. Covers the action types we currently dispatch; anything
     * unknown is treated as "no action to wait on" so users aren't blocked
     * from dismissing older notifications when new types ship.
     */
    public function actionResolved(array $notification): bool
    {
        $type = (string)($notification['type'] ?? '');
        $data = !empty($notification['data']) ? json_decode((string)$notification['data'], true) : [];
        if (!is_array($data)) $data = [];

        switch ($type) {
            case 'content.transfer_request':
            case 'content.transfer_offer':
                $rid = (int)($data['request_id'] ?? 0);
                if ($rid <= 0) return true;
                $row = $this->db->fetchOne(
                    "SELECT status FROM content_transfer_requests WHERE id = ?",
                    [$rid]
                );
                return !$row || $row['status'] !== 'pending';

            case 'group.owner_removal_request':
                $rid = (int)($data['request_id'] ?? 0);
                if ($rid <= 0) return true;
                $row = $this->db->fetchOne(
                    "SELECT status FROM group_owner_removal_requests WHERE id = ?",
                    [$rid]
                );
                return !$row || $row['status'] !== 'pending';

            case 'group.invitation':
                // Token lives in data.join_url — extract it rather than
                // storing it separately so existing invitation notifications
                // still work.
                $url = (string)($data['join_url'] ?? '');
                if (!$url || !preg_match('#/join/([A-Za-z0-9]+)#', $url, $m)) return true;
                $row = $this->db->fetchOne(
                    "SELECT status FROM group_invitations WHERE token = ?",
                    [$m[1]]
                );
                return !$row || $row['status'] !== 'pending';

            default:
                return true;
        }
    }

    /**
     * Convenience: enrich a list of notifications with a computed
     * `can_delete` flag so views don't have to re-implement the rules.
     */
    public function annotate(array $notifications): array
    {
        foreach ($notifications as &$n) {
            $n['can_delete'] = $this->canDelete($n);
        }
        return $notifications;
    }

    public function getUnread(int $userId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    public function getAll(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

