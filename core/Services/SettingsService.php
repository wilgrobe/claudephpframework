<?php
// core/Services/SettingsService.php
namespace Core\Services;

use Core\Database\Database;

/**
 * SettingsService — key-value configuration store with scope support.
 *
 * Scopes: site | page | function | group
 */
class SettingsService
{
    private Database $db;
    private array    $cache = [];
    /** Tracks which (scope, scopeKey) buckets have been bulk-warmed.
     *  Once warmed, a missing key is authoritative — no per-key fallback
     *  query needed. Key shape: "scope:scopeKey" (scopeKey may be null). */
    private array    $warmed = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Phase 43.197c M2 — invalidate the warm-cache for a specific bucket
     * (and optionally a specific key). Long-running CLI loops (cron sweeps
     * like `marketing:dispatch-scheduled`, GDPR purger, ZipBuilder 90s+
     * builds) need this so they pick up admin-set changes mid-script.
     * Pre-fix the `$warmed` flag was sticky-per-request — an instance
     * cached at the start of a 90s build never saw a setting written
     * by an admin during the build window.
     *
     * Without args, drops everything. With args, drops a single bucket
     * or a single key within a bucket.
     *
     * Note: this is a single-process invalidator. Cross-process / cross-
     * server invalidation requires a versioned schema-stamp (planned in
     * a separate phase if/when settings get hotter write contention).
     */
    public function invalidate(?string $scope = null, ?string $scopeKey = null, ?string $key = null): void
    {
        if ($scope === null) {
            $this->cache = [];
            $this->warmed = [];
            return;
        }
        $bucketKey = "$scope:$scopeKey";
        if ($key === null) {
            unset($this->warmed[$bucketKey]);
            $prefix = "$bucketKey:";
            foreach (array_keys($this->cache) as $ck) {
                if (str_starts_with($ck, $prefix)) unset($this->cache[$ck]);
            }
            return;
        }
        unset($this->cache["$bucketKey:$key"]);
        // Bucket warmed-flag stays — caller invalidated ONE key, others
        // should keep their cached values.
    }

    /**
     * Bulk-load every row in a (scope, scopeKey) bucket into the per-key
     * cache with one SELECT. Subsequent get() calls in the same request
     * hit the cache; misses are authoritative (no second query).
     *
     * Called lazily from get() the first time a bucket is touched. Site-
     * scope rows are typically <300 — one fetchAll is dramatically cheaper
     * than the 100+ round trips header.php would otherwise pay through
     * ThemeService + setting() + chrome lookups.
     */
    private function warmBucket(string $scope, ?string $scopeKey): void
    {
        $bucketKey = "$scope:$scopeKey";
        if (isset($this->warmed[$bucketKey])) return;
        // Mark warmed BEFORE the query so a fetchAll exception (uninitialized
        // PDO in tests, dropped connection in production) doesn't cascade
        // into a retry storm. Readers fall through to their callback default.
        $this->warmed[$bucketKey] = true;

        try {
            $rows = $this->db->fetchAll(
                "SELECT `key`, value, type FROM settings WHERE scope = ? AND scope_key <=> ?",
                [$scope, $scopeKey]
            );
        } catch (\Throwable $e) {
            error_log('[settings] warmBucket(' . $bucketKey . ') failed: ' . $e->getMessage());
            return;
        }
        foreach ($rows as $row) {
            $this->cache["$scope:$scopeKey:" . $row['key']] = $this->cast($row['value'], $row['type']);
        }
    }

    public function get(string $key, mixed $default = null, string $scope = 'site', ?string $scopeKey = null): mixed
    {
        $cacheKey = "$scope:$scopeKey:$key";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Warm the whole bucket on first miss. After this returns, any
        // legitimately-missing key stays missing - we don't fall through
        // to a per-key SELECT because the bucket-load was authoritative.
        if (!isset($this->warmed["$scope:$scopeKey"])) {
            $this->warmBucket($scope, $scopeKey);
            if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];
            return $default;
        }

