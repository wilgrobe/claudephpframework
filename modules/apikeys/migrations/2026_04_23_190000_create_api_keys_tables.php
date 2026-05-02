<?php
// modules/api-keys/migrations/2026_04_23_190000_create_api_keys_tables.php
use Core\Database\Migration;

/**
 * Schema for user-facing API keys.
 *
 *   api_keys         — one row per issued key. The plaintext token is
 *                      *never* stored — only its SHA-256 hash. We also
 *                      store a short `prefix` (the human-readable
 *                      "phpk_live_ABCD" part) for the UI to display,
 *                      and a `last_four` of the full token so users
 *                      can distinguish "their Mac's key" from "their
 *                      server's key" without seeing the secret.
 *
 *                      `scopes_json` is an array of scope strings
 *                      (read:store, write:content, ...). Scopes are
 *                      app-defined; the middleware just does exact
 *                      string matching against required scopes on a
 *                      route.
 *
 *                      revoked_at nulls → active; set → rejected by
 *                      the middleware even if the hash matches.
 *                      expires_at nulls → no expiry.
 *                      last_used_at updated opportunistically at
 *                      auth time (non-blocking).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE api_keys (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id        INT UNSIGNED NOT NULL,
                name           VARCHAR(120) NOT NULL,
                prefix         VARCHAR(32)  NOT NULL,
                token_hash     CHAR(64)     NOT NULL,
                last_four      CHAR(4)      NOT NULL,
                scopes_json    TEXT         NOT NULL DEFAULT ('[]'),
                last_used_at   DATETIME NULL,
                expires_at     DATETIME NULL,
                revoked_at     DATETIME NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_token_hash (token_hash),
                KEY idx_user    (user_id, revoked_at),
                KEY idx_prefix  (prefix)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS api_keys");
    }
};
