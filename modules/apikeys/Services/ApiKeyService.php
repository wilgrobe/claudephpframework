<?php
// modules/api-keys/Services/ApiKeyService.php
namespace Modules\ApiKeys\Services;

use Core\Database\Database;

/**
 * API key lifecycle: mint, list, revoke, authenticate.
 *
 * Security posture:
 *   - Plaintext tokens are returned to the user exactly once, at mint
 *     time. Only SHA-256(token) is persisted. We can't show the token
 *     again; users must regenerate a new key if they lose it.
 *   - Token format: {prefix}_{32_url_safe_base64}. Prefix is
 *     "phpk_live" by convention so tokens are recognizable in logs.
 *     The 24-byte random segment = 192 bits of entropy ≫ any
 *     realistic brute-force budget.
 *   - Lookup uses the UNIQUE index on `token_hash` — constant-time
 *     equality at the DB level. Not a timing oracle since it's a
 *     hash comparison, not a plaintext compare.
 *   - `last_used_at` is updated opportunistically; failure to update
 *     never blocks auth. Lost updates on a racing write are fine —
 *     last-writer-wins is acceptable for a "when did this key last
 *     auth" stat.
 */
class ApiKeyService
{
    private const PREFIX = 'phpk_live';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM api_keys WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, created_at DESC",
            [$userId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM api_keys WHERE id = ?", [$id]);
    }

    // ── Mint ──────────────────────────────────────────────────────────────

    /**
     * Mint a new key for a user. Returns:
     *   ['id' => int, 'token' => string, 'prefix' => string, 'last_four' => string]
     * Token is present ONLY in this return value — never readable again.
     *
     * @param list<string> $scopes
     */
    public function mint(int $userId, string $name, array $scopes = [], ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('Key name required.');

        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes))));
        // 24 bytes of entropy → 32 url-safe base64 chars (no padding, no '+/').
        $random = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $token  = self::PREFIX . '_' . $random;
        $hash   = hash('sha256', $token);
        $last4  = substr($token, -4);

        $id = (int) $this->db->insert('api_keys', [
            'user_id'     => $userId,
            'name'        => substr($name, 0, 120),
            'prefix'      => self::PREFIX,
            'token_hash'  => $hash,
            'last_four'   => $last4,
            'scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'expires_at'  => $expiresAt ?: null,
        ]);

        return [
            'id'        => $id,
            'token'     => $token,
            'prefix'    => self::PREFIX,
            'last_four' => $last4,
        ];
    }

    // ── Revoke ────────────────────────────────────────────────────────────

    public function revoke(int $id, int $userId): bool
    {
        $row = $this->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) return false;
        $this->db->update('api_keys',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'id = ?', [$id]
        );
        return true;
    }

    // ── Authenticate ──────────────────────────────────────────────────────

    /**
     * Resolve a presented bearer token to an owning user + scopes.
     * Returns null on any failure (unknown, revoked, expired, hash
     * mismatch). The middleware should treat null as 401.
     *
     * Opportunistic last_used_at update is done here, swallowing
     * failures so a busy DB can never block auth.
     *
     * @return array{user_id: int, key_id: int, scopes: list<string>}|null
     */
    public function authenticate(string $presented): ?array
    {
        $presented = trim($presented);
        if ($presented === '' || !str_starts_with($presented, self::PREFIX . '_')) return null;

        $hash = hash('sha256', $presented);
        $row  = $this->db->fetchOne(
            "SELECT * FROM api_keys WHERE token_hash = ? LIMIT 1",
            [$hash]
        );
        if (!$row) return null;
        if ($row['revoked_at']) return null;
        if ($row['expires_at'] && strtotime((string) $row['expires_at']) < time()) return null;

        // Opportunistic usage update — errors are non-fatal.
        try {
            $this->db->update('api_keys',
                ['last_used_at' => date('Y-m-d H:i:s')],
                'id = ?', [(int) $row['id']]
            );
        } catch (\Throwable $e) {
            error_log('[api-keys] last_used_at update failed: ' . $e->getMessage());
        }

        $scopes = json_decode((string) $row['scopes_json'], true) ?: [];
        return [
            'user_id' => (int) $row['user_id'],
            'key_id'  => (int) $row['id'],
            'scopes'  => array_values(array_map('strval', $scopes)),
        ];
    }

    /**
     * Convenience scope checker. Matches exact strings; returns true
     * when all required scopes are present in the auth context.
     *
     * @param list<string> $required
     * @param list<string> $granted
     */
    public static function hasScopes(array $required, array $granted): bool
    {
        if (empty($required)) return true;
        $g = array_flip($granted);
        foreach ($required as $s) {
            if (!isset($g[$s])) return false;
        }
        return true;
    }
}
