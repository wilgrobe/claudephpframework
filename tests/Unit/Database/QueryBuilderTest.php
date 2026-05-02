<?php
// tests/Unit/Database/QueryBuilderTest.php
namespace Tests\Unit\Database;

use Core\Database\Database;
use Core\Database\QueryBuilder;
use Core\Database\Raw;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Spy Database subclass — records every call without touching MySQL.
 * Tests assert on the SQL + bindings the builder produces.
 */
final class SpyDb extends Database {
    public array $calls = [];
    public array $cannedRows = [];
    public int   $cannedInsertId = 99;

    public function __construct() { /* bypass parent ctor (no PDO) */ }

    public function fetchAll(string $sql, array $b = []): array { $this->calls[] = ['fetchAll', $sql, $b]; return $this->cannedRows; }
    public function fetchOne(string $sql, array $b = []): ?array { $this->calls[] = ['fetchOne', $sql, $b]; return $this->cannedRows[0] ?? null; }
    public function fetchColumn(string $sql, array $b = [], int $col = 0): mixed { $this->calls[] = ['fetchColumn', $sql, $b]; return $this->cannedRows[0] ?? 0; }
    public function insert(string $t, array $d): int { $this->calls[] = ['insert', $t, $d]; return $this->cannedInsertId; }
    public function update(string $t, array $d, string $w, array $wb = []): int { $this->calls[] = ['update', $t, $d, $w, $wb]; return 1; }
    public function delete(string $t, string $w, array $wb = []): int { $this->calls[] = ['delete', $t, $w, $wb]; return 1; }
    public function paginate(string $s, array $b, int $p, int $pp = 20): array { $this->calls[] = ['paginate', $s, $b, $p, $pp]; return ['items' => $this->cannedRows, 'total' => 0, 'per_page' => $pp, 'current_page' => $p, 'last_page' => 1]; }
}

final class QueryBuilderTest extends TestCase
{
    private SpyDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SpyDb();
    }

    public function test_basic_select_compiles_without_where(): void
    {
        $this->db->table('users')->get();
        $this->assertSame('SELECT * FROM `users`', $this->db->calls[0][1]);
        $this->assertSame([], $this->db->calls[0][2]);
    }

    public function test_where_two_arg_form_defaults_operator_to_equals(): void
    {
        $this->db->table('users')->where('id', 42)->get();
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ?', $this->db->calls[0][1]);
        $this->assertSame([42], $this->db->calls[0][2]);
    }

    public function test_where_three_arg_form_honors_operator(): void
    {
        $this->db->table('users')->where('age', '>', 18)->get();
        $this->assertSame('SELECT * FROM `users` WHERE `age` > ?', $this->db->calls[0][1]);
    }

    public function test_chained_where_and_orWhere(): void
    {
        $this->db->table('users')->where('is_active', 1)->orWhere('is_admin', 1)->get();
        $this->assertStringContainsString('`is_active` = ? OR `is_admin` = ?', $this->db->calls[0][1]);
    }

    public function test_whereIn_with_empty_list_compiles_to_always_false(): void
    {
        $this->db->table('content')->whereIn('id', [])->get();
        $this->assertSame('SELECT * FROM `content` WHERE 0', $this->db->calls[0][1]);
    }

    public function test_whereNotIn_with_empty_list_compiles_to_always_true(): void
    {
        $this->db->table('content')->whereNotIn('id', [])->get();
        $this->assertSame('SELECT * FROM `content` WHERE 1', $this->db->calls[0][1]);
    }

    public function test_whereRaw_passes_sql_and_bindings_through(): void
    {
        $this->db->table('c')
            ->whereRaw('MATCH(b) AGAINST(?)', ['x*'])
            ->where('status', 'published')
            ->get();
        $this->assertStringContainsString('MATCH(b) AGAINST(?)', $this->db->calls[0][1]);
        $this->assertSame(['x*', 'published'], $this->db->calls[0][2]);
    }

    public function test_orderBy_limit_offset(): void
    {
        $this->db->table('users')->orderBy('created_at', 'desc')->limit(5)->offset(10)->get();
        $this->assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC LIMIT 5 OFFSET 10', $this->db->calls[0][1]);
    }

    public function test_unsafe_identifier_is_rejected_at_build_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('users; DROP TABLE users--');
    }

    public function test_unsafe_column_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('users')->where('email; --', 'x');
    }

    public function test_invalid_operator_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('users')->where('email', 'INJECT', 'x');
    }

    public function test_update_without_where_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->table('users')->update(['name' => 'x']);
    }

    public function test_delete_without_where_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->table('users')->delete();
    }

    public function test_raw_expression_passes_through_in_orderBy(): void
    {
        $this->db->table('i')->orderBy(new Raw('RAND()'))->get();
        $this->assertStringContainsString('ORDER BY RAND() ASC', $this->db->calls[0][1]);
    }
}
