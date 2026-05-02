<?php
// core/Services/FileUploadService.php
namespace Core\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Aws\S3\S3Client;

/**
 * FileUploadService — secure file upload handler.
 *
 * SECURITY:
 *  - Validates MIME type by inspecting file contents (not extension or client header)
 *  - Regenerates filename with a random prefix — no user-supplied filenames stored
 *  - Enforces maximum file size (configurable)
 *  - For images: re-encodes with GD to strip any embedded metadata or polyglot payloads
 *
 * STORAGE BACKENDS:
 *  Controlled by config/storage.php (driven by STORAGE_DRIVER env var).
 *    local — writes to STORAGE_PATH/uploads/, served via UploadsController@serve
 *    s3    — writes to an S3-compatible bucket (AWS S3, MinIO, Cloudflare R2, ...)
 *
 * The caller interface is identical regardless of backend:
 *   $rel = $uploader->uploadImage($_FILES['avatar'], 'avatars');
 *   $url = $uploader->url($rel);
 *   $uploader->delete($rel);
 *
 * Stored values are RELATIVE paths (e.g. 'avatars/abc123.jpg'). Historical
 * rows in the DB may have full URLs baked in (that was the previous
 * behavior) — those still render correctly so long as the backing file
 * hasn't been deleted.
 */
class FileUploadService
{
    private const DEFAULT_MAX_BYTES = 5_242_880; // 5 MB
    private const ALLOWED_IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private Filesystem $fs;
    private string     $driver;
    private array      $cfg;

    public function __construct(?string $driverOverride = null)
    {
        $config       = config('storage');
        $this->driver = $driverOverride ?? ($config['driver'] ?? 'local');
        $this->cfg    = $config[$this->driver] ?? [];
        $this->fs     = $this->buildFilesystem();
    }

    private function buildFilesystem(): Filesystem
    {
        if ($this->driver === 's3') {
            if (!class_exists(S3Client::class)) {
                throw new \RuntimeException(
                    'aws/aws-sdk-php is not installed. Run: composer require league/flysystem-aws-s3-v3'
                );
            }
            $client = new S3Client([
                'version'                 => 'latest',
                'region'                  => $this->cfg['region'] ?? 'us-east-1',
                'endpoint'                => $this->cfg['endpoint'] ?: null,
                'use_path_style_endpoint' => !empty($this->cfg['use_path_style']),
                'credentials' => [
                    'key'    => $this->cfg['access_key'] ?? '',
                    'secret' => $this->cfg['secret_key'] ?? '',
                ],
            ]);
            return new Filesystem(
                new AwsS3V3Adapter($client, $this->cfg['bucket'] ?? '')
            );
        }

        // Local disk (default)
        $root = $this->cfg['root_path'] ?? (BASE_PATH . '/storage/uploads');
        if (!is_dir($root)) mkdir($root, 0750, true);
        return new Filesystem(new LocalFilesystemAdapter($root));
    }

    /**
     * Upload and validate an image file.
     * Returns the stored path relative to storage root (e.g. 'avatars/abc123.jpg').
     */
    public function uploadImage(array $file, string $subfolder = 'uploads', int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $this->validateUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $this->validateSize($file['size'] ?? 0, $maxBytes);

        $tmpPath  = $file['tmp_name'];
        $mimeType = $this->detectMimeType($tmpPath);

        if (!isset(self::ALLOWED_IMAGE_TYPES[$mimeType])) {
            throw new \RuntimeException(
                "File type '$mimeType' is not allowed. Only JPEG, PNG, GIF, and WebP images are accepted."
            );
        }

        $ext      = self::ALLOWED_IMAGE_TYPES[$mimeType];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $relKey   = trim($subfolder, '/') . '/' . $filename;

        // Re-encode via GD to a temp file, then stream it into the filesystem.
        // This keeps the sensitive metadata-stripping logic identical across
        // storage drivers — GD always works on a local temp path.
        if (!extension_loaded('gd')) {
            // No GD: move the raw upload. This is a soft fallback; logs warn.
            error_log('[FileUploadService] GD extension not available; image not re-encoded. Install ext-gd for full security.');
            $this->fs->writeStream($relKey, fopen($tmpPath, 'rb'));
            return $relKey;
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'cphpfw_img_');
        try {
            $this->reEncodeImage($tmpPath, $tmpOut, $mimeType);
            $stream = fopen($tmpOut, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open re-encoded image for streaming.');
            }
            $this->fs->writeStream($relKey, $stream, ['visibility' => 'public']);
            if (is_resource($stream)) fclose($stream);
        } finally {
            if (is_file($tmpOut)) @unlink($tmpOut);
        }

        return $relKey;
    }

