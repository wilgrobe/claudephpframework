<?php
// modules/import-export/Services/ImportExportService.php
namespace Modules\ImportExport\Services;

use Core\Database\Database;

/**
 * Generic import/export engine.
 *
 * ## Handlers (per entity type)
 *
 * Each importable entity type registers a handler describing:
 *   - label            — human-readable name ("Users", "Store products")
 *   - fields           — target fields the admin maps source columns to
 *   - upsert(array $row, bool $dryRun): ['ok'=>bool, 'id'=>?int, 'error'=>?string]
 *   - exportRows(array $filter = []): iterable  — for /export
 *
 * Modules register during their own `register(Container)`:
 *   ImportExportService::registerHandler('users', [
 *       'label'  => 'Users',
 *       'fields' => ['email','username','first_name','last_name'],
 *       'upsert' => fn(array $row, bool $dry) => ...,
 *       'exportRows' => fn(array $filter) => ...,
 *   ]);
 *
 * ## Storage
 *
 * Uploaded files land under `storage/imports/<id>.<ext>`. The service
 * never writes outside that root and never reads absolute paths from
 * the UI.
 *
 * ## CSV parsing
 *
 * fgetcsv with default (comma) delimiter; TSV switches to tab. The
 * first row is treated as a header. JSON imports expect an array of
 * objects; the object keys become the "columns" for mapping.
 */
class ImportExportService
{
    /** @var array<string, array<string, mixed>> */
    private static array $handlers = [];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function registerHandler(string $type, array $spec): void
    {
        foreach (['label','fields','upsert','exportRows'] as $k) {
            if (!array_key_exists($k, $spec)) {
                throw new \InvalidArgumentException("Handler for '$type' missing key: $k");
            }
        }
        self::$handlers[$type] = $spec;
    }

    public static function clearHandlers(): void
    {
        self::$handlers = [];
    }

    /** @return array<string, array<string, mixed>> */
    public static function handlers(): array { return self::$handlers; }

    public function handlerFor(string $type): ?array
    {
        return self::$handlers[$type] ?? null;
    }

    // ── Imports ──────────────────────────────────────────────────────────

    public function createImport(
        int $uploadedBy, string $entityType, string $sourcePath, string $format, int $rowCount
    ): int {
        if (!$this->handlerFor($entityType)) {
            throw new \InvalidArgumentException("No import handler for '$entityType'.");
        }
        $id = (int) $this->db->insert('imports', [
            'entity_type' => $entityType,
            'uploaded_by' => $uploadedBy,
            'file_path'   => $sourcePath,
            'file_format' => $format,
            'row_count'   => $rowCount,
            'status'      => 'uploaded',
        ]);
        return $id;
    }

