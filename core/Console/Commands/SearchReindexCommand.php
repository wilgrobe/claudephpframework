<?php
// core/Console/Commands/SearchReindexCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Services\SearchService;

/**
 * search:reindex — push the corpus to the configured search provider.
 *
 *   php artisan search:reindex            # all collections
 *   php artisan search:reindex content
 *   php artisan search:reindex pages
 *   php artisan search:reindex faqs
 *
 * Safe to call on a live site: hosted providers (Meilisearch/Algolia/
 * OpenSearch) accept upserts without rebuilding the whole index, and
 * SearchService no-ops gracefully when SEARCH_PROVIDER=none.
 *
 * Wired into the nightly scheduled_tasks row so drift between the local
 * tables and the hosted index can't grow unbounded.
 */
class SearchReindexCommand extends Command
{
    public function name(): string        { return 'search:reindex'; }
    public function description(): string { return 'Reindex content, pages, or faqs (default: all) against the configured search provider'; }
    public function usage(): string       { return 'php artisan search:reindex [content|pages|faqs|all]'; }

    public function handle(array $argv): int
    {
        $target = strtolower($this->arg($argv, 2, 'all') ?? 'all');

        $valid = ['content', 'pages', 'faqs', 'all'];
        if (!in_array($target, $valid, true)) {
            $this->error("Invalid target: $target (valid: " . implode(', ', $valid) . ')');
            return 1;
        }

        /** @var SearchService $svc */
        $svc = app(SearchService::class);

        if (!$svc->isEnabled()) {
            $this->warn('SEARCH_PROVIDER is not configured — nothing to reindex.');
            $this->line('Set SEARCH_PROVIDER and provider credentials in .env, then re-run.');
            return 0;
        }

        $this->line('[' . date('Y-m-d H:i:s') . '] Reindexing (' . $svc->provider() . "): $target");

        $totals = [];
        if ($target === 'content' || $target === 'all') {
            $totals['content'] = $svc->reindexContent();
        }
        if ($target === 'pages' || $target === 'all') {
            $totals['pages'] = $svc->reindexPages();
        }
        if ($target === 'faqs' || $target === 'all') {
            $totals['faqs'] = $svc->reindexFaqs();
        }

        foreach ($totals as $collection => $count) {
            $this->line("  $collection: $count document" . ($count === 1 ? '' : 's'));
        }

        $this->line('[' . date('Y-m-d H:i:s') . '] Done.');
        return 0;
    }
}
