<?php
// core/Services/SearchIndexer.php
namespace Core\Services;

use Core\Contracts\SearchEngine;
use Core\Database\Database;
use Core\Queue\DatabaseQueue;
use Core\Queue\Jobs\DeleteDocumentJob;
use Core\Queue\Jobs\IndexDocumentJob;

/**
 * SearchIndexer — bridges content-save lifecycle to the search index via
 * queued jobs.
 *
 * Controllers call sync() AFTER a row is inserted/updated/deleted. The
 * indexer fetches the current state of the row:
 *   - Row exists and is publicly visible  → enqueue IndexDocumentJob
 *   - Row is gone or no longer visible    → enqueue DeleteDocumentJob
 *
 * This keeps visibility rules (status='published', is_public=1, is_active=1)
 * in ONE place — if they ever change, only SearchIndexer needs to know.
 *
 * When SEARCH_PROVIDER is disabled (driver=none) sync() is a no-op so
 * installations that don't use hosted search don't fill the jobs table
 * with work that has nowhere to run.
 */
class SearchIndexer
{
    public function __construct(
        private DatabaseQueue $queue,
        private Database $db,
        private SearchEngine $search,
    ) {}

    /**
     * Resync a single row. Safe to call regardless of what just happened
     * to the row (insert/update/delete) — the indexer reads the current
     * state to decide the action.
     *
     * Perf tip: controllers that just finished a successful insert/update
     * already have the full row in memory. Pass it as $prefetchedRow to skip
     * the redundant fetchOne. For deletes, omit it — the null-row path
     * correctly enqueues a delete job. Example:
     *
     *   $id = $this->db->insert('content_items', $data);
     *   app(SearchIndexer::class)->sync('content', $id, $data + ['id' => $id, 'status' => $status]);
     */
    public function sync(string $collection, int $id, ?array $prefetchedRow = null): void
    {
        if (!$this->search->isEnabled()) return;

        $doc = match ($collection) {
            'content' => $this->buildContentDoc($id, $prefetchedRow),
            'pages'   => $this->buildPageDoc($id, $prefetchedRow),
            'faqs'    => $this->buildFaqDoc($id, $prefetchedRow),
            default   => null,
        };

        if ($doc === null) {
            // Row is gone or not visible — make sure it isn't left in the index.
            $this->queueDelete($collection, (string) $id);
            return;
        }

        $this->queueIndex($collection, (string) $id, $doc);
    }

    /** Explicit enqueue helpers — exposed for callers that have a pre-built doc. */
    public function queueIndex(string $collection, string $id, array $doc): void
    {
        if (!$this->search->isEnabled()) return;
        $job = new IndexDocumentJob();
        $job->collection = $collection;
        $job->documentId = $id;
        $job->doc        = $doc;
        $this->queue->push($job);
    }

    public function queueDelete(string $collection, string $id): void
    {
        if (!$this->search->isEnabled()) return;
        $job = new DeleteDocumentJob();
        $job->collection = $collection;
        $job->documentId = $id;
        $this->queue->push($job);
    }

    // ── Collection-specific doc builders. Mirror SearchService::reindex*(). ──

    private function buildContentDoc(int $id, ?array $prefetched = null): ?array
    {
        $row = $prefetched ?? $this->db->fetchOne(
            "SELECT id, title, slug, body, type, status, published_at
               FROM content_items WHERE id = ?",
            [$id]
        );
        if (!$row || ($row['status'] ?? null) !== 'published') return null;
        return [
            'title'        => $row['title']        ?? '',
            'slug'         => $row['slug']         ?? '',
            'body'         => strip_tags((string) ($row['body'] ?? '')),
            'type'         => $row['type']         ?? '',
            'published_at' => $row['published_at'] ?? null,
        ];
    }

    private function buildPageDoc(int $id, ?array $prefetched = null): ?array
    {
        $row = $prefetched ?? $this->db->fetchOne(
            "SELECT id, title, slug, body, status, is_public FROM pages WHERE id = ?",
            [$id]
        );
        if (!$row
            || ($row['status'] ?? null) !== 'published'
            || (int) ($row['is_public'] ?? 0) !== 1
        ) return null;
        return [
            'title' => $row['title'] ?? '',
            'slug'  => $row['slug']  ?? '',
            'body'  => strip_tags((string) ($row['body'] ?? '')),
        ];
    }

    private function buildFaqDoc(int $id, ?array $prefetched = null): ?array
    {
        $row = $prefetched ?? $this->db->fetchOne(
            "SELECT id, question, answer, is_public, is_active FROM faqs WHERE id = ?",
            [$id]
        );
        if (!$row
            || (int) ($row['is_public'] ?? 0) !== 1
            || (int) ($row['is_active'] ?? 0) !== 1
        ) return null;
        return [
            'id'       => (int) ($row['id'] ?? $id),
            'question' => $row['question'] ?? '',
            'answer'   => strip_tags((string) ($row['answer'] ?? '')),
        ];
    }
}