    public function findImport(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM imports WHERE id = ?", [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recentImports(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM imports ORDER BY created_at DESC LIMIT ?", [$limit]
        );
    }

    public function saveMapping(int $importId, array $mapping): void
    {
        $this->db->update('imports', [
            'mapping_json' => json_encode($mapping, JSON_UNESCAPED_SLASHES),
            'status'       => 'mapped',
        ], 'id = ?', [$importId]);
    }

    /**
     * Process the import. $dryRun=true runs through without calling
     * upsert in commit mode — returns preview results without mutating.
     * Per-row errors accumulate in `errors_json` (capped to the first
     * 200 to keep the row size sane).
     */
    public function run(int $importId, bool $dryRun = false): array
    {
        $imp = $this->findImport($importId);
        if (!$imp) throw new \InvalidArgumentException('Import not found.');
        $handler = $this->handlerFor((string) $imp['entity_type']);
        if (!$handler) throw new \InvalidArgumentException('No handler for entity type.');
        $mapping = $imp['mapping_json'] ? json_decode((string) $imp['mapping_json'], true) : [];
        if (!is_array($mapping) || empty($mapping)) {
            throw new \InvalidArgumentException('Mapping required before run.');
        }

        if (!$dryRun) {
            $this->db->update('imports', ['status' => 'running'], 'id = ?', [$importId]);
        }

        $stats  = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0];
        $errors = [];
        $rowIdx = 0;
        $processed = 0;

        foreach ($this->iterateRows((string) $imp['file_path'], (string) $imp['file_format']) as $raw) {
            $rowIdx++;
            $mapped = [];
            // mapping: target_field => source_column_name_or_index
            foreach ($mapping as $target => $source) {
                $mapped[$target] = $raw[$source] ?? null;
            }
            try {
                $res = ($handler['upsert'])($mapped, $dryRun);
                if ($res['ok'] ?? false) {
                    $stats[$res['action'] ?? 'updated'] = ($stats[$res['action'] ?? 'updated'] ?? 0) + 1;
                } else {
                    $stats['errored']++;
                    if (count($errors) < 200) {
                        $errors[] = ['row' => $rowIdx, 'error' => (string) ($res['error'] ?? 'unknown')];
                    }
                }
            } catch (\Throwable $e) {
                $stats['errored']++;
                if (count($errors) < 200) {
                    $errors[] = ['row' => $rowIdx, 'error' => $e->getMessage()];
                }
            }
            $processed++;
        }

        $final = [
            'status'          => $stats['errored'] > 0 && $stats['errored'] === $processed ? 'failed' : 'completed',
            'stats_json'      => json_encode($stats, JSON_UNESCAPED_SLASHES),
            'errors_json'     => json_encode($errors, JSON_UNESCAPED_SLASHES),
            'processed_count' => $processed,
        ];
        if (!$dryRun) {
            $this->db->update('imports', $final, 'id = ?', [$importId]);
        }

        return ['stats' => $stats, 'errors' => $errors, 'processed' => $processed];
    }

    /**
     * Iterate file rows lazily. CSV and TSV yield associative arrays
     * keyed by the header row; JSON yields the parsed array entries
     * directly.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateRows(string $path, string $format): \Generator
    {
        if (!is_file($path)) return;

        if ($format === 'json') {
            $raw  = (string) file_get_contents($path);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                foreach ($data as $row) {
                    yield is_array($row) ? $row : [];
                }
            }
            return;
        }

        $delim = $format === 'tsv' ? "\t" : ',';
        $fh = fopen($path, 'r');
        if (!$fh) return;
        try {
            $header = fgetcsv($fh, 0, $delim);
            if (!is_array($header)) return;
            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                if (!is_array($row)) continue;
                $assoc = [];
                foreach ($header as $i => $h) {
                    $assoc[(string) $h] = $row[$i] ?? null;
                }
                yield $assoc;
            }
        } finally {
            fclose($fh);
        }
    }

    public function detectHeaders(string $path, string $format): array
    {
        if ($format === 'json') {
            $raw  = (string) file_get_contents($path);
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                return array_keys($data[0]);
            }
            return [];
        }
        $delim = $format === 'tsv' ? "\t" : ',';
        $fh = fopen($path, 'r');
        if (!$fh) return [];
        try {
            $header = fgetcsv($fh, 0, $delim);
            return is_array($header) ? array_map('strval', $header) : [];
        } finally {
            fclose($fh);
        }
    }

    public function countRows(string $path, string $format): int
    {
        if ($format === 'json') {
            $raw  = (string) file_get_contents($path);
            $data = json_decode($raw, true);
            return is_array($data) ? count($data) : 0;
        }
        $delim = $format === 'tsv' ? "\t" : ',';
        $fh = fopen($path, 'r');
        if (!$fh) return 0;
        $n = 0;
        fgetcsv($fh, 0, $delim); // skip header
        while (fgetcsv($fh, 0, $delim) !== false) $n++;
        fclose($fh);
        return $n;
    }

    // ── Exports ──────────────────────────────────────────────────────────

    /**
     * Stream an entity's rows as CSV to stdout. Uses the handler's
     * `exportRows` callable to produce an iterable of associative
     * arrays. Headers derived from the handler's `fields`.
     */
    public function streamCsvExport(string $entityType, array $filter = [], string $delim = ','): void
    {
        $handler = $this->handlerFor($entityType);
        if (!$handler) throw new \InvalidArgumentException("No handler for '$entityType'.");
        $fields = (array) $handler['fields'];

        $out = fopen('php://output', 'w');
        try {
            fputcsv($out, $fields, $delim);
            foreach (($handler['exportRows'])($filter) as $row) {
                $line = [];
                foreach ($fields as $f) $line[] = $row[$f] ?? '';
                fputcsv($out, $line, $delim);
            }
        } finally {
            fclose($out);
        }
    }
}
