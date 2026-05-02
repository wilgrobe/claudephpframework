<?php
// modules/import-export/Controllers/ImportExportAdminController.php
namespace Modules\ImportExport\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Modules\ImportExport\Services\ImportExportService;

/**
 *   GET  /admin/import                         — list handlers + recent imports
 *   POST /admin/import/upload                  — upload file, go to mapping step
 *   GET  /admin/import/{id}                    — mapping editor + dry-run preview
 *   POST /admin/import/{id}/map                — save column→field mapping
 *   POST /admin/import/{id}/run                — run the import (with dry_run flag)
 *
 *   GET  /admin/export/{entity_type}.csv       — stream an export
 */
class ImportExportAdminController
{
    private Auth                $auth;
    private ImportExportService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new ImportExportService();
    }

    private function gate(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        return null;
    }

    public function index(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        return Response::view('import_export::admin.index', [
            'handlers' => ImportExportService::handlers(),
            'imports'  => $this->svc->recentImports(50),
        ]);
    }

    public function upload(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        $entityType = (string) $request->post('entity_type');
        if (!$this->svc->handlerFor($entityType)) {
            return Response::redirect('/admin/import')->withFlash('error', 'Unknown entity type.');
        }
        if (!$request->hasFile('file')) {
            return Response::redirect('/admin/import')->withFlash('error', 'Upload a file.');
        }
        $file = $request->file('file');
        if (!is_array($file)) return Response::redirect('/admin/import')->withFlash('error', 'Upload failed.');

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $format = in_array($ext, ['csv','tsv','json'], true) ? $ext : 'csv';

        $storageDir = BASE_PATH . '/storage/imports';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);
        $dest = $storageDir . '/upload_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $format;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return Response::redirect('/admin/import')->withFlash('error', 'Could not store upload.');
        }

        $rowCount = $this->svc->countRows($dest, $format);
        $id = $this->svc->createImport(
            (int) $this->auth->id(), $entityType, $dest, $format, $rowCount
        );
        return Response::redirect("/admin/import/$id");
    }

    public function show(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        $id = (int) $request->param(0);
        $imp = $this->svc->findImport($id);
        if (!$imp) return new Response('Not found', 404);

        $handler = $this->svc->handlerFor((string) $imp['entity_type']);
        $headers = $this->svc->detectHeaders((string) $imp['file_path'], (string) $imp['file_format']);
        $mapping = !empty($imp['mapping_json']) ? json_decode((string) $imp['mapping_json'], true) : [];

        return Response::view('import_export::admin.show', [
            'import'  => $imp,
            'handler' => $handler,
            'headers' => $headers,
            'mapping' => $mapping ?: [],
        ]);
    }

    public function saveMapping(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        $id = (int) $request->param(0);
        $imp = $this->svc->findImport($id);
        if (!$imp) return new Response('Not found', 404);

        $mapping = [];
        $handler = $this->svc->handlerFor((string) $imp['entity_type']);
        if ($handler) {
            foreach ((array) $handler['fields'] as $target) {
                $src = (string) $request->post("map_$target");
                if ($src !== '') $mapping[$target] = $src;
            }
        }
        $this->svc->saveMapping($id, $mapping);
        return Response::redirect("/admin/import/$id");
    }

    public function run(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        $id = (int) $request->param(0);
        $dry = (bool) $request->post('dry_run');
        try {
            $result = $this->svc->run($id, $dry);
        } catch (\InvalidArgumentException $e) {
            return Response::redirect("/admin/import/$id")->withFlash('error', $e->getMessage());
        }
        $msg = $dry
            ? 'Dry run: ' . array_sum($result['stats']) . ' rows would be processed.'
            : 'Import complete. See stats below.';
        return Response::redirect("/admin/import/$id")->withFlash('success', $msg);
    }

    public function export(Request $request): Response
    {
        if ($r = $this->gate()) return $r;
        $entityType = (string) $request->param(0);
        $handler = $this->svc->handlerFor($entityType);
        if (!$handler) return new Response('Unknown entity type', 404);

        $filename = preg_replace('~[^a-z0-9_-]~i', '', $entityType) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $this->svc->streamCsvExport($entityType);
        exit; // streamCsvExport writes directly to stdout
    }
}
