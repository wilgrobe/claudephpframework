<?php
// core/Queue/Jobs/DeleteDocumentJob.php
namespace Core\Queue\Jobs;

use Core\Queue\Job;
use Core\Services\SearchService;

/**
 * Remove a single document from the hosted search index.
 *
 * Dispatched by SearchIndexer::sync() when a row disappears or loses
 * visibility (e.g. status moved from 'published' to 'draft'). The same
 * provider-disabled short-circuit as IndexDocumentJob applies.
 */
class DeleteDocumentJob extends Job
{
    public string $collection = '';
    public string $documentId = '';

    public function handle(): void
    {
        /** @var SearchService $svc */
        $svc = app(SearchService::class);
        if (!$svc->deleteDocument($this->collection, $this->documentId)) {
            if (!$svc->isEnabled()) {
                return;
            }
            throw new \RuntimeException(
                "deleteDocument({$this->collection}, {$this->documentId}) failed"
            );
        }
    }
}
