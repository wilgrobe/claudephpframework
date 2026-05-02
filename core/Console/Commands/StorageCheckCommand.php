<?php
// core/Console/Commands/StorageCheckCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Services\FileUploadService;

/**
 * End-to-end diagnostic for the storage driver. Reads the current
 * STORAGE_DRIVER config, writes a tiny test file, reads it back, and cleans
 * up. Tells you exactly where the pipeline breaks (auth, DNS, bucket, etc.).
 */
class StorageCheckCommand extends Command
{
    public function name(): string        { return 'storage-check'; }
    public function description(): string { return 'Test the file-storage driver end-to-end (write + read + delete)'; }

    public function handle(array $argv): int
    {
        $storageCfg = config('storage');
        $driver     = $storageCfg['driver'] ?? 'local';

        $this->line('[' . date('Y-m-d H:i:s') . '] storage-check');
        $this->line("  Driver: $driver");

        if ($driver === 's3') {
            $s3 = $storageCfg['s3'];
            $this->line('  Endpoint:       ' . ($s3['endpoint'] ?: '(real AWS)'));
            $this->line('  Bucket:         ' . $s3['bucket']);
            $this->line('  Region:         ' . $s3['region']);
            $this->line('  Path-style:     ' . ($s3['use_path_style'] ? 'yes' : 'no'));
            $this->line('  Access key:     ' . (empty($s3['access_key']) ? '(empty!)' : substr($s3['access_key'], 0, 4) . '…'));
            $this->line('  Secret:         ' . (empty($s3['secret_key']) ? '(empty!)' : 'set'));
            $this->line('  Public URL:     ' . $s3['public_url_base']);
        } else {
            $this->line('  Root path:      ' . ($storageCfg['local']['root_path'] ?? '(unset)'));
            $this->line('  Public URL:     ' . ($storageCfg['local']['public_url_base'] ?? '(unset)'));
        }

        $this->line('');
        $this->line('  Attempting a round-trip write/read/delete of a test file...');

        try {
            $svc       = new FileUploadService();
            $testKey   = '_diag/storage_check_' . bin2hex(random_bytes(4)) . '.txt';
            $testValue = 'hello-minio-' . date('YmdHis');

            // Reach the underlying Flysystem handle — the service's public
            // API is image-specific but this probe needs plain bytes.
            $refl = new \ReflectionObject($svc);
            $fsProp = $refl->getProperty('fs');
            $fsProp->setAccessible(true);
            /** @var \League\Flysystem\Filesystem $fs */
            $fs = $fsProp->getValue($svc);

            $fs->write($testKey, $testValue);
            $this->line("  ✓ write ok ($testKey)");

            $read = $fs->read($testKey);
            $this->line("  ✓ read  ok (got '" . substr($read, 0, 40) . "')");
            if ($read !== $testValue) {
                $this->warn("content mismatch — wrote '$testValue', got '$read'");
            }

            $fs->delete($testKey);
            $this->line('  ✓ delete ok');

            $this->line('');
            $this->line('  Public URL for that key would have been:');
            $this->line('    ' . $svc->url($testKey));
            $this->line('');
            $this->line('[' . date('Y-m-d H:i:s') . '] Storage is healthy.');
            return 0;
        } catch (\Throwable $e) {
            $this->error($e::class);
            $this->error('    ' . $e->getMessage());
            if ($e->getPrevious()) {
                $this->error('    Caused by: ' . $e->getPrevious()::class);
                $this->error('    ' . $e->getPrevious()->getMessage());
            }
            $this->line('');
            $this->line('  Common causes:');
            $this->line('    - MinIO not running (check http://localhost:9001)');
            $this->line('    - S3_USE_PATH_STYLE=1 missing (MinIO requires it)');
            $this->line('    - Wrong S3_ACCESS_KEY / S3_SECRET_KEY');
            $this->line('    - Bucket name typo or bucket doesn\'t exist yet');
            $this->line('    - STORAGE_DRIVER not reading from .env');
            return 1;
        }
    }
}
