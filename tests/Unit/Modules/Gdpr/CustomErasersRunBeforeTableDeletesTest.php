<?php
// tests/Unit/Modules/Gdpr/CustomErasersRunBeforeTableDeletesTest.php
namespace Tests\Unit\Modules\Gdpr;

use Core\Database\Database;
use Modules\Gdpr\Services\DataPurger;
use Modules\Gdpr\Services\GdprHandler;
use Modules\Gdpr\Services\GdprRegistry;
use Tests\TestCase;

/**
 * A custom erase handler runs before ANY declared table is deleted.
 *
 * 2026-10-02, StoriesDen: bookshelf declares `cp_books` (erase by user_id) and
 * every book-scoped module erases through a custom handler that finds the
 * user's books first. Handlers ran in module order, so bookshelf deleted the
 * books and every module after it alphabetically found nothing — an erasure
 * removed the books, kept every chapter, scene and plan, and reported success.
 */
final class FakePurgeDb extends Database
{
    /** @var list<string> */
    public array $log = [];

    public function __construct() {}
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function fetchOne(string $sql, array $bindings = []): ?array { return null; }
    public function fetchAll(string $sql, array $bindings = []): array { return []; }
    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed { return null; }
    public function insert(string $table, array $data): int { $this->log[] = "INSERT $table"; return 1; }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $this->log[] = trim((string) preg_replace('/\s+/', ' ', $sql));
        return (new \ReflectionClass(\PDOStatement::class))->newInstanceWithoutConstructor();
    }
}

final class FixedRegistry extends GdprRegistry
{
    /** @param GdprHandler[] $handlers */
    public function __construct(private array $handlers) {}
    public function all(): array { return $this->handlers; }
}

final class CustomErasersRunBeforeTableDeletesTest extends TestCase
{
    public function test_a_handler_after_the_parent_table_still_sees_the_parent(): void
    {
        $db = new FakePurgeDb();
        $seenAtErase = null;
        $handlers = [
            // Module order: bookshelf (owns the parent) comes BEFORE the module whose rows hang off it.
            new GdprHandler('bookshelf', 'books', [
                ['table' => 'cp_books', 'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
            ]),
            new GdprHandler('story-planner', 'plans', [], null,
                static function (int $userId, string $marker) use ($db, &$seenAtErase): void {
                    $seenAtErase = $db->log;
                    $db->query('DELETE FROM sp_outline WHERE book_id IN (1)');
                }),
        ];

        $stats = (new DataPurger($db, new FixedRegistry($handlers)))->purge(7, 1);

        $this->assertIsArray($seenAtErase, 'The custom erase handler never ran.');
        foreach ($seenAtErase as $q) {
            $this->assertStringNotContainsString('DELETE FROM `cp_books`', $q,
                'The parent table was deleted before the handler that finds rows through it ran.');
        }
        $books = array_keys(array_filter($db->log, static fn ($q) => str_contains($q, 'DELETE FROM `cp_books`')));
        $plans = array_keys(array_filter($db->log, static fn ($q) => str_contains($q, 'DELETE FROM sp_outline')));
        $this->assertCount(1, $books, 'The declared table must still be erased.');
        $this->assertCount(1, $plans);
        $this->assertLessThan($books[0], $plans[0]);
        $this->assertSame(1, $stats['custom_handlers']);
        $this->assertSame(1, $stats['tables_erased']);
    }

    public function test_a_throwing_custom_handler_still_aborts_the_purge(): void
    {
        $db = new FakePurgeDb();
        $handlers = [
            new GdprHandler('bookshelf', 'books', [
                ['table' => 'cp_books', 'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ERASE],
            ]),
            new GdprHandler('story-planner', 'plans', [], null,
                static function (): void { throw new \RuntimeException('table went away'); }),
        ];

        try {
            (new DataPurger($db, new FixedRegistry($handlers)))->purge(7, 1);
            $this->fail('A failed custom erase must not be reported as a completed purge.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("module 'story-planner'", $e->getMessage());
        }
        foreach ($db->log as $q) {
            $this->assertStringNotContainsString('UPDATE users', $q, 'The user row was scrubbed after a failed erase.');
        }
    }
}
