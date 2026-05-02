<?php
// core/Services/SearchService.php
namespace Core\Services;

use Core\Contracts\SearchEngine;
use Core\Database\Database;

/**
 * Hosted-search abstraction over Meilisearch or Algolia.
 *
 * When SEARCH_PROVIDER=none (default) the methods fall back to MySQL
 * FULLTEXT queries against the corresponding local tables, so every
 * install has *some* search out of the box and apps can flip the env
 * var on once the corpus grows large enough to justify hosting.
 *
 * The index keys follow a "collection" pattern — a collection is a
 * logical document type (content, pages). Each document has a stable
 * string id so index updates overwrite cleanly.
 *
 * Typical usage:
 *   $svc = new SearchService();
 *   $svc->indexDocument('content', '42', ['title' => ..., 'body' => ...]);
 *   $results = $svc->search('content', 'how to bake bread', 20);
 *
 * Reindexing the whole corpus is a superadmin action — the
 * reindexContent() and reindexPages() helpers walk the tables and push
 * one document per row.
 */
class SearchService implements SearchEngine
{
    private Database $db;
    private string   $provider;
    private array    $config;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->config   = IntegrationConfig::config('search');
        $this->provider = (string) ($this->config['driver'] ?? 'none');
    }

    public function isEnabled(): bool
    {
        return $this->provider !== 'none' && IntegrationConfig::enabled('search');
    }

    public function provider(): string
    {
        return $this->provider;
    }

    // ── Document lifecycle ──────────────────────────────────────────────────

    /**
     * Upsert a single document into the given collection. No-op when
     * search is disabled — callers can safely call this on every
     * content save.
     */
    public function indexDocument(string $collection, string $id, array $doc): bool
    {
        if (!$this->isEnabled()) return false;
        // Force the id into the document as Meilisearch / Algolia both
        // use the doc id for upserts.
        $doc['id'] = $id;

        if ($this->provider === 'meilisearch') {
            return $this->meiliRequest('POST', "/indexes/$collection/documents", [$doc]) !== null;
        }
        if ($this->provider === 'algolia') {
            return $this->algoliaRequest('PUT', "/1/indexes/$collection/$id", $doc) !== null;
        }
        if ($this->provider === 'opensearch') {
            return $this->opensearchRequest('PUT', "/$collection/_doc/" . rawurlencode($id), $doc) !== null;
        }
        return false;
    }

    /**
     * Batch-upsert many documents into $collection in a single HTTP call per
     * provider. Used by the reindex* helpers and the search:reindex command;
     * normal per-save indexing still goes through indexDocument() one doc at
     * a time (via a queue job, so no latency is added to the save path).
     *
     * @param array<int, array<string,mixed>> $docs must each contain 'id';
     *        if missing, the caller's id-key should be copied in first.
     * @return int number of docs the driver accepted (0 when disabled or on
     *         transport failure — the caller decides how to treat partials).
     */
    public function bulkIndex(string $collection, array $docs): int
    {
        if (!$this->isEnabled() || empty($docs)) return 0;

        if ($this->provider === 'meilisearch') {
            // Meilisearch accepts an array of docs on POST /indexes/X/documents
            // — idempotent upsert, returns a taskUid.
            $res = $this->meiliRequest('POST', "/indexes/$collection/documents", $docs);
            return $res !== null ? count($docs) : 0;
        }

        if ($this->provider === 'algolia') {
            // Algolia batch format: {"requests":[{"action":"updateObject","body":{...}},...]}
            $requests = array_map(static fn(array $d) => [
                'action' => 'updateObject',
                'body'   => $d,
            ], $docs);
            $res = $this->algoliaRequest('POST', "/1/indexes/$collection/batch", ['requests' => $requests]);
            return $res !== null ? count($docs) : 0;
        }

        if ($this->provider === 'opensearch') {
            // OpenSearch/Elasticsearch _bulk: newline-delimited JSON, action
            // line + doc line per entry. We build a string and POST it via
            // the ES-style NDJSON content type. opensearchRequest takes an
            // array body today; easier to fall back to per-doc PUT here.
            $ok = 0;
            foreach ($docs as $doc) {
                $id = (string) ($doc['id'] ?? '');
                if ($id === '') continue;
                if ($this->opensearchRequest('PUT', "/$collection/_doc/" . rawurlencode($id), $doc) !== null) {
                    $ok++;
                }
            }
            return $ok;
        }
        return 0;
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        if (!$this->isEnabled()) return false;

        if ($this->provider === 'meilisearch') {
            return $this->meiliRequest('DELETE', "/indexes/$collection/documents/$id") !== null;
        }
        if ($this->provider === 'algolia') {
            return $this->algoliaRequest('DELETE', "/1/indexes/$collection/$id") !== null;
        }
        if ($this->provider === 'opensearch') {
            return $this->opensearchRequest('DELETE', "/$collection/_doc/" . rawurlencode($id)) !== null;
        }
        return false;
    }

    /**
     * Run a query and return an array of matching documents.
     *
     * When hosted search is disabled we transparently fall back to MySQL
     * FULLTEXT against the `content_items` / `pages` tables. Results have
     * the same shape either way so callers don't need to branch.
     */
    public function search(string $collection, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') return [];

        if ($this->provider === 'meilisearch') {
            $res = $this->meiliRequest('POST', "/indexes/$collection/search", [
                'q'     => $query,
                'limit' => $limit,
            ]);
            return $res['hits'] ?? [];
        }
        if ($this->provider === 'algolia') {
            $res = $this->algoliaRequest('POST', "/1/indexes/$collection/query", [
                'params' => http_build_query(['query' => $query, 'hitsPerPage' => $limit]),
            ]);
            return $res['hits'] ?? [];
        }
        if ($this->provider === 'opensearch') {
            // OpenSearch / Elasticsearch _search with multi-match across
            // title + body fields. Returns the _source of each hit to
            // match the shape of other providers.
            $res = $this->opensearchRequest('POST', "/$collection/_search", [
                'size'  => $limit,
                'query' => [
                    'multi_match' => [
                        'query'  => $query,
                        'fields' => ['title^2', 'body'],
                    ],
                ],
            ]);
            $hits = $res['hits']['hits'] ?? [];
            return array_map(fn($h) => $h['_source'] ?? [], $hits);
        }

        // Disabled — local MySQL FULLTEXT fallback.
        return $this->fallbackSearch($collection, $query, $limit);
    }

    /**
     * Rebuild the content_items index from scratch. Returns the number
     * of documents pushed. Safe to call on a live site — hosted search
     * providers accept upserts without rebuilding the whole index.
     */
    /**
     * Rebuilds are chunked so we don't hold the entire corpus in memory at
     * once for a large table, and don't send a 100 MB POST body to the
     * provider. 500 docs/batch is the sweet spot that Meilisearch + Algolia
     * both happily accept.
     */
    private const REINDEX_BATCH_SIZE = 500;

    public function reindexContent(): int
    {
        return $this->streamReindex(
            'content',
            "SELECT id, title, slug, body, type, status, published_at
               FROM content_items WHERE status = 'published' ORDER BY id",
            static fn(array $r): array => [
                'id'           => (string) $r['id'],
                'title'        => $r['title'],
                'slug'         => $r['slug'],
                'body'         => strip_tags((string) $r['body']),
                'type'         => $r['type'],
                'published_at' => $r['published_at'],
            ]
        );
    }

    public function reindexPages(): int
    {
        return $this->streamReindex(
            'pages',
            "SELECT id, title, slug, body
               FROM pages WHERE status = 'published' AND is_public = 1 ORDER BY id",
            static fn(array $r): array => [
                'id'    => (string) $r['id'],
                'title' => $r['title'],
                'slug'  => $r['slug'],
                'body'  => strip_tags((string) $r['body']),
            ]
        );
    }

    /**
     * Rebuild the public FAQ index. Only public+active rows are included —
     * matches the /search view's visibility rules.
     */
    public function reindexFaqs(): int
    {
        return $this->streamReindex(
            'faqs',
            "SELECT id, question, answer
               FROM faqs WHERE is_public = 1 AND is_active = 1 ORDER BY id",
            static fn(array $r): array => [
                'id'       => (int) $r['id'],
                'question' => $r['question'],
                'answer'   => strip_tags((string) $r['answer']),
            ]
        );
    }

    /**
     * Walk the result set in batches of REINDEX_BATCH_SIZE rows, map each
     * batch through $docBuilder, and ship via bulkIndex(). Before this
     * helper landed each reindex was N HTTP calls; it's now
     * ceil(N / BATCH_SIZE) calls.
     *
     * Uses id-based cursor pagination so memory stays O(BATCH_SIZE) even
     * for corpora in the hundreds of thousands.
     */
    private function streamReindex(string $collection, string $baseSql, callable $docBuilder): int
    {
        if (!$this->isEnabled()) return 0;

        $total    = 0;
        $lastId   = 0;
        $limit    = self::REINDEX_BATCH_SIZE;
        // Splice the keyset cursor into the baseline query. We rely on
        // "ORDER BY id" being present in $baseSql (all three helpers supply
        // it) — the WHERE id > ? is injected before ORDER BY.
        $cursorSql = preg_replace(
            '/\bORDER BY\b/i',
            // Only apply the "AND id > ?" if there's an existing WHERE, else use WHERE.
            (stripos($baseSql, 'WHERE') !== false ? 'AND id > ? ORDER BY' : 'WHERE id > ? ORDER BY'),
            $baseSql,
            1
        ) . ' LIMIT ?';

        while (true) {
            $rows = $this->db->fetchAll($cursorSql, [$lastId, $limit]);
            if (empty($rows)) break;

            $docs = array_map($docBuilder, $rows);
            $total += $this->bulkIndex($collection, $docs);

            $lastId = (int) end($rows)['id'];
            if (count($rows) < $limit) break; // short page = end of data
        }

        return $total;
    }

    // ── Backends ────────────────────────────────────────────────────────────

    private function meiliRequest(string $method, string $path, ?array $body = null): ?array
    {
        $host = rtrim((string) ($this->config['host'] ?? ''), '/');
        if ($host === '') return null;
        $key  = (string) ($this->config['api_key'] ?? '');
        $url  = $host . $path;

        $headers = ['Content-Type: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => 10.0,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) $opts['http']['content'] = json_encode($body);

        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * HTTP call against AWS OpenSearch / Elasticsearch. Uses HTTP Basic
     * auth if OPENSEARCH_USERNAME + PASSWORD are set, which covers
     * fine-grained access control on AWS OpenSearch and self-hosted
     * clusters with security enabled.
     *
     * For IAM-authenticated AWS OpenSearch domains, requests need AWS
     * Signature v4 — you'll want the AWS SDK for that; this client
     * intentionally stays SDK-free.
     */
    private function opensearchRequest(string $method, string $path, ?array $body = null): ?array
    {
        $host = rtrim((string) ($this->config['host'] ?? ''), '/');
        if ($host === '') return null;
        $url = $host . $path;

        $headers = ['Content-Type: application/json'];
        $user = (string) ($this->config['username'] ?? '');
        $pass = (string) ($this->config['password'] ?? '');
        if ($user !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode("$user:$pass");
        }

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => 10.0,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) $opts['http']['content'] = json_encode($body);

        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function algoliaRequest(string $method, string $path, ?array $body = null): ?array
    {
        $app = (string) ($this->config['app_id']  ?? '');
        $key = (string) ($this->config['api_key'] ?? '');
        if ($app === '' || $key === '') return null;

        $host = "https://$app-dsn.algolia.net";
        $url  = $host . $path;

        $headers = [
            'X-Algolia-Application-Id: ' . $app,
            'X-Algolia-API-Key: '        . $key,
            'Content-Type: application/json',
        ];
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => 10.0,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) $opts['http']['content'] = json_encode($body);

        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * MySQL FULLTEXT fallback. Uses the existing ft_* fulltext indexes
     * on content_items and pages. Returns documents shaped like the
     * hosted-provider hits so callers don't branch on provider.
     */
    private function fallbackSearch(string $collection, string $query, int $limit): array
    {
        if ($collection === 'content') {
            return $this->db->fetchAll(
                "SELECT id, title, slug, `type`, published_at,
                        SUBSTRING(`body`, 1, 300) AS body
                 FROM content_items
                 WHERE `status` = 'published'
                   AND MATCH(title, `body`) AGAINST(? IN NATURAL LANGUAGE MODE)
                 LIMIT ?",
                [$query, $limit]
            );
        }
        if ($collection === 'pages') {
            return $this->db->fetchAll(
                "SELECT id, title, slug, SUBSTRING(`body`, 1, 300) AS body
                 FROM pages
                 WHERE `status` = 'published' AND is_public = 1
                   AND MATCH(title, `body`) AGAINST(? IN NATURAL LANGUAGE MODE)
                 LIMIT ?",
                [$query, $limit]
            );
        }
        if ($collection === 'faqs') {
            return $this->db->fetchAll(
                "SELECT id, question, SUBSTRING(answer, 1, 300) AS answer
                 FROM faqs
                 WHERE is_public = 1 AND is_active = 1
                   AND MATCH(question, answer) AGAINST(? IN NATURAL LANGUAGE MODE)
                 LIMIT ?",
                [$query, $limit]
            );
        }
        return [];
    }
}
