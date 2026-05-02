<?php
// app/Controllers/UploadsController.php
namespace App\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;

/**
 * UploadsController — serves files from storage/uploads/ (which is outside the web root).
 *
 * SECURITY:
 *  - Validates the resolved path stays within the storage/uploads directory (no traversal)
 *  - Avatars are public; other subfolders require authentication
 *  - Sets appropriate Content-Type from extension allowlist (never trusts stored extension blindly)
 *  - Sends Content-Disposition: attachment for non-image types
 *  - Adds X-Content-Type-Options: nosniff
 */
class UploadsController
{
    private const PUBLIC_FOLDERS = ['avatars', 'group_images'];
    private const ALLOWED_TYPES  = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    public function serve(Request $request): Response
    {
        // Reconstruct path from route params (up to 3 segments: folder/subfolder/file)
        $parts = array_filter([
            $request->param(0),
            $request->param(1),
            $request->param(2),
        ]);
        $relativePath = implode('/', $parts);

        // SECURITY: Validate path — only alphanumeric, dots, dashes, underscores, slashes
        if (!preg_match('#^[a-zA-Z0-9_\-./]+$#', $relativePath)) {
            return new Response('', 400);
        }

        $storageDir  = $_ENV['STORAGE_PATH'] ?? BASE_PATH . '/storage';
        $uploadsBase = realpath($storageDir . '/uploads');

        if (!$uploadsBase) {
            return new Response('', 404);
        }

        $fullPath = realpath($uploadsBase . '/' . $relativePath);

        // SECURITY: Assert resolved path stays inside uploads directory
        if (!$fullPath || !str_starts_with($fullPath, $uploadsBase . DIRECTORY_SEPARATOR)) {
            return new Response('', 404);
        }

        if (!is_file($fullPath)) {
            return new Response('', 404);
        }

        // Determine the top-level folder
        $folder = explode('/', $relativePath)[0] ?? '';

        // SECURITY: Non-public folders require authentication
        if (!in_array($folder, self::PUBLIC_FOLDERS, true)) {
            if (Auth::getInstance()->guest()) {
                return new Response('', 403);
            }
        }

        // Get extension and validate against allowlist
        $ext      = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeType = self::ALLOWED_TYPES[$ext] ?? null;

        if (!$mimeType) {
            return new Response('', 403);
        }

        $fileContent = file_get_contents($fullPath);
        if ($fileContent === false) {
            return new Response('', 500);
        }

        $isImage = str_starts_with($mimeType, 'image/');

        return new Response($fileContent, 200, [
            'Content-Type'              => $mimeType,
            'X-Content-Type-Options'    => 'nosniff',
            'Cache-Control'             => $isImage ? 'public, max-age=86400, immutable' : 'private, no-store',
            'Content-Length'            => (string) strlen($fileContent),
            'Content-Disposition'       => $isImage ? 'inline' : 'attachment; filename="' . basename($fullPath) . '"',
        ]);
    }
}
