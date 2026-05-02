<?php
// tests/Unit/Modules/Taxonomy/TaxonomyServiceTest.php
namespace Tests\Unit\Modules\Taxonomy;

use Core\Database\Database;
use Modules\Taxonomy\Services\TaxonomyService;
use Tests\TestCase;

/**
 * Unit tests for TaxonomyService — focus on the logic we can exercise
 * without a live DB:
 *   - buildTree: flat-list → nested, orphan handling
 *   - createTerm invariants: parent in different set / flat-set parent /
 *     depth cap
 *
 * Closure-table correctness under real SQL (cascading deletes, ancestor
 * queries) is integration-territory — exercised on Will's MySQL instance
 * rather than here.
 */

/** Minimal DB double returning preset rows for fetchOne / fetchColumn. */
final class FakeTaxonomyDb extends Database
{
    /** fetchOne lookup keyed by "table|col|val". */
    public array $rows = [];
    public array $cols = [];
    public int $nextId = 1;

    public function __construct() { /* skip parent */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // findSet(int)
        if (str_contains($sql, 'FROM taxonomy_sets WHERE id = ?')) {
            $id = (int) $bindings[0];
            return $this->rows["taxonomy_sets|id|$id"] ?? null;
        }
        // findSetBySlug(string)
        if (str_contains($sql, 'FROM taxonomy_sets WHERE slug = ?')) {
            $slug = (string) $bindings[0];
            return $this->rows["taxonomy_sets|slug|$slug"] ?? null;
        }
        // findTerm(int)
        if (str_contains($sql, 'FROM taxonomy_terms WHERE id = ?')) {
            $id = (int) $bindings[0];
            return $this->rows["taxonomy_terms|id|$id"] ?? null;
        }
        return null;
    }

    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed
    {
        // MAX(depth) for parent — used by createTerm's depth guard
        if (str_contains($sql, 'MAX(depth)')) {
            $pid = (int) $bindings[0];
            return $this->cols["max_depth|$pid"] ?? 0;
        }
        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array { return []; }
    public function insert(string $table, array $data): int { return $this->nextId++; }
    public function query(string $sql, array $bindings = []): \PDOStatement { return new class extends \PDOStatement { public function rowCount(): int { return 1; } }; }
    public function transaction(callable $fn): mixed { return $fn($this); }
}

final class TaxonomyServiceTest extends TestCase
{
    private FakeTaxonomyDb $db;
    private TaxonomyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeTaxonomyDb();
        $this->mockDatabase($this->db);
        $this->svc = new TaxonomyService();
    }

    // ── buildTree ─────────────────────────────────────────────────────────

    public function test_buildTree_all_roots_when_no_parents(): void
    {
        $tree = $this->svc->buildTree([
            ['id' => 1, 'parent_id' => null, 'name' => 'A'],
            ['id' => 2, 'parent_id' => null, 'name' => 'B'],
            ['id' => 3, 'parent_id' => null, 'name' => 'C'],
        ]);
        $this->assertCount(3, $tree);
        foreach ($tree as $n) {
            $this->assertSame([], $n['children']);
        }
    }

    public function test_buildTree_nests_children(): void
    {
        $tree = $this->svc->buildTree([
            ['id' => 1, 'parent_id' => null, 'name' => 'Electronics'],
            ['id' => 2, 'parent_id' => 1,    'name' => 'Phones'],
            ['id' => 3, 'parent_id' => 2,    'name' => 'Smartphones'],
            ['id' => 4, 'parent_id' => 1,    'name' => 'Laptops'],
            ['id' => 5, 'parent_id' => null, 'name' => 'Clothing'],
        ]);

        $this->assertCount(2, $tree);
        $this->assertSame('Electronics', $tree[0]['name']);
        $this->assertCount(2, $tree[0]['children']);
        $phones = $tree[0]['children'][0];
        $this->assertSame('Phones', $phones['name']);
        $this->assertCount(1, $phones['children']);
        $this->assertSame('Smartphones', $phones['children'][0]['name']);
    }

    public function test_buildTree_surfaces_orphan_as_root(): void
    {
        // parent_id=99 is missing from the dataset.
        $tree = $this->svc->buildTree([
            ['id' => 1, 'parent_id' => null, 'name' => 'Known'],
            ['id' => 2, 'parent_id' => 99,   'name' => 'Orphan'],
        ]);
        $this->assertCount(2, $tree);
        $names = array_column($tree, 'name');
        $this->assertContains('Orphan', $names);
    }

    // ── createTerm invariants ─────────────────────────────────────────────

    public function test_createTerm_rejects_unknown_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Set 42 does not exist');
        $this->svc->createTerm(42, 'Thing', 'thing');
    }

    public function test_createTerm_rejects_parent_in_flat_set(): void
    {
        $this->db->rows['taxonomy_sets|id|1']   = [
            'id' => 1, 'slug' => 'tags', 'allow_hierarchy' => 0,
        ];
        $this->db->rows['taxonomy_terms|id|10'] = [
            'id' => 10, 'set_id' => 1, 'parent_id' => null, 'name' => 'A',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not allow hierarchy');
        $this->svc->createTerm(1, 'Child', 'child', parentId: 10);
    }

    public function test_createTerm_rejects_parent_in_different_set(): void
    {
        $this->db->rows['taxonomy_sets|id|1']   = [
            'id' => 1, 'slug' => 'a', 'allow_hierarchy' => 1,
        ];
        // parent lives in set 2, not set 1
        $this->db->rows['taxonomy_terms|id|10'] = [
            'id' => 10, 'set_id' => 2, 'parent_id' => null, 'name' => 'OtherSet',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not in set 1');
        $this->svc->createTerm(1, 'X', 'x', parentId: 10);
    }

    public function test_createTerm_rejects_depth_cap_exceeded(): void
    {
        // MAX_DEPTH = 10; simulate parent already at depth 10 so child would be 11.
        $this->db->rows['taxonomy_sets|id|1']   = [
            'id' => 1, 'slug' => 'deep', 'allow_hierarchy' => 1,
        ];
        $this->db->rows['taxonomy_terms|id|100'] = [
            'id' => 100, 'set_id' => 1, 'parent_id' => null, 'name' => 'DeepParent',
        ];
        $this->db->cols['max_depth|100'] = 10;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max tree depth');
        $this->svc->createTerm(1, 'Child', 'child', parentId: 100);
    }

    public function test_createTerm_happy_path_at_root(): void
    {
        $this->db->rows['taxonomy_sets|id|1'] = [
            'id' => 1, 'slug' => 'cats', 'allow_hierarchy' => 1,
        ];
        $id = $this->svc->createTerm(1, 'Electronics', 'electronics');
        $this->assertGreaterThan(0, $id);
    }

    public function test_createTerm_under_valid_parent_in_same_set(): void
    {
        $this->db->rows['taxonomy_sets|id|1']  = [
            'id' => 1, 'slug' => 'cats', 'allow_hierarchy' => 1,
        ];
        $this->db->rows['taxonomy_terms|id|5'] = [
            'id' => 5, 'set_id' => 1, 'parent_id' => null, 'name' => 'Electronics',
        ];
        $this->db->cols['max_depth|5'] = 0;

        $id = $this->svc->createTerm(1, 'Phones', 'phones', parentId: 5);
        $this->assertGreaterThan(0, $id);
    }
}
