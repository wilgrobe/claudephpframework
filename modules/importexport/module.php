<?php
// modules/import-export/module.php
use Core\Container\Container;
use Core\Module\ModuleProvider;
use Modules\ImportExport\Services\ImportExportService;

/**
 * Import-export module — pluggable CSV/JSON import with a column-
 * mapping UI, plus streaming CSV export for any registered entity
 * type.
 *
 * Modules register their importable entities via
 * ImportExportService::registerHandler($type, $spec). This module
 * ships a built-in handler for `users` as a simple example; other
 * modules (store, content, etc.) register their own types from their
 * own boot path.
 */
return new class extends ModuleProvider {
    // Namespace must match View::addNamespace's /^[a-zA-Z0-9_]+$/ regex.
    public function name(): string            { return 'import_export'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function register(Container $container): void
    {
        // Built-in `users` handler. Reads + upserts via Database directly
        // so this module doesn't have to depend on any specific user-
        // management module. Apps with a richer user model can
        // unregister this and register their own.
        ImportExportService::registerHandler('users', [
            'label'  => 'Users',
            'fields' => ['email','username','first_name','last_name'],
            'upsert' => static function (array $row, bool $dryRun): array {
                $db = \Core\Database\Database::getInstance();
                $email = trim((string) ($row['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'error' => 'invalid email'];
                }
                if ($dryRun) return ['ok' => true, 'action' => 'updated'];

                $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
                $data = [
                    'email'      => $email,
                    'username'   => trim((string) ($row['username']   ?? '')),
                    'first_name' => trim((string) ($row['first_name'] ?? '')),
                    'last_name'  => trim((string) ($row['last_name']  ?? '')),
                ];
                // Drop empty optional fields so we don't null-out existing values.
                foreach (['username','first_name','last_name'] as $k) {
                    if ($data[$k] === '') unset($data[$k]);
                }

                if ($existing) {
                    $db->update('users', $data, 'id = ?', [(int) $existing['id']]);
                    return ['ok' => true, 'action' => 'updated', 'id' => (int) $existing['id']];
                }
                $id = (int) $db->insert('users', $data);
                return ['ok' => true, 'action' => 'created', 'id' => $id];
            },
            'exportRows' => static function (array $filter = []) {
                $db = \Core\Database\Database::getInstance();
                $rows = $db->fetchAll(
                    "SELECT id, email, username, first_name, last_name, created_at FROM users ORDER BY id ASC"
                );
                foreach ($rows as $r) yield $r;
            },
        ]);
    }
};
