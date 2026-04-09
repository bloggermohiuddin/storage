<?php

declare(strict_types=1);

namespace StorageSDK\Adapters;

use StorageSDK\Contracts\StorageInterface;
use StorageSDK\Exceptions\FileNotFoundException;
use StorageSDK\Exceptions\StorageException;
use StorageSDK\Support\Logger;

/**
 * LocalAdapter — stores files on the local filesystem.
 *
 * Designed for shared hosting with no external dependencies.
 * Generates public URLs using a configurable base URL.
 * Signed URLs are token-based (HMAC-SHA256) and work without any server config.
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  Storage root:  /var/www/html/storage/uploads/              │
 * │  Public URL:    https://example.com/storage/uploads/{key}   │
 * └─────────────────────────────────────────────────────────────┘
 */
class LocalAdapter implements StorageInterface
{
    private string $root;
    private string $baseUrl;
    private string $signedUrlSecret;
    private Logger $logger;

    /**
     * @param string $root             Absolute filesystem root for storage
     * @param string $baseUrl          Base public URL (no trailing slash)
     * @param string $signedUrlSecret  HMAC secret for signed URL generation
     * @param Logger $logger
     */
    public function __construct(
        string $root,
        string $baseUrl,
        string $signedUrlSecret,
        Logger $logger
    ) {
        $this->root            = rtrim($root, DIRECTORY_SEPARATOR);
        $this->baseUrl         = rtrim($baseUrl, '/');
        $this->signedUrlSecret = $signedUrlSecret;
        $this->logger          = $logger;
    }

    // =========================================================================
    // StorageInterface implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function put(string $key, string $source, array $options = []): string
    {
        $this->assertNotTraversal($key);

        $destination = $this->fullPath($key);
        $dir         = dirname($destination);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new StorageException("Failed to create directory: {$dir}");
        }

        if (! copy($source, $destination)) {
            throw new StorageException("Failed to store file [{$key}]");
        }

        // Ensure the file is not web-executable
        chmod($destination, 0644);

        $this->logger->info('PUT', ['key' => $key, 'size' => filesize($destination)]);

        return $key;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): string
    {
        $path = $this->resolvePath($key);
        $data = file_get_contents($path);

        if ($data === false) {
            throw new StorageException("Failed to read file [{$key}]");
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (! $this->exists($key)) {
            return false;
        }

        $result = unlink($this->fullPath($key));
        if ($result) {
            $this->logger->info('DELETE', ['key' => $key]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        $this->assertNotTraversal($key);
        return is_file($this->fullPath($key));
    }

    /**
     * {@inheritdoc}
     *
     * Returns the public URL to access this file.
     * For private files, use generateSignedUrl() instead.
     */
    public function url(string $key): string
    {
        $this->assertNotTraversal($key);
        return $this->baseUrl . '/' . ltrim($key, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $key): int
    {
        $path = $this->resolvePath($key);
        $size = filesize($path);

        if ($size === false) {
            throw new StorageException("Unable to determine file size for [{$key}]");
        }

        return $size;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $key): string
    {
        $path = $this->resolvePath($key);

        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime !== false) {
                return $mime;
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime !== false) {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $key): int
    {
        $path  = $this->resolvePath($key);
        $mtime = filemtime($path);

        if ($mtime === false) {
            throw new StorageException("Unable to read mtime for [{$key}]");
        }

        return $mtime;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        $srcPath = $this->resolvePath($from);
        $this->assertNotTraversal($to);

        $dstPath = $this->fullPath($to);
        $dir     = dirname($dstPath);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new StorageException("Failed to create directory: {$dir}");
        }

        $result = copy($srcPath, $dstPath);
        if ($result) {
            chmod($dstPath, 0644);
            $this->logger->info('COPY', ['from' => $from, 'to' => $to]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $from, string $to): bool
    {
        $srcPath = $this->resolvePath($from);
        $this->assertNotTraversal($to);

        $dstPath = $this->fullPath($to);
        $dir     = dirname($dstPath);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new StorageException("Failed to create directory: {$dir}");
        }

        $result = rename($srcPath, $dstPath);
        if ($result) {
            chmod($dstPath, 0644);
            $this->logger->info('MOVE', ['from' => $from, 'to' => $to]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * Generates a time-limited signed URL using HMAC-SHA256.
     * The URL includes an expiry timestamp and a signature.
     *
     * Verification is done in your serve script (see examples/serve_signed.php).
     *
     * URL format: {baseUrl}/{key}?expires={timestamp}&signature={hmac}
     */
    public function generateSignedUrl(string $key, int $expiry = 3600): string
    {
        $this->assertNotTraversal($key);

        $expires   = time() + $expiry;
        $payload   = $key . '|' . $expires;
        $signature = hash_hmac('sha256', $payload, $this->signedUrlSecret);

        return $this->baseUrl . '/' . ltrim($key, '/')
            . '?expires=' . $expires
            . '&signature=' . $signature;
    }

    // =========================================================================
    // Public helper — verify a signed URL (use in your serve script)
    // =========================================================================

    /**
     * Verify whether a signed URL token is valid and not expired.
     *
     * @param  string $key        Object key (from URL path)
     * @param  int    $expires    Expiry timestamp (from query param)
     * @param  string $signature  HMAC signature (from query param)
     * @return bool
     */
    public function verifySignedUrl(string $key, int $expires, string $signature): bool
    {
        if (time() > $expires) {
            return false; // Link expired
        }

        $payload  = $key . '|' . $expires;
        $expected = hash_hmac('sha256', $payload, $this->signedUrlSecret);

        // Constant-time comparison prevents timing attacks
        return hash_equals($expected, $signature);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Returns absolute filesystem path for a key.
     */
    private function fullPath(string $key): string
    {
        // Normalise: no leading slash, forward slashes only
        $key = ltrim(str_replace('\\', '/', $key), '/');
        return $this->root . DIRECTORY_SEPARATOR . $key;
    }

    /**
     * Returns the path only if the file exists (throws otherwise).
     */
    private function resolvePath(string $key): string
    {
        $this->assertNotTraversal($key);

        if (! $this->exists($key)) {
            throw new FileNotFoundException($key);
        }

        return $this->fullPath($key);
    }

    /**
     * Prevent directory traversal attacks.
     * Throws if the key contains ".." or null bytes.
     */
    private function assertNotTraversal(string $key): void
    {
        if (str_contains($key, '..') || str_contains($key, "\0")) {
            throw new StorageException(
                "Directory traversal attempt detected in key: [{$key}]"
            );
        }

        // Resolve real path and ensure it stays within root
        $fullPath = $this->fullPath($key);
        $real     = realpath(dirname($fullPath));

        // realpath returns false for non-existent dirs; check normalised string instead
        $normalised = str_replace('\\', '/', $fullPath);
        $rootNorm   = str_replace('\\', '/', $this->root);

        if (! str_starts_with($normalised, $rootNorm)) {
            throw new StorageException(
                "Key [{$key}] resolves outside storage root — access denied"
            );
        }
    }
}
