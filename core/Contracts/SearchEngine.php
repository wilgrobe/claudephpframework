<?php
// core/Contracts/SearchEngine.php
namespace Core\Contracts;

/**
 * Full-text search engine. Default is MySQL FULLTEXT via SearchService;
 * Meilisearch driver is planned (see infra follow-up queue).
 *
 * Signatures match Core\Services\SearchService exactly so it can
 * `implements SearchEngine` with no behavior change. When Meilisearch
 * lands, its driver matches the same method shapes.
 */
interface SearchEngine
{
    /** True when the engine is configured + reachable. */
    public function isEnabled(): bool;

    /**
     * Query the index for records matching $query.
     *
     * @param string $collection  Logical index name (e.g. 'content_items', 'pages')
     * @param string $query       User-supplied query string; drivers sanitize as needed
     * @param int    $limit       Max results to return
     * @return array<int, array<string, mixed>>  Matching records; shape is driver-specific
     */
    public function search(string $collection, string $query, int $limit = 20): array;

    /** Insert or update a document in the index. Returns true on success. */
    public function indexDocument(string $collection, string $id, array $doc): bool;

    /** Remove a document by its primary key. */
    public function deleteDocument(string $collection, string $id): bool;
}
