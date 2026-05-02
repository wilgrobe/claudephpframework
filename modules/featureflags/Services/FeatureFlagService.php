<?php
// modules/featureflags/Services/FeatureFlagService.php
namespace Modules\FeatureFlags\Services;

use Core\Database\Database;

/**
 * Flag resolution + CRUD.
 *
 * Resolution precedence (first match wins):
 *   1. Per-user override — `feature_flag_overrides` row for this (user, flag).
 *   2. Global disabled — flag.enabled=0 → false.
 *   3. Group rollout — user is in any of flag.groups_json → true.
 *   4. Percentage rollout — hash(user_id, flag_key) % 100 < rollout_percent → true.
 *   5. Otherwise — true if no constraints (empty groups + 100% rollout).
 *
 * Guest users (user_id = null) skip rules 1/3/4; they're either "on for
 * everyone" (global + 100%) or "off".
 *
 * Hashing: SHA-256(user_id . ':' . key) truncated to 8 hex digits as a
 * 32-bit int, modulo 100. Deterministic across calls, stable across
 * process restarts. No external seed.
 *
 * In-request cache keyed by "key:user_id" so `feature('x')` in a view
 * doesn't hit the DB each call.
 */
class FeatureFlagService
{
    /** @var array<string, bool> */
    private static array $cache = [];
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $flagsCache = null;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Resolution ──────────────────────────────────────────────────────

    public function isEnabled(string $key, ?int $userId = null): bool
    {
        $cacheKey = $key . ':' . ($userId ?? 'null');
        if (isset(self::$cache[$cacheKey])) return self::$cache[$cacheKey];

        $flag = $this->findFlag($key);
        if (!$flag) return self::$cache[$cacheKey] = false;

        // 1. Per-user override.
        if ($userId !== null) {
            $row = $this->db->fetchOne(
                "SELECT enabled FROM feature_flag_overrides WHERE user_id = ? AND flag_key = ?",
                [$userId, $key]
            );
            if ($row !== null) {
                return self::$cache[$cacheKey] = (int) $row['enabled'] === 1;
            }
        }

        // 2. Global disabled — kill switch.
        if ((int) $flag['enabled'] !== 1) return self::$cache[$cacheKey] = false;

        // 3. Group membership.
        $groups = !empty($flag['groups_json'])
            ? (json_decode((string) $flag['groups_json'], true) ?: [])
            : [];
        if (!empty($groups) && $userId !== null) {
            $ph = implode(',', array_fill(0, count($groups), '?'));
            $hit = $this->db->fetchOne(
                "SELECT 1 FROM user_groups
                  WHERE user_id = ? AND group_id IN ($ph) LIMIT 1",
                [$userId, ...array_map('intval', $groups)]
            );
            if ($hit) return self::$cache[$cacheKey] = true;
        }

        // 4. Percentage rollout.
        $pct = (int) $flag['rollout_percent'];
        if ($pct >= 100) return self::$cache[$cacheKey] = true;
        if ($pct <= 0)   return self::$cache[$cacheKey] = false;

        // Guests without a user_id fall on the deterministic-percentage
        // side: they all get the same hash "bucket" (hash(0:key)), so
        // either they're all in or all out for a given flag. Alternative
        // would be "guests always excluded from partial rollouts" — less
        // surprising, but less useful for canarying anonymous-facing UI.
        $bucket = self::hashBucket($userId ?? 0, $key);
        return self::$cache[$cacheKey] = $bucket < $pct;
    }

    public static function hashBucket(int $userId, string $key): int
    {
        $h = hash('sha256', $userId . ':' . $key);
        // First 8 hex chars → 32-bit unsigned int → mod 100.
        return hexdec(substr($h, 0, 8)) % 100;
    }

    // ── Reads ────────────────────────────────────────────────────────────

    public function findFlag(string $key): ?array
    {
        return $this->db->fetchOne("SELECT * FROM feature_flags WHERE `key` = ?", [$key]);
    }

    /** @return array<int, array<string, mixed>> */
    public function allFlags(): array
    {
        return $this->db->fetchAll("SELECT * FROM feature_flags ORDER BY `key` ASC");
    }

    /** @return array<int, array<string, mixed>> */
    public function overridesFor(string $key): array
    {
        return $this->db->fetchAll(
            "SELECT o.*, u.username
               FROM feature_flag_overrides o
          LEFT JOIN users u ON u.id = o.user_id
              WHERE o.flag_key = ?
           ORDER BY o.created_at DESC",
            [$key]
        );
    }

    // ── Writes ───────────────────────────────────────────────────────────

    public function upsertFlag(array $data): void
    {
        $key = trim((string) ($data['key'] ?? ''));
        if ($key === '' || !preg_match('/^[a-z0-9_.\-]+$/', $key)) {
            throw new \InvalidArgumentException('Flag key must be a-z, 0-9, _ . - (no spaces).');
        }

        $row = [
            'label'           => substr((string) ($data['label'] ?? $key), 0, 191),
            'description'     => !empty($data['description']) ? (string) $data['description'] : null,
            'enabled'         => !empty($data['enabled']) ? 1 : 0,
            'rollout_percent' => max(0, min(100, (int) ($data['rollout_percent'] ?? 100))),
            'groups_json'     => !empty($data['group_ids'])
                ? json_encode(array_values(array_map('intval', (array) $data['group_ids'])),
                              JSON_UNESCAPED_SLASHES)
                : null,
        ];

        $existing = $this->findFlag($key);
        if ($existing) {
            $this->db->update('feature_flags', $row, '`key` = ?', [$key]);
        } else {
            $this->db->insert('feature_flags', array_merge(['key' => $key], $row));
        }
        self::clearCache();
    }

    public function deleteFlag(string $key): void
    {
        $this->db->delete('feature_flags', '`key` = ?', [$key]);
        self::clearCache();
    }

    public function setOverride(string $key, int $userId, bool $enabled, ?string $note = null): void
    {
        $existing = $this->db->fetchOne(
            "SELECT user_id FROM feature_flag_overrides WHERE user_id = ? AND flag_key = ?",
            [$userId, $key]
        );
        if ($existing) {
            $this->db->update('feature_flag_overrides',
                ['enabled' => $enabled ? 1 : 0, 'note' => $note],
                'user_id = ? AND flag_key = ?', [$userId, $key]);
        } else {
            $this->db->insert('feature_flag_overrides', [
                'user_id' => $userId, 'flag_key' => $key,
                'enabled' => $enabled ? 1 : 0, 'note' => $note,
            ]);
        }
        self::clearCache();
    }

    public function clearOverride(string $key, int $userId): void
    {
        $this->db->delete('feature_flag_overrides',
            'user_id = ? AND flag_key = ?', [$userId, $key]);
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$cache      = [];
        self::$flagsCache = null;
    }
}
