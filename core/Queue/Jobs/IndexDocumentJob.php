<?php
// core/Queue/Jobs/IndexDocumentJob.php
namespace Core\Queue\Jobs;

use Core\Queue\Job;
use Core\Services\SearchService;

/**
 * Push a single document to the hosted search index.
 *
 * Dispatched by SearchIndexer whenever a content/page/faq row is saved.
 * handle() resolves SearchService at run time so the job picks up current
 * provider config even if it changes between enqueue and drain.
 *
 * indexDocument() no-ops when search is disabled — useful if the provider
 * is flipped off between enqueue and drain.
 */
class IndexDocumentJob extends Job
{
    public string $collection = '';
    public string $documentId = '';
    public array  $doc        = [];

    public function handle(): void
    {
        /** @var SearchService $svc */
        $svc = app(SearchService::class);
        if (!$svc->indexDocument($this->collection, $this->documentId, $this->doc)) {
            // indexDocument returns false for both "provider disabled" (fine)
            // and "HTTP call failed" (transient). We can't distinguish from
            // here without a richer return type; the safer default is to
            // surface the failure so backoff retries pick it up. If the
            // provider is disabled at run time, throw a distinct exception
            // that short-circuits to success so we don't churn the queue.
            if (!$svc->isEnabled()) {
                return; // provider was turned off — drop silently.
            }
            throw new \RuntimeException(
                "indexDocument({$this->collection}, {$this->documentId}) failed"
            );
        }
    }
}
