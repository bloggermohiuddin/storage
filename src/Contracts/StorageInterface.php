<?php

declare(strict_types=1);

namespace StorageSDK\Contracts;

/**
 * StorageInterface — unified contract for all storage adapters.
 *
 * All adapters (Local, S3, R2, B2) MUST implement this interface.
 * Application code depends ONLY on this contract, never on concrete adapters.
 */
interface StorageInterface
{
    /**
     * Store a file from a local temp path or raw content.
     *
     * @param  string $key       Object key, e.g. "patients/2026/04/uuid.jpg"
     * @param  string $source    Absolute path to the source file (e.g. $_FILES tmp path)
     * @param  array  $options   Optional: ['visibility'=>'public'|'private', 'mime'=>'image/jpeg']
     * @return string            The stored object key
     */
    public function put(string $key, string $source, array $options = []): string;

    /**
     * Retrieve raw file contents.
     *
     * @param  string $key
     * @return string  Raw binary/text content
     */
    public function get(string $key): string;

    /**
     * Delete a file.
     *
     * @param  string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Check whether a file exists.
     *
     * @param  string $key
     * @return bool
     */
    public function exists(string $key): bool;

    /**
     * Get the public URL for a file.
     *
     * @param  string $key
     * @return string
     */
    public function url(string $key): string;

    /**
     * Get file size in bytes.
     *
     * @param  string $key
     * @return int
     */
    public function size(string $key): int;

    /**
     * Get the MIME type of a stored file.
     *
     * @param  string $key
     * @return string  e.g. "image/jpeg"
     */
    public function mimeType(string $key): string;

    /**
     * Get last modified timestamp.
     *
     * @param  string $key
     * @return int  Unix timestamp
     */
    public function lastModified(string $key): int;

    /**
     * Copy a file to a new key (same disk).
     *
     * @param  string $from  Source key
     * @param  string $to    Destination key
     * @return bool
     */
    public function copy(string $from, string $to): bool;

    /**
     * Move (rename) a file to a new key.
     *
     * @param  string $from  Source key
     * @param  string $to    Destination key
     * @return bool
     */
    public function move(string $from, string $to): bool;

    /**
     * Generate a time-limited signed URL for private file access.
     *
     * @param  string $key
     * @param  int    $expiry  Expiry time in seconds from now (default 3600)
     * @return string          Pre-signed URL
     */
    public function generateSignedUrl(string $key, int $expiry = 3600): string;
}
