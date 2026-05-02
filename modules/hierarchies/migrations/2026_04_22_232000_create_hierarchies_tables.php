<?php
// modules/hierarchies/migrations/2026_04_22_232000_create_hierarchies_tables.php
use Core\Database\Migration;

/**
 * Schema for the hierarchies module.
 *
 * Separate from taxonomy on purpose — taxonomy is for *classification*
 * (tagging things with terms), hierarchies is for *navigable structure*
 * (site menus, org charts, curated product catalogs, section trees).
 * Both could share a closure table primitive, but their concerns
 * diverge enough that collapsing them would muddle both admin UIs.
 *
 *   hierarchies              — named trees (slug, label, description).
 *
 *   hierarchy_nodes          — parent-pointer tree with per-parent
 *                              sort_order for drag-and-drop reordering.
 *                              Nodes carry label + slug + optional url +
 *                              icon + color + metadata_json so the
 *                              same table covers nav menus, catalog
 *                              categories, org charts, and anything
 *                              tree-shaped.
 *
 *   hierarchy_node_closure   — closure table (ancestor_id, descendant_id,
 *                              depth). Makes descendant lookups O(depth)
 *                              instead of recursive. Maintained by
 *                              HierarchyService on insert/move/delete.
 *                              Includes self-rows (depth=0) so SELECTs
 *                              can use "WHERE ancestor_id = X" to get
 *                              the subtree including X itself.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE hierarchies (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(120) NOT NULL,
                slug        VARCHAR(120) NOT NULL,
                description TEXT         NULL,
                active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE hierarchy_nodes (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                hierarchy_id   INT UNSIGNED NOT NULL,
                parent_id      INT UNSIGNED NULL,
                label          VARCHAR(191) NOT NULL,
                slug           VARCHAR(160) NOT NULL,
                url            VARCHAR(500) NULL,
                icon           VARCHAR(64)  NULL,
                color          VARCHAR(16)  NULL,
                metadata_json  TEXT         NULL,
                sort_order     INT          NOT NULL DEFAULT 0,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_hierarchy_slug (hierarchy_id, slug),
                KEY idx_parent_sort (parent_id, sort_order),
                KEY idx_hierarchy (hierarchy_id),
                CONSTRAINT fk_hn_hierarchy
                    FOREIGN KEY (hierarchy_id) REFERENCES hierarchies (id) ON DELETE CASCADE,
                CONSTRAINT fk_hn_parent
                    FOREIGN KEY (parent_id) REFERENCES hierarchy_nodes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE hierarchy_node_closure (
                ancestor_id   INT UNSIGNED NOT NULL,
                descendant_id INT UNSIGNED NOT NULL,
                depth         INT UNSIGNED NOT NULL,
                PRIMARY KEY (ancestor_id, descendant_id),
                KEY idx_desc (descendant_id, depth),
                CONSTRAINT fk_hnc_ancestor
                    FOREIGN KEY (ancestor_id)   REFERENCES hierarchy_nodes (id) ON DELETE CASCADE,
                CONSTRAINT fk_hnc_descendant
                    FOREIGN KEY (descendant_id) REFERENCES hierarchy_nodes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS hierarchy_node_closure");
        $this->db->query("DROP TABLE IF EXISTS hierarchy_nodes");
        $this->db->query("DROP TABLE IF EXISTS hierarchies");
    }
};
