<?php
// tests/Unit/Queue/IndexDocumentJobTest.php
namespace Tests\Unit\Queue;

use Core\Queue\Jobs\DeleteDocumentJob;
use Core\Queue\Jobs\IndexDocumentJob;
use Core\Services\SearchService;
use Tests\TestCase;

/**
 * SearchService test double. Skips parent ctor (which touches DB + config),
 * records each call, and lets tests control the return values + enabled flag.
 */
final class FakeSearchService extends SearchService
{
    public bool $enabled = true;
    public bool $indexReturns = true;
    public bool $deleteReturns = true;
    public array $indexCalls = [];
    public array $deleteCalls = [];

    public function __construct() { /* skip parent */ }

    public function isEnabled(): bool { return $this->enabled; }

    public function indexDocument(string $collection, string $id, array $doc): bool
    {
        $this->indexCalls[] = compact('collection', 'id', 'doc');
        return $this->indexReturns;
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        $this->deleteCalls[] = compact('collection', 'id');
        return $this->deleteReturns;
    }
}

final class IndexDocumentJobTest extends TestCase
{
    private FakeSearchService $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeSearchService();
        $c = $this->bootContainer();
        $c->instance(SearchService::class, $this->fake);
    }

    public function test_index_job_delegates_to_search_service(): void
    {
        $job = new IndexDocumentJob();
        $job->collection = 'content';
        $job->documentId = '42';
        $job->doc        = ['title' => 'x'];
        $job->handle();

        $this->assertCount(1, $this->fake->indexCalls);
        $this->assertSame('content', $this->fake->indexCalls[0]['collection']);
        $this->assertSame('42',      $this->fake->indexCalls[0]['id']);
        $this->assertSame(['title' => 'x'], $this->fake->indexCalls[0]['doc']);
    }

    public function test_index_job_throws_when_indexDocument_fails_with_provider_enabled(): void
    {
        $this->fake->indexReturns = false;
        $this->fake->enabled = true;

        $job = new IndexDocumentJob();
        $job->collection = 'content';
        $job->documentId = '42';
        $job->doc        = [];

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    public function test_index_job_swallows_failure_when_provider_disabled(): void
    {
        $this->fake->indexReturns = false;
        $this->fake->enabled = false;

        $job = new IndexDocumentJob();
        $job->collection = 'content';
        $job->documentId = '42';
        $job->doc        = [];

        // Must NOT throw — the job shouldn't churn the queue when the admin
        // has turned search off.
        $job->handle();
        $this->addToAssertionCount(1);
    }

    public function test_delete_job_delegates_to_search_service(): void
    {
        $job = new DeleteDocumentJob();
        $job->collection = 'pages';
        $job->documentId = '7';
        $job->handle();

        $this->assertCount(1, $this->fake->deleteCalls);
        $this->assertSame('pages', $this->fake->deleteCalls[0]['collection']);
        $this->assertSame('7',     $this->fake->deleteCalls[0]['id']);
    }

    public function test_delete_job_throws_when_deleteDocument_fails_with_provider_enabled(): void
    {
        $this->fake->deleteReturns = false;
        $this->fake->enabled = true;

        $job = new DeleteDocumentJob();
        $job->collection = 'pages';
        $job->documentId = '7';

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }
}
