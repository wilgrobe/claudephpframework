<?php
// modules/security/migrations/2026_04_30_700000_create_password_breach_cache.php
use Core\Database\Migration;

/**
 * Cache for HIBP "Have I Been Pwned" k-anonymity API responses.
 *
 * The HIBP "range" API takes the first 5 hex chars of an SHA-1
 * password hash and returns every (35-char-suffix, count) pair whose
 * full hash starts with that prefix — typically 300-700 results per
 * prefix. We cache the response per prefix for 24h so a busy site
 * doesn't hit HIBP for every signup attempt.
 *
 * Storing only the prefix + the parsed-out count map (NOT the
 * password the user typed) keeps the cache itself defensible: it
 * never holds a hash of any specific user's password, only a public
 * dataset that any client can fetch from HIBP.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE password_breach_cache (
                prefix      CHAR(5) NOT NULL PRIMARY KEY
                            COMMENT 'First 5 hex chars of SHA-1; uppercase per HIBP convention',
                payload     LONGTEXT NULL
                            COMMENT 'Raw HIBP response body (newline-delimited SUFFIX:COUNT)',
                fetched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at  DATETIME NOT NULL,
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS password_breach_cache");
    }
};
