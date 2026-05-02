<?php
// modules/gdpr/Services/DataExporter.php
namespace Modules\Gdpr\Services;

use Core\Database\Database;

/**
 * Builds a per-user data export ZIP from every active GdprHandler.
 *
 * Output structure:
 *   storage/gdpr/exports/user-{id}-{token}.zip
 *     ├── README.txt                  human-readable index of what's inside
 *     ├── account.json                core users.* row (PII)
 *     ├── core.identity/              one JSON per declared table
 *     │   ├── user_oauth.json
 *     │   ├── user_roles.json
 *     │   └── user_groups.json
 *     ├── core.security/
 *     │   └── ...
 *     └── {module}/
 *         └── ...
 *
 * Tables marked `export: false` are skipped (e.g. password_resets,
 * two_factor_challenges — these are operational artifacts the user
 * doesn't need a copy of and are arguably not "personal data" GDPR
 * Art. 15 covers).
 *
 * The export file lives outside the web root and is only fetchable via
 * a signed download token + an authenticated session. Default TTL is
 * 7 days; configurable via `gdpr_export_ttl_days` setting.
 */
class DataExporter
{
    private Database     $db;
    private GdprRegistry $registry;
    private string       $storagePath;

    public function __construct(?Database $db = null, ?GdprRegistry $registry = null)
    {
        $this->db          = $db       ?? Database::getInstance();
        $this->registry    = $registry ?? new GdprRegistry();
        $this->storagePath = rtrim(BASE_PATH, '/\\') . '/storage/gdpr/exports';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Build the export ZIP for one user. Returns the data_exports row id.
     *
     * @param int  $userId
     * @param ?int $dsarId  link to the dsar_requests row, if this export
     *                     was triggered from a DSAR (vs. self-service)
     */
    public function buildForUser(int $userId, ?int $dsarId = null): int
    {
        $token   = bin2hex(random_bytes(32));
        $ttlDays = max(1, (int) (setting('gdpr_export_ttl_days', 7) ?? 7));

        $exportId = $this->db->insert('data_exports', [
            'user_id'        => $userId,
            'dsar_id'        => $dsarId,
            'status'         => 'building',
            'format'         => 'zip',
            'download_token' => $token,
            'expires_at'     => date('Y-m-d H:i:s', time() + $ttlDays * 86400),
        ]);

        $filename = sprintf('user-%d-%s.zip', $userId, substr($token, 0, 16));
        $filepath = $this->storagePath . '/' . $filename;

        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('PHP zip extension is required for data exports.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create export zip: ' . $filepath);
            }

            $readme = "DATA EXPORT for user_id={$userId}\n";
            $readme .= "Generated: " . date('Y-m-d H:i:s T') . "\n";
            $readme .= "Expires:   " . date('Y-m-d H:i:s T', time() + $ttlDays * 86400) . "\n\n";
            $readme .= "This export contains every record the framework holds about\n";
            $readme .= "your account, organised by the module that owns each piece.\n\n";

            // ── 1. The user record itself ───────────────────────────
            $userRow = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            if ($userRow) {
                // Drop password hashes + 2FA secrets — these aren't
                // "personal data" that survives a verified export, and
                // including them would let an export file be used as a
                // credential dump.
                unset(
                    $userRow['password'],
                    $userRow['two_factor_secret'],
                    $userRow['two_factor_recovery_codes'],
                    $userRow['totp_last_counter']
                );
                $zip->addFromString('account.json', json_encode($userRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $readme .= "  account.json — your core profile (passwords + 2FA secrets are excluded for security)\n";
            }

            // ── 2. Walk every handler and export its tables ─────────
            foreach ($this->registry->all() as $handler) {
                $folder      = $this->safePath($handler->module);
                $folderAdded = false;

                foreach ($handler->tables as $tbl) {
                    // Skip tables explicitly opted out of export
                    if (isset($tbl['export']) && $tbl['export'] === false) continue;

                    $table  = (string) $tbl['table'];
                    $col    = (string) $tbl['user_column'];
                    $select = (string) ($tbl['export_select'] ?? '*');

                    try {
                        $rows = $this->db->fetchAll(
                            "SELECT {$select} FROM `{$table}` WHERE `{$col}` = ?",
                            [$userId]
                        );
                    } catch (\Throwable $e) {
                        // Table missing on this install — module ships
                        // a handler but the migration didn't run, etc.
                        // Don't fail the whole export for one missing table.
                        $rows = ['_error' => "Could not export {$table}: " . $e->getMessage()];
                    }

                    if (empty($rows)) continue;

                    $entry = $folder . '/' . $this->safePath($table) . '.json';
                    $zip->addFromString($entry, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    if (!$folderAdded) {
                        $readme .= "  {$folder}/  ({$handler->description})\n";
                        $folderAdded = true;
                    }
                    $readme .= "    {$table}.json — " . count($rows) . " row" . (count($rows) === 1 ? '' : 's') . "\n";
                }

                // Custom export callable — anything more complex than
                // straight tables.
                if ($handler->customExport !== null) {
                    try {
                        $custom = ($handler->customExport)($userId);
                        if (is_array($custom)) {
                            foreach ($custom as $entryName => $payload) {
                                $entry = $folder . '/' . $this->safePath((string) $entryName);
                                $body  = is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                $zip->addFromString($entry, $body);
                                if (!$folderAdded) {
                                    $readme .= "  {$folder}/  ({$handler->description})\n";
                                    $folderAdded = true;
                                }
                                $readme .= "    " . basename($entry) . " — custom export\n";
                            }
                        }
                    } catch (\Throwable $e) {
                        $readme .= "    [custom export failed: {$e->getMessage()}]\n";
                    }
                }
            }

            $zip->addFromString('README.txt', $readme);
            $zip->close();

            $size = filesize($filepath) ?: 0;
            $this->db->update('data_exports', [
                'status'       => 'ready',
                'file_path'    => $filepath,
                'file_size'    => $size,
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$exportId]);

        } catch (\Throwable $e) {
            $this->db->update('data_exports', [
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 1000),
                'completed_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$exportId]);
            // Surface the failure — the caller will mark the DSAR
            // record accordingly.
            throw $e;
        }

        return $exportId;
    }

    /**
     * Look up an export by its download token — used by the controller
     * when the user clicks the download link. Returns null if the token
     * is invalid, expired, or the export has been purged.
     */
    public function findByToken(string $token): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM data_exports
             WHERE download_token = ? AND status = 'ready'
             AND (expires_at IS NULL OR expires_at > NOW())",
            [$token]
        );
        return $row ?: null;
    }

    /**
     * Sweep called by a scheduled task — purge expired files + mark
     * their rows. Files are deleted from disk; the data_exports row
     * itself stays so admins can audit who exported when.
     */
    public function purgeExpired(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id, file_path FROM data_exports
             WHERE status = 'ready' AND expires_at IS NOT NULL AND expires_at <= NOW()"
        );
        $count = 0;
        foreach ($rows as $r) {
            if ($r['file_path'] && file_exists($r['file_path'])) {
                @unlink($r['file_path']);
            }
            $this->db->update('data_exports', [
                'status'    => 'expired',
                'file_path' => null,
            ], 'id = ?', [$r['id']]);
            $count++;
        }
        return $count;
    }

    private function safePath(string $name): string
    {
        // Used as a subdirectory inside the zip — strip anything that
        // could break out of the directory or cause path-confusion on
        // weird Windows clients.
        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?? '';
        return trim($clean, '_.') ?: 'unknown';
    }
}
