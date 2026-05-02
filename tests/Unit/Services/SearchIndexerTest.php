<?php
// tests/Unit/Services/SearchIndexerTest.php
namespace Tests\Unit\Services;

use Core\Contracts\SearchEngine;
use Core\Database\Database;
use Core\Queue\DatabaseQueue;
use Core\Queue\Job;
use Core\Queue\Jobs\DeleteDocumentJob;
use Core\Queue\Jobs\IndexDocumentJob;
use Core\Services\SearchIndexer;
use Tests\TestCase;

/** Minimal SearchEngine double. `enabled` drives sync()'s no-op branch. */
final class FakeSearchEngine implements SearchEngine
{
    public function __construct(public bool $enabled = true) {}
    public function isEnabled(): bool { return $this->enabled; }
    public function search(string $collection, string $query, int $limit = 20): array { return []; }
    public function indexDocument(string $collection, string $id, array $doc): bool { return true; }
    public function deleteDocument(string $collection, string $id): bool { return true; }
}

/** Database double — returns preset rows from fetchOne, ignores everything else. */
final class FakeIndexerDb extends Database
{
    /** @var array<string, array|null> keyed by "$table:$id" */
    public array $rows = [];

    public function __construct() { /* skip parent */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        foreach ([
            'content_items' => 'content',
            'pages'         => 'pages',
            'faqs'          => 'faqs',
        ] as $table => $_) {
            if (str_contains($sql, $table)) {
                $id = (int) ($bindings[0] ?? 0);
                return $this->rows["$table:$id"] ?? null;
            }
        }
        return null;
    }
}

/** Queue double — records push() calls. */
final class FakeIndexerQueue extends DatabaseQueue
{
    /** @var Job[] */
    public array $pushed = [];

    public function __construct() { /* skip parent */ }

    public function push(Job $job, ?\DateTimeImmutable $availableAt = null): int
    {
        $this->pushed[] = $job;
        return count($this->pushed);
    }
}

final class SearchIndexerTest extends TestCase
{
    public function test_sync_content_published_enqueues_index_job(): void
    {
        $queue  = new FakeIndexerQueue();
        $db     = new FakeIndexerDb();
        $db->rows['content_items:42'] = [
            'id' => 42, 'title' => 'Hello', 'slug' => 'hello',
            'body' => '<p>World</p>', 'type' => 'article',
            'status' => 'published', 'published_at' => '2026-04-22 10:00:00',
        ];

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('content', 42);

        $this->assertCount(1, $queue->pushed);
        /** @var IndexDocumentJob $job */
        $job = $queue->pushed[0];
        $this->assertInstanceOf(IndexDocumentJob::class, $job);
        $this->assertSame('content', $job->collection);
        $this->assertSame('42',     $job->documentId);
        $this->assertSame('Hello',  $job->doc['title']);
        // HTML stripped from body.
        $this->assertSame('World',  $job->doc['body']);
        $this->assertSame('2026-04-22 10:00:00', $job->doc['published_at']);
    }

    public function test_sync_content_draft_enqueues_delete_job(): void
    {
        $queue  = new FakeIndexerQueue();
        $db     = new FakeIndexerDb();
        $db->rows['content_items:42'] = [
            'id' => 42, 'title' => 'Draft', 'slug' => 'draft',
            'body' => '', 'type' => 'article',
            'status' => 'draft', 'published_at' => null,
        ];

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('content', 42);

        $this->assertCount(1, $queue->pushed);
        $this->assertInstanceOf(DeleteDocumentJob::class, $queue->pushed[0]);
        $this->assertSame('content', $queue->pushed[0]->collection);
        $this->assertSame('42',      $queue->pushed[0]->documentId);
    }

    public function test_sync_deleted_row_enqueues_delete_job(): void
    {
        $queue = new FakeIndexerQueue();
        $db    = new FakeIndexerDb(); // no rows preset => fetchOne returns null

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('content', 99);

        $this->assertCount(1, $queue->pushed);
        $this->assertInstanceOf(DeleteDocumentJob::class, $queue->pushed[0]);
    }

    public function test_sync_is_noop_when_search_disabled(): void
    {
        $queue = new FakeIndexerQueue();
        $db    = new FakeIndexerDb();
        $db->rows['content_items:42'] = [
            'id' => 42, 'title' => 'x', 'slug' => 'x',
            'body' => '', 'type' => 'article',
            'status' => 'published', 'published_at' => null,
        ];

        (new SearchIndexer($queue, $db, new FakeSearchEngine(false)))->sync('content', 42);

        $this->assertSame([], $queue->pushed, 'Disabled search must not enqueue any jobs');
    }

    public function test_sync_page_invisible_enqueues_delete(): void
    {
        $queue = new FakeIndexerQueue();
        $db    = new FakeIndexerDb();
        // Published but is_public=0 => not visible publicly.
        $db->rows['pages:7'] = [
            'id' => 7, 'title' => 'Internal', 'slug' => 'internal',
            'body' => '', 'status' => 'published', 'is_public' => 0,
        ];

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('pages', 7);

        $this->assertCount(1, $queue->pushed);
        $this->assertInstanceOf(DeleteDocumentJob::class, $queue->pushed[0]);
    }

    public function test_sync_faq_public_active_enqueues_index(): void
    {
        $queue = new FakeIndexerQueue();
        $db    = new FakeIndexerDb();
        $db->rows['faqs:3'] = [
            'id' => 3, 'question' => 'Why?', 'answer' => '<b>Because</b>',
            'is_public' => 1, 'is_active' => 1,
        ];

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('faqs', 3);

        $this->assertCount(1, $queue->pushed);
        /** @var IndexDocumentJob $job */
        $job = $queue->pushed[0];
        $this->assertInstanceOf(IndexDocumentJob::class, $job);
        $this->assertSame('faqs', $job->collection);
        $this->assertSame('3',    $job->documentId);
        $this->assertSame('Why?', $job->doc['question']);
        $this->assertSame('Because', $job->doc['answer']);
    }

    public function test_unknown_collection_enqueues_delete_safely(): void
    {
        // An unknown collection means "can't build a doc" — behavior mirrors
        // a not-found row: enqueue a delete so stale docs get purged.
        $queue = new FakeIndexerQueue();
        $db    = new FakeIndexerDb();

        (new SearchIndexer($queue, $db, new FakeSearchEngine(true)))->sync('bogus', 1);

        $this->assertCount(1, $queue->pushed);
        $this->assertInstanceOf(DeleteDocumentJob::class, $queue->pushed[0]);
    }
}
