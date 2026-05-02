<?php
// modules/taxonomy/Services/TaxonomyService.php
namespace Modules\Taxonomy\Services;

use Core\Database\Database;

/**
 * Taxonomy domain logic — vocabularies ("sets"), nested terms, polymorphic
 * attachment to entities, closure-table maintenance.
 *
 * Usage pattern from other modules:
 *   // Attach terms by slug
 *   $tax = new TaxonomyService();
 *   $term = $tax->findTermBySlug('product-categories', 'electronics');
 *   $tax->attach('product', 42, (int) $term['id']);
 *
 *   // Fetch all terms on an entity
 *   $terms = $tax->termsFor('product', 42);
 *
 *   // Render a full tree for a vocabulary
 *   $tree = $tax->tree('product-categories');
 *
 * Helper functions in Helpers/helpers.php wrap these for views.
 */
class TaxonomyService
{
    public const MAX_DEPTH = 10;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Sets (vocabularies) ───────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function allSets(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM taxonomy_sets ORDER BY name ASC"
        );
    }

    public function findSet(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM taxonomy_sets WHERE id = ?", [$id]);
    }

    public function findSetBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM taxonomy_sets WHERE slug = ?", [$slug]);
    }

    public function createSet(string $name, string $slug, bool $allowHierarchy = true, ?string $description = null): int
    {
        return $this->db->insert('taxonomy_sets', [
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'allow_hierarchy' => $allowHierarchy ? 1 : 0,
        ]);
    }

    public function updateSet(int $id, string $name, string $slug, bool $allowHierarchy, ?string $description): void
    {
        $this->db->update('taxonomy_sets', [
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'allow_hierarchy' => $allowHierarchy ? 1 : 0,
        ], 'id = ?', [$id]);
    }

    /**
     * Delete a set and everything under it. ON DELETE CASCADE on
     * taxonomy_terms handles terms and their closure rows; entity_terms
     * rows are removed by the per-term FK cascade too.
     */
    public function deleteSet(int $id): void
    {
        $this->db->delete('taxonomy_sets', 'id = ?', [$id]);
    }

    // ── Terms ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> terms in set, depth-first by closure */
    public function termsInSet(int $setId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM taxonomy_terms
              WHERE set_id = ?
           ORDER BY parent_id IS NOT NULL, parent_id ASC, sort_order ASC, name ASC",
            [$setId]
        );
    }

    public function findTerm(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM taxonomy_terms WHERE id = ?", [$id]);
    }

    public function findTermBySlug(string $setSlug, string $termSlug): ?array
    {
        return $this->db->fetchOne(
            "SELECT t.*
               FROM taxonomy_terms t
               JOIN taxonomy_sets s ON s.id = t.set_id
              WHERE s.slug = ? AND t.slug = ?",
            [$setSlug, $termSlug]
        );
    }

    /**
     * Insert a new term into a set, optionally under $parentId. Transactional
     * so closure-table updates land atomically with the term insert — a
     * half-written term that isn't in the closure is worse than no term.
     *
     * Throws on invariant violations (cycle, cross-set parent, depth cap).
     */
    public function createTerm(
        int $setId, string $name, string $slug,
        ?int $parentId = null, ?string $description = null, int $sortOrder = 0
    ): int {
        $set = $this->findSet($setId);
        if (!$set) {
            throw new \InvalidArgumentException("Set $setId does not exist.");
        }

        if ($parentId !== null) {
            if ((int) $set['allow_hierarchy'] !== 1) {
                throw new \InvalidArgumentException("Set '{$set['slug']}' does not allow hierarchy — no parent terms.");
            }
            $parent = $this->findTerm($parentId);
            if (!$parent || (int) $parent['set_id'] !== $setId) {
                throw new \InvalidArgumentException("Parent term $parentId is not in set $setId.");
            }
            // Depth guard — parent's depth is the longest path to a root in
            // closure; new term is parent-depth + 1.
            $parentDepth = (int) $this->db->fetchColumn(
                "SELECT COALESCE(MAX(depth), 0) FROM taxonomy_closure WHERE descendant_id = ?",
                [$parentId]
            );
            if ($parentDepth + 1 > self::MAX_DEPTH) {
                throw new \InvalidArgumentException("Max tree depth (" . self::MAX_DEPTH . ") exceeded.");
            }
        }

        return $this->db->transaction(function () use ($setId, $name, $slug, $parentId, $description, $sortOrder) {
            $id = $this->db->insert('taxonomy_terms', [
                'set_id'      => $setId,
                'parent_id'   => $parentId,
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
                'sort_order'  => $sortOrder,
            ]);

            // Closure rows for the new term:
            //   (self, self, 0) — every node is its own 0-depth ancestor
            //   (A, self, depth+1) for every (A, parent, depth) row
            $this->db->query(
                "INSERT INTO taxonomy_closure (ancestor_id, descendant_id, depth)
                 VALUES (?, ?, 0)",
                [$id, $id]
            );
            if ($parentId !== null) {
                $this->db->query(
                    "INSERT INTO taxonomy_closure (ancestor_id, descendant_id, depth)
                     SELECT ancestor_id, ?, depth + 1
                       FROM taxonomy_closure
                      WHERE descendant_id = ?",
                    [$id, $parentId]
                );
            }
            return $id;
        });
    }

    public function updateTerm(int $id, string $name, string $slug, ?string $description, int $sortOrder): void
    {
        // Note: parent_id changes (moves) are NOT supported in MVP — closure
        // maintenance on move is non-trivial and I don't want to ship it
        // half-right. Admins can delete + recreate for now.
        $this->db->update('taxonomy_terms', [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'sort_order'  => $sortOrder,
        ], 'id = ?', [$id]);
    }

    /**
     * Delete a term and its subtree. The FK cascades on parent_id cover
     * the descendant term rows; the closure FK cascades clear the
     * corresponding ancestry rows; the entity_terms FK cascade detaches.
     * Single DELETE, everything else unwinds automatically.
     */
    public function deleteTerm(int $id): void
    {
        $this->db->delete('taxonomy_terms', 'id = ?', [$id]);
    }

    // ── Tree construction ────────────────────────────────────────────────

    /**
     * Return a nested tree for $setSlug. Top-level nodes have parent_id=null;
     * each node has a 'children' key populated recursively.
     *
     * @return array<int, array<string, mixed>>  top-level nodes
     */
    public function tree(string $setSlug): array
    {
        $set = $this->findSetBySlug($setSlug);
        if (!$set) return [];

        $flat = $this->termsInSet((int) $set['id']);
        return $this->buildTree($flat);
    }

    /** Same as tree() but keyed by set id. */
    public function treeBySetId(int $setId): array
    {
        $flat = $this->termsInSet($setId);
        return $this->buildTree($flat);
    }

    /**
     * Flat-to-nested. Orphan children (parent not in set) surface as roots
     * so data stays visible even if something's inconsistent.
     */
    public function buildTree(array $flat): array
    {
        $nodes = [];
        foreach ($flat as $row) {
            $row['children'] = [];
            $nodes[(int) $row['id']] = $row;
        }
        $roots = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
            if ($parentId && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    }

    // ── Ancestors / descendants via closure ──────────────────────────────

    /** @return array<int, array<string, mixed>> — excludes the term itself */
    public function descendantsOf(int $termId): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, c.depth
               FROM taxonomy_terms t
               JOIN taxonomy_closure c ON c.descendant_id = t.id
              WHERE c.ancestor_id = ?
                AND c.depth > 0
           ORDER BY c.depth ASC, t.sort_order ASC, t.name ASC",
            [$termId]
        );
    }

    /** @return array<int, array<string, mixed>> — ancestors in order root-first, excludes the term itself */
    public function ancestorsOf(int $termId): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, c.depth
               FROM taxonomy_terms t
               JOIN taxonomy_closure c ON c.ancestor_id = t.id
              WHERE c.descendant_id = ?
                AND c.depth > 0
           ORDER BY c.depth DESC",
            [$termId]
        );
    }

    // ── Polymorphic attach ──────────────────────────────────────────────

    /** True if the attach succeeded (or already existed). */
    public function attach(string $entityType, int $entityId, int $termId): bool
    {
        if (!$this->findTerm($termId)) return false;
        $this->db->query(
            "INSERT IGNORE INTO taxonomy_entity_terms (term_id, entity_type, entity_id)
             VALUES (?, ?, ?)",
            [$termId, $entityType, $entityId]
        );
        return true;
    }

    public function detach(string $entityType, int $entityId, int $termId): void
    {
        $this->db->delete(
            'taxonomy_entity_terms',
            'term_id = ? AND entity_type = ? AND entity_id = ?',
            [$termId, $entityType, $entityId]
        );
    }

    public function detachAll(string $entityType, int $entityId): void
    {
        $this->db->delete(
            'taxonomy_entity_terms',
            'entity_type = ? AND entity_id = ?',
            [$entityType, $entityId]
        );
    }

    /**
     * Replace all terms on an entity with exactly the given list. More
     * efficient than detachAll + loop-attach when the caller has a
     * definitive final set (e.g. from a form multi-select).
     */
    public function syncTerms(string $entityType, int $entityId, array $termIds): void
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));

        $this->db->transaction(function () use ($entityType, $entityId, $termIds) {
            $this->detachAll($entityType, $entityId);
            foreach ($termIds as $tid) {
                if ($tid > 0) $this->attach($entityType, $entityId, $tid);
            }
        });
    }

    /** @return array<int, array<string, mixed>>  terms attached to the entity */
    public function termsFor(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, s.slug AS set_slug
               FROM taxonomy_entity_terms et
               JOIN taxonomy_terms t ON t.id = et.term_id
               JOIN taxonomy_sets  s ON s.id = t.set_id
              WHERE et.entity_type = ? AND et.entity_id = ?
           ORDER BY s.slug, t.name",
            [$entityType, $entityId]
        );
    }

    /**
     * Entities tagged with a term. Returns an array of (entity_type, entity_id)
     * pairs — caller joins to the appropriate entity table to resolve.
     *
     * @return array<int, array{entity_type:string, entity_id:int}>
     */
    public function entitiesFor(int $termId, int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT entity_type, entity_id
               FROM taxonomy_entity_terms
              WHERE term_id = ?
           ORDER BY created_at DESC
              LIMIT ? OFFSET ?",
            [$termId, $limit, $offset]
        );
    }
}
