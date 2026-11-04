<?php
// tests/Unit/Database/IdentifierGuardTest.php
namespace Tests\Unit\Database;

use Core\Database\Database;
use Tests\TestCase;

/**
 * Database::insert / insertMany / update / delete put the table and column names into SQL inside backticks, so a
 * controller passing request keys as column names would be SQL injection if a name could carry a backtick. The guard
 * (assertSafeIdentifier) refuses anything but [A-Za-z0-9_] before any SQL is built. It had no test (#466 profile).
 * The guard runs before the query, so no connection is needed: an instance made without its constructor is enough.
 */
final class IdentifierGuardTest extends TestCase
{
    private function db(): Database
    {
        return (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, array{0:string}> */
    public static function badNames(): array
    {
        return [
            'backtick break-out'   => ['name` = 1; DROP TABLE users; -- '],
            'statement terminator' => ['users;DROP TABLE users'],
            'space'                => ['first name'],
            'comment'              => ['users--'],
            'quote'                => ["o'neil"],
            'dot (schema.table)'   => ['mysql.user'],
            'parenthesis'          => ['sleep(5)'],
            'empty'                => [''],
            'unicode lookalike'    => ['usеrs'],   // Cyrillic е
            'newline'              => ["users\n"],
        ];
    }

    /** @dataProvider badNames */
    public function test_insert_refuses_a_bad_table_or_column_name(string $bad): void
    {
        foreach ([fn () => $this->db()->insert($bad, ['a' => 1]), fn () => $this->db()->insert('users', [$bad => 1])] as $call) {
            try { $call(); $this->fail('accepted: ' . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Unsafe SQL identifier', $e->getMessage()); }
        }
    }

    /** @dataProvider badNames */
    public function test_insert_many_update_and_delete_refuse_it_too(string $bad): void
    {
        $calls = [
            'insertMany table'  => fn () => $this->db()->insertMany($bad, [['a' => 1]]),
            'insertMany column' => fn () => $this->db()->insertMany('users', [[$bad => 1]]),
            'update table'      => fn () => $this->db()->update($bad, ['a' => 1], 'id = ?', [1]),
            'update column'     => fn () => $this->db()->update('users', [$bad => 1], 'id = ?', [1]),
            'delete table'      => fn () => $this->db()->delete($bad, 'id = ?', [1]),
        ];
        foreach ($calls as $label => $call) {
            try { $call(); $this->fail("$label accepted " . json_encode($bad)); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Unsafe SQL identifier', $e->getMessage(), $label); }
        }
    }

    /**
     * `$` in a PCRE pattern also matches just before a trailing "\n": both guards accepted "users\n" until the D
     * modifier was added (found by this test, 4 Nov 2026). Not exploitable — a newline cannot leave the backticks —
     * but a guard should mean exactly what it says.
     */
    public function test_a_trailing_newline_is_refused_by_both_guards(): void
    {
        $qb = (new \ReflectionClass(\Core\Database\QueryBuilder::class))->newInstanceWithoutConstructor();
        foreach ([[$this->db(), 'assertSafeIdentifier'], [$qb, 'assertIdent']] as [$obj, $method]) {
            try { (new \ReflectionMethod($obj, $method))->invoke($obj, "users\n"); $this->fail("$method accepted a trailing newline"); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_ordinary_names_pass_the_guard(): void
    {
        $guard = new \ReflectionMethod(Database::class, 'assertSafeIdentifier');
        foreach (['users', 'user_groups', 'Groups2', 'a', '_private'] as $ok) {
            $guard->invoke($this->db(), $ok);
        }
        $this->addToAssertionCount(5);
    }
}