        // Already warmed and the key isn't in cache — definitively missing.
        return $default;
    }

    public function set(string $key, mixed $value, string $scope = 'site', ?string $scopeKey = null, string $type = 'string'): void
    {
        // Phase 43.197c L2 — strict JSON type coercion. Pre-fix
        // `is_array($value) ? json_encode($value) : (string) $value`
        // silently corrupted type=json: caller passing a string with
        // type='json' persisted verbatim, then get() called
        // json_decode() returning null. Now: when type='json', always
        // json_encode regardless of input shape. Arrays + objects
        // round-trip naturally; scalars like int/string get wrapped
        // (`5` → `"5"` → json_decode → 5; `"hi"` → `"\"hi\""` → "hi").
        if ($type === 'json') {
            $encoded = json_encode($value);
            if ($encoded === false) {
                throw new \InvalidArgumentException(
                    "SettingsService::set type='json' value couldn't be encoded: " . json_last_error_msg()
                );
            }
        } elseif (is_array($value)) {
            // Legacy back-compat: array passed with non-json type still gets
            // json_encode'd. Callers should use type='json' explicitly going
            // forward but we don't break existing behaviour.
            $encoded = json_encode($value);
        } else {
            $encoded = (string) $value;
        }
        // Phase 43.201b H1 — SELECT-then-write race wrapped in a
        // transaction with FOR UPDATE. Pre-fix two concurrent writes
        // of the same (scope, scope_key, key) tuple both SELECTed and
        // saw no existing row → both attempted INSERT → second hit
        // the UNIQUE index and threw a PDOException out of the
        // request. Same shape Phase 43.197a C3 closed for
        // project_settings (which has the same NULL-scope_key UNIQUE
        // semantics; MySQL treats each NULL as distinct so
        // ON DUPLICATE KEY UPDATE can't help — must serialise via
        // FOR UPDATE inside a tx instead).
        //
        // WHERE column order matches the (scope, scope_key, key)
        // UNIQUE index so the optimiser uses the leftmost prefix.
        $this->db->beginTransaction();
        try {
            $existing = $this->db->fetchOne(
                "SELECT id FROM settings WHERE scope = ? AND scope_key <=> ? AND `key` = ? FOR UPDATE",
                [$scope, $scopeKey, $key]
            );
            if ($existing) {
                $this->db->update('settings', ['value' => $encoded, 'type' => $type], 'id = ?', [$existing['id']]);
            } else {
                $this->db->insert('settings', [
                    'scope'     => $scope,
                    'scope_key' => $scopeKey,
                    'key'       => $key,
                    'value'     => $encoded,
                    'type'      => $type,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        // Refresh the cache entry rather than unset - the bucket is already
        // warmed, so unsetting would force a missing-key default on the next
        // read (the warm flag prevents a second SELECT).
        $this->cache["$scope:$scopeKey:$key"] = $this->cast($encoded, $type);
    }

    public function all(string $scope = 'site', ?string $scopeKey = null): array
    {
        // Reuse the bulk-warmed cache when available so admin pages don't
        // re-query for the same bucket the request already loaded.
        $this->warmBucket($scope, $scopeKey);
        $prefix = "$scope:$scopeKey:";
        $prefixLen = strlen($prefix);
        $result = [];
        foreach ($this->cache as $cacheKey => $value) {
            if (strncmp($cacheKey, $prefix, $prefixLen) !== 0) continue;
            $result[substr($cacheKey, $prefixLen)] = $value;
        }
        return $result;
    }

    /**
     * Same scope filter as all(), but returns the raw string + type alongside
     * the cast value. The admin settings grid needs this — it can't pick the
     * right input widget (text, number, toggle, textarea) without knowing the
     * type, and can't repaint the row on edit without the raw string value.
     *
     * Shape: [ $key => ['raw' => string, 'value' => mixed, 'type' => string] ]
     */
    public function allWithMeta(string $scope = 'site', ?string $scopeKey = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, value, type FROM settings WHERE scope = ? AND scope_key <=> ?",
            [$scope, $scopeKey]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = [
                'raw'   => (string) $row['value'],
                'value' => $this->cast($row['value'], $row['type']),
                'type'  => (string) $row['type'],
            ];
        }
        return $result;
    }

    public function delete(string $key, string $scope = 'site', ?string $scopeKey = null): void
    {
        // Same index-leftmost-prefix order as set()'s lookup.
        $this->db->delete('settings',
            'scope = ? AND scope_key <=> ? AND `key` = ?',
            [$scope, $scopeKey, $key]
        );
        // Drop the cached entry so the next read resolves to the default.
        unset($this->cache["$scope:$scopeKey:$key"]);
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => $value === 'true' || $value === '1',
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }
}
