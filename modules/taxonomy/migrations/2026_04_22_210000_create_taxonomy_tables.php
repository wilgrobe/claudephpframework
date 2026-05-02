<?php
// modules/taxonomy/migrations/2026_04_22_210000_create_taxonomy_tables.php
use Core\Database\Migration;

/**
 * Schema for the taxonomy module. Four tables:
 *
 *   taxonomy_sets          — "vocabularies". Each set is an independent
 *                            tree (or flat list, if allow_hierarchy=0).
 *                            Example rows: 'product-categories',
 *                            'article-tags', 'difficulty-levels'.
 *
 *   taxonomy_terms         — the nodes. Every term belongs to a set and
 *                            optionally has a parent_id within the same set.
 *                            Slugs are unique within a set.
 *
 *   taxonomy_closure       — closure table. One row per (ancestor, descendant)
 *                            pair including self-rows (depth=0). Lets
 *                            "all descendants of X" and "path to root"
 *                            be single SELECTs with no recursion.
 *
 *   taxonomy_entity_terms  — polymorphic attach. Any entity in the app
 *                            can carry terms via (entity_type, entity_id).
 *                            Same string-type convention as comments —
 *                            'content', 'page', 'product' etc.; never PHP
 *                            class names.
 *
 * Closure table maintenance:
 *   - INSERT a new term:   add self-row (t, t, 0) + for every ancestor A of
 *                          parent, add (A, t, depth+1). Bounded by the
 *                          depth of the tree (capped at 10 in practice).
 *   - DELETE a term:       cascade removes all closure rows where ancestor
 *                          or descendant is the deleted id.
 *   - MOVE a term:         not supported in MVP. Delete + recreate.
 *
 * Performance:
 *   - idx_closure_ancestor = descendants-of-X queries
 *   - idx_closure_descendant = path-to-root queries
 *   - idx_entity = "what terms does this entity have?" query
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE taxonomy_sets (
                id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name             VARCHAR(191) NOT NULL,
                slug             VARCHAR(120) NOT NULL,
                description      VARCHAR(500) NULL,
                allow_hierarchy  TINYINT(1)   NOT NULL DEFAULT 1,
                created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE taxonomy_terms (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                set_id       INT UNSIGNED NOT NULL,
                parent_id    INT UNSIGNED NULL,
                name         VARCHAR(191) NOT NULL,
                slug         VARCHAR(191) NOT NULL,
                description  VARCHAR(500) NULL,
                sort_order   INT          NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_set_slug (set_id, slug),
                KEY idx_set_parent (set_id, parent_id),
                KEY idx_parent     (parent_id),
                CONSTRAINT fk_term_set FOREIGN KEY (set_id)
                    REFERENCES taxonomy_sets (id) ON DELETE CASCADE,
                CONSTRAINT fk_term_parent FOREIGN KEY (parent_id)
                    REFERENCES taxonomy_terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE taxonomy_closure (
                ancestor_id    INT UNSIGNED NOT NULL,
                descendant_id  INT UNSIGNED NOT NULL,
                depth          INT UNSIGNED NOT NULL,

                PRIMARY KEY (ancestor_id, descendant_id),
                KEY idx_descendant (descendant_id, depth),
                KEY idx_ancestor   (ancestor_id, depth),
                CONSTRAINT fk_clos_anc FOREIGN KEY (ancestor_id)
                    REFERENCES taxonomy_terms (id) ON DELETE CASCADE,
                CONSTRAINT fk_clos_desc FOREIGN KEY (descendant_id)
                    REFERENCES taxonomy_terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE taxonomy_entity_terms (
                term_id      INT UNSIGNED NOT NULL,
                entity_type  VARCHAR(64)  NOT NULL,
                entity_id    BIGINT UNSIGNED NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (term_id, entity_type, entity_id),
                KEY idx_entity (entity_type, entity_id),
                KEY idx_term   (term_id),
                CONSTRAINT fk_entity_term_term FOREIGN KEY (term_id)
                    REFERENCES taxonomy_terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS taxonomy_entity_terms");
        $this->db->query("DROP TABLE IF EXISTS taxonomy_closure");
        $this->db->query("DROP TABLE IF EXISTS taxonomy_terms");
        $this->db->query("DROP TABLE IF EXISTS taxonomy_sets");
    }
};
