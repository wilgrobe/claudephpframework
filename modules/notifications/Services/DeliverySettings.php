<?php

namespace Modules\Notifications\Services;

use Core\Database\Database;

/**
 * Per-user notification delivery preference (generic; stored by the framework,
 * honored per-module). Two knobs:
 *   mode     each | digest | off   — individual notifications, one daily digest,
 *                                    or none.
 *   channel  both | email | sms | none   — none = on-site (in-app) only.
 *
 * Defaults (no stored row) are each / both, preserving prior behavior.
 */
class DeliverySettings
{
    public const MODES    = ['each', 'digest', 'off'];
    public const CHANNELS = ['both', 'email', 'sms', 'none'];

    /** @return array{mode:string, channel:string} */
    public static function get(int $userId): array
    {
        $mode = 'each';
        $channel = 'both';
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT delivery_mode, delivery_channel FROM user_notification_settings WHERE user_id = ? LIMIT 1",
                [$userId]
            );
            if ($row) {
                if (in_array($row['delivery_mode'], self::MODES, true))       $mode = (string) $row['delivery_mode'];
                if (in_array($row['delivery_channel'], self::CHANNELS, true))  $channel = (string) $row['delivery_channel'];
            }
        } catch (\Throwable) {}
        return ['mode' => $mode, 'channel' => $channel];
    }

    public static function set(int $userId, string $mode, string $channel): void
    {
        if (!in_array($mode, self::MODES, true))       $mode = 'each';
        if (!in_array($channel, self::CHANNELS, true)) $channel = 'both';
        try {
            Database::getInstance()->query(
                "INSERT INTO user_notification_settings (user_id, delivery_mode, delivery_channel)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE delivery_mode = VALUES(delivery_mode), delivery_channel = VALUES(delivery_channel)",
                [$userId, $mode, $channel]
            );
        } catch (\Throwable) {}
    }

    /**
     * Does the user's global channel choice permit this channel? in-app is always
     * permitted here (the per-type matrix still gates it); email/sms are gated by
     * the delivery_channel knob. 'none' = on-site only.
     */
    public static function channelAllows(string $channel, string $deliveryChannel): bool
    {
        if ($channel === 'in_app') return true;
        if ($deliveryChannel === 'none') return false;
        if ($deliveryChannel === 'both') return true;
        return $deliveryChannel === $channel; // 'email' or 'sms'
    }
}
