<?php
// modules/hierarchies/Services/HierarchyService.php
namespace Modules\Hierarchies\Services;

use Core\Database\Database;

/**
 * Hierarchy CRUD + closure-table maintenance.
 *
 * Closure table shape: for each (ancestor, descendant) relationship we
 * store one row with the depth between them — and crucially, a self-row
 * with depth=0 so a "subtree including X" query is a single WHERE
 * ancestor_id = X. Moves and deletes maintain the closure via explicit
 * inserts and CASCADE on the foreign keys.
 *
 * Public surface:
 *   Hierarchies (trees): all, find, findBySlug, create, update, delete
 *   Nodes:               addNode, updateNode, deleteNode, moveNode,
 *                        reorderSiblings, tree
 */
class HierarchyService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Hierarchies (trees) ───────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function allHierarchies(): array
    {
        return $this->db->fetchAll("SELECT * FROM hierarchies ORDER BY name ASC");
    }

    public function findHierarchy(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM hierarchies WHERE id = ?", [$id]);
    }

    public function findHierarchyBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM hierarchies WHERE slug = ?", [$slug]);
    }

    public function createHierarchy(string $name, string $slug, ?string $description = null): int
    {
        return (int) $this->db->insert('hierarchies', [
            'name'        => $name,
            'slug'        => self::slugify($slug),
            'description' => $description,
            'active'      => 1,
        ]);
    }

    public function updateHierarchy(int $id, array $data): void
    {
        $out = [];
        foreach (['name','slug','description','active'] as $k) {
            if (array_key_exists($k, $data)) $out[$k] = $data[$k];
        }
        if (isset($out['slug'])) $out['slug'] = self::slugify((string) $out['slug']);
        if ($out) $this->db->update('hierarchies', $out, 'id = ?', [$id]);
    }

    public function deleteHierarchy(int $id): void
    {
        // CASCADE wipes nodes + closure.
        $this->db->delete('hierarchies', 'id = ?', [$id]);
    }

    // ── Nodes ─────────────────────────────────────────────────────────────

    public function findNode(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM hierarchy_nodes WHERE id = ?", [$id]);
    }

    /**
     * Add a node under $parentId (null = root of $hierarchyId). Returns
     * the new node id. Maintains closure by pulling the parent's
     * ancestors and appending (self, 0) + (each ancestor, depth+1).
     */
    public function addNode(int $hierarchyId, ?int $parentId, array $fields): int
    {
        if ($parentId !== null) {
            $parent = $this->findNode($parentId);
            if (!$parent || (int) $parent['hierarchy_id'] !== $hierarchyId) {
                throw new \InvalidArgumentException('parent not in hierarchy');
            }
        }

        // Default sort_order to end-of-siblings unless caller set it.
        if (!array_key_exists('sort_order', $fields)) {
            $max = (int) $this->db->fetchColumn(
                "SELECT COALESCE(MAX(sort_order), 0) FROM hierarchy_nodes
                  WHERE hierarchy_id = ? AND " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?"),
                $parentId === null ? [$hierarchyId] : [$hierarchyId, $parentId]
            );
            $fields['sort_order'] = $max + 1;
        }

        $row = array_merge([
            'hierarchy_id' => $hierarchyId,
            'parent_id'    => $parentId,
            'label'        => (string) ($fields['label'] ?? ''),
            'slug'         => self::slugify((string) ($fields['slug'] ?? ($fields['label'] ?? ''))),
            'url'          => $fields['url']           ?? null,
            'icon'         => $fields['icon']          ?? null,
            'color'        => $fields['color']         ?? null,
            'metadata_json'=> $fields['metadata_json'] ?? null,
            'sort_order'   => (int) $fields['sort_order'],
        ]);

        $id = (int) $this->db->insert('hierarchy_nodes', $row);

        // Closure: self-row + ancestors' rows with depth+1
        $this->db->insert('hierarchy_node_closure', [
            'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
        ]);
        if ($parentId !== null) {
            $this->db->query(
                "INSERT INTO hierarchy_node_closure (ancestor_id, descendant_id, depth)
                 SELECT ancestor_id, ?, depth + 1
                   FROM hierarchy_node_closure
                  WHERE descendant_id = ?",
                [$id, $parentId]
            );
        }
        return $id;
    }

    public function updateNode(int $id, array $fields): void
    {
        $out = [];
        foreach (['label','slug','url','icon','color','metadata_json','sort_order'] as $k) {
            if (array_key_exists($k, $fields)) $out[$k] = $fields[$k];
        }
        if (isset($out['slug'])) $out['slug'] = self::slugify((string) $out['slug']);
        if ($out) $this->db->update('hierarchy_nodes', $out, 'id = ?', [$id]);
    }

    /** Delete node + descendants (CASCADE on parent_id + closure FK). */
    public function deleteNode(int $id): void
    {
        // Closure cascade handles itself; the parent_id FK on
        // hierarchy_nodes also CASCADEs, so descendants drop.
        $this->db->delete('hierarchy_nodes', 'id = ?', [$id]);
    }

    /**
     * Move a subtree to a new parent (null = root). Re-parenting rewrites
     * the closure rows for every descendant. Rejects moves that would
     * create a cycle (moving X under one of its own descendants).
     */
    public function moveNode(int $nodeId, ?int $newParentId): void
    {
        $node = $this->findNode($nodeId);
        if (!$node) return;

        if ($newParentId !== null) {
            $newParent = $this->findNode($newParentId);
            if (!$newParent || (int) $newParent['hierarchy_id'] !== (int) $node['hierarchy_id']) {
                throw new \InvalidArgumentException('new parent not in hierarchy');
            }
            // Cycle check: newParent must NOT be a descendant of $nodeId
            $cycle = $this->db->fetchOne(
                "SELECT 1 FROM hierarchy_node_closure
                  WHERE ancestor_id = ? AND descendant_id = ? LIMIT 1",
                [$nodeId, $newParentId]
            );
            if ($cycle) throw new \InvalidArgumentException('cycle');
        }

        // Rewrite closure: for each descendant D of $nodeId:
        //   delete rows (ancestor=X, descendant=D) where X is an ancestor of $nodeId (not including $nodeId)
        //   insert rows (ancestor=Y, descendant=D, depth=Y.depth + (nodeId-to-D depth) + 1) for Y = every ancestor of $newParentId including self
        //
        // We do this by:
        //   1) Delete all (ancestor, descendant) where descendant ∈ subtree(nodeId) AND ancestor ∉ subtree(nodeId)
        //   2) Re-insert with correct depths using the new parent's ancestor chain
        $this->db->query(
            "DELETE c FROM hierarchy_node_closure c
              JOIN hierarchy_node_closure sub ON sub.descendant_id = c.descendant_id
             WHERE sub.ancestor_id = ?
               AND c.ancestor_id NOT IN (
                    SELECT x.descendant_id FROM (
                        SELECT descendant_id FROM hierarchy_node_closure WHERE ancestor_id = ?
                    ) AS x
               )",
            [$nodeId, $nodeId]
        );

        if ($newParentId !== null) {
            $this->db->query(
                "INSERT INTO hierarchy_node_closure (ancestor_id, descendant_id, depth)
                 SELECT super.ancestor_id, sub.descendant_id, super.depth + sub.depth + 1
                   FROM hierarchy_node_closure super
                   JOIN hierarchy_node_closure sub
                     ON super.descendant_id = ?
                    AND sub.ancestor_id     = ?",
                [$newParentId, $nodeId]
            );
        }

        $this->db->update('hierarchy_nodes',
            ['parent_id' => $newParentId],
            'id = ?', [$nodeId]
        );
    }

    /**
     * Rewrite sort_order for a contiguous sibling group. $orderedIds is
     * the desired order; missing ids are ignored, extra ids in the DB
     * are left alone.
     *
     * Drag-and-drop UIs call this after a drop to persist the new order.
     */
    public function reorderSiblings(array $orderedIds): void
    {
        $i = 1;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $this->db->update('hierarchy_nodes', ['sort_order' => $i], 'id = ?', [$id]);
                $i++;
            }
        }
    }

    /**
     * Build a nested tree for a hierarchy. Each node has a `children`
     * array (recursive). Returns [] if the hierarchy has no nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(int $hierarchyId): array
    {
        $nodes = $this->db->fetchAll(
            "SELECT * FROM hierarchy_nodes
              WHERE hierarchy_id = ?
           ORDER BY COALESCE(parent_id, 0) ASC, sort_order ASC, id ASC",
            [$hierarchyId]
        );

        $byId = [];
        foreach ($nodes as $n) {
            $n['children'] = [];
            $byId[(int) $n['id']] = $n;
        }

        $roots = [];
        foreach ($byId as $id => &$n) {
            if ($n['parent_id'] === null) {
                $roots[] = &$byId[$id];
            } else {
                $pid = (int) $n['parent_id'];
                if (isset($byId[$pid])) {
                    $byId[$pid]['children'][] = &$byId[$id];
                }
            }
        }
        unset($n);
        return $roots;
    }

    public function treeBySlug(string $slug): array
    {
        $h = $this->findHierarchyBySlug($slug);
        return $h ? $this->tree((int) $h['id']) : [];
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? '';
        return trim($s, '-');
    }
}
