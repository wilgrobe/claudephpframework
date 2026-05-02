<?php
// core/Contracts/StorageDriver.php
namespace Core\Contracts;

/**
 * File storage abstraction. Local filesystem, S3, MinIO, GCS all sit behind
 * this. FileUploadService currently implements via Flysystem under the hood.
 */
interface StorageDriver
{
    /** Store raw bytes at $path and return the storage key. */
    public function put(string $path, string $contents): string;

    /** Read raw bytes back. Throws when the object is missing. */
    public function get(string $path): string;

    /** Delete an object. Returns true on success / already-gone. */
    public function delete(string $path): bool;

    /** True when the object exists. */
    public function exists(string $path): bool;

    /** Resolve a storage key to a publicly-reachable URL. */
    public function url(string $path): string;
}