    /**
     * Delete a stored file. No-op if path is empty or missing.
     */
    public function delete(string $relativePath): void
    {
        if (!$relativePath) return;

        // Historical rows may have a full URL in the DB. Strip the URL prefix
        // so we end up with a key like 'avatars/abc.jpg' before calling delete.
        $relativePath = $this->pathFromUrl($relativePath);
        if (!$relativePath) return;

        try {
            if ($this->fs->fileExists($relativePath)) {
                $this->fs->delete($relativePath);
            }
        } catch (\Throwable $e) {
            error_log('[FileUploadService] delete failed for ' . $relativePath . ': ' . $e->getMessage());
        }
    }

    /**
     * Return a public URL for a stored file.
     *
     * For the local driver this goes through the app's /uploads/ route.
     * For s3 it's the configured public_url_base + key — typically a direct
     * bucket URL for MinIO/dev, and either a direct bucket URL or a CDN URL
     * in production.
     */
    public function url(string $relativePath, array $transforms = []): string
    {
        if ($relativePath === '') return '';

        // Already a full URL (historical rows stored the absolute URL).
        $url = preg_match('#^https?://#i', $relativePath)
            ? $relativePath
            : rtrim((string) ($this->cfg['public_url_base'] ?? ''), '/') . '/' . ltrim($relativePath, '/');

        // Rewrite through the configured Media CDN for on-the-fly
        // resize/format conversion. No-op when MEDIA_CDN_PROVIDER=none;
        // pass-through for non-image file types.
        return MediaCdnService::rewrite($url, $transforms);
    }

    /**
     * Translate a stored value (either a relative key OR a historical full
     * URL) back to a bare relative key so delete()/url() can operate on it.
     * Returns empty string if the URL doesn't look like one of ours.
     */
    private function pathFromUrl(string $value): string
    {
        if (!preg_match('#^https?://#i', $value)) {
            return ltrim($value, '/');
        }
        // Strip any known public base.
        foreach (['local', 's3'] as $d) {
            $base = config("storage.$d.public_url_base");
            if ($base && str_starts_with($value, (string)$base)) {
                return ltrim(substr($value, strlen((string)$base)), '/');
            }
        }
        // Old-style /uploads/ URLs under APP_URL.
        $appBase = rtrim((string) config('app.url'), '/') . '/uploads/';
        if (str_starts_with($value, $appBase)) {
            return ltrim(substr($value, strlen($appBase)), '/');
        }
        return '';
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function validateUploadError(int $error): void
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($messages[$error] ?? "Upload error code $error.");
        }
    }

    private function validateSize(int $size, int $maxBytes): void
    {
        if ($size === 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }
        if ($size > $maxBytes) {
            $mb = round($maxBytes / 1_048_576, 1);
            throw new \RuntimeException("File too large. Maximum size is {$mb} MB.");
        }
    }

    /**
     * Detect MIME type by inspecting file bytes — never trust the client-supplied
     * Content-Type or the filename extension.
     */
    private function detectMimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime) return $mime;
        }
        if (class_exists('\finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($path);
            if ($mime) return $mime;
        }
        $info = @getimagesize($path);
        if (!empty($info['mime'])) return $info['mime'];

        return 'application/octet-stream';
    }

    /**
     * Re-encode the image through GD to strip metadata and validate it is
     * actually a valid image (not a disguised script or polyglot).
     */
    private function reEncodeImage(string $src, string $dest, string $mimeType): void
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/gif'  => @imagecreatefromgif($src),
            'image/webp' => @imagecreatefromwebp($src),
            default      => false,
        };

        if ($image === false) {
            throw new \RuntimeException('File could not be decoded as a valid image.');
        }

        $w = imagesx($image);
        $h = imagesy($image);
        if ($w > 8000 || $h > 8000) {
            imagedestroy($image);
            throw new \RuntimeException("Image dimensions ({$w}×{$h}) exceed the maximum allowed (8000×8000).");
        }

        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $dest, 85),
            'image/png'  => imagepng($image, $dest, 7),
            'image/gif'  => imagegif($image, $dest),
            'image/webp' => imagewebp($image, $dest, 85),
            default      => false,
        };

        imagedestroy($image);

        if (!$saved) {
            throw new \RuntimeException('Failed to save re-encoded image.');
        }
    }
}
