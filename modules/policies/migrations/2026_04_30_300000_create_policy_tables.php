<?php
// modules/policies/migrations/2026_04_30_300000_create_policy_tables.php
use Core\Database\Migration;

/**
 * Schema for policy versioning + acceptance tracking.
 *
 *   policy_kinds        — registry of policy kinds (tos, privacy,
 *                         acceptable_use + admin-defined customs).
 *                         is_system=1 protects the seeded set from
 *                         deletion via /admin/policies.
 *
 *   policies            — one row per kind with the live page reference
 *                         and pointer to the current version. Splitting
 *                         from policy_kinds lets a kind exist without
 *                         a body wired up yet (admin sets it up in two
 *                         steps: define kind → assign live page).
 *
 *   policy_versions     — append-only history of accepted text.
 *                         body_html is a SNAPSHOT of the source page's
 *                         body at the moment "Bump version" was clicked,
 *                         not a live reference. This is essential — the
 *                         text the user accepted on date X must remain
 *                         queryable forever, even if the source page
 *                         is later edited.
 *
 *   policy_acceptances  — one row per (user, version). user_id is
 *                         INT NULL so we can keep the row after a GDPR
 *                         erasure (the user is gone but the count of
 *                         accepts in that version stays accurate as
 *                         compliance evidence — we anonymise rather
 *                         than delete).
 */
return new class extends Migration {
    public function up(): void
    {
        // ── policy_kinds ────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE policy_kinds (
                id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug                 VARCHAR(64)  NOT NULL UNIQUE
                                     COMMENT 'tos, privacy, acceptable_use, custom_*',
                label                VARCHAR(191) NOT NULL,
                description          TEXT NULL,
                requires_acceptance  TINYINT(1) NOT NULL DEFAULT 1
                                     COMMENT '0 = display-only (cookie policy maybe), 1 = blocking modal until accepted',
                is_system            TINYINT(1) NOT NULL DEFAULT 0
                                     COMMENT '1 = seeded set, cannot be deleted from admin UI',
                sort_order           INT NOT NULL DEFAULT 0,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ── policies (1:1 with policy_kinds) ────────────────────────
        $this->db->query("
            CREATE TABLE policies (
                kind_id              INT UNSIGNED NOT NULL PRIMARY KEY,
                source_page_id       INT UNSIGNED NULL
                                     COMMENT 'Live page whose body is snapshotted on bump-version. NULL until admin assigns one.',
                current_version_id   INT UNSIGNED NULL,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_pol_kind FOREIGN KEY (kind_id)
                    REFERENCES policy_kinds (id) ON DELETE CASCADE,
                CONSTRAINT fk_pol_page FOREIGN KEY (source_page_id)
                    REFERENCES pages (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ── policy_versions (append-only history) ───────────────────
        $this->db->query("
            CREATE TABLE policy_versions (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kind_id         INT UNSIGNED NOT NULL,
                version_label   VARCHAR(64) NOT NULL
                                COMMENT 'human-friendly label (1.0, 2026-04-30, etc.)',
                body_html       LONGTEXT NULL
                                COMMENT 'Snapshot of source page body at the time of bump. NULL when admin hasn''t wired a source page.',
                source_page_id  INT UNSIGNED NULL
                                COMMENT 'Page snapshotted at bump (info-only — page may be deleted later)',
                effective_date  DATE NOT NULL,
                summary         TEXT NULL
                                COMMENT 'Optional short summary of what changed — shown to users on the re-acceptance modal',
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by      INT UNSIGNED NULL,

                KEY idx_kind_eff (kind_id, effective_date),
                CONSTRAINT fk_polver_kind  FOREIGN KEY (kind_id)        REFERENCES policy_kinds (id) ON DELETE CASCADE,
                CONSTRAINT fk_polver_page  FOREIGN KEY (source_page_id) REFERENCES pages (id)        ON DELETE SET NULL,
                CONSTRAINT fk_polver_actor FOREIGN KEY (created_by)     REFERENCES users (id)        ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Backfill policies.current_version_id FK now that the target
        // table exists.
        $this->db->query("
            ALTER TABLE policies
            ADD CONSTRAINT fk_pol_curver
            FOREIGN KEY (current_version_id) REFERENCES policy_versions (id) ON DELETE SET NULL
        ");

        // ── policy_acceptances (audit trail) ────────────────────────
        $this->db->query("
            CREATE TABLE policy_acceptances (
                id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id         INT UNSIGNED NULL
                                COMMENT 'NULLed on GDPR erasure to anonymise the row while preserving the count',
                kind_id         INT UNSIGNED NOT NULL,
                version_id      INT UNSIGNED NOT NULL,
                accepted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address      VARBINARY(16) NULL,
                user_agent      VARCHAR(500) NULL,

                UNIQUE KEY uq_user_version (user_id, version_id),
                KEY idx_kind_version (kind_id, version_id),
                KEY idx_user (user_id),
                CONSTRAINT fk_polacc_user    FOREIGN KEY (user_id)    REFERENCES users (id)            ON DELETE SET NULL,
                CONSTRAINT fk_polacc_kind    FOREIGN KEY (kind_id)    REFERENCES policy_kinds (id)     ON DELETE CASCADE,
                CONSTRAINT fk_polacc_version FOREIGN KEY (version_id) REFERENCES policy_versions (id)  ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS policy_acceptances");
        $this->db->query("DROP TABLE IF EXISTS policies");
        $this->db->query("DROP TABLE IF EXISTS policy_versions");
        $this->db->query("DROP TABLE IF EXISTS policy_kinds");
    }
};
