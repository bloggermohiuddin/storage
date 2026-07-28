<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

use StoragePlatform\StorageEngine\HashedLocalEngine;

/**
 * LocalProvider — direct local filesystem storage provider backed by HashedLocalEngine.
 * Stores files under a root directory using hashed 2-level subdirectories and separate metadata.
 */
class LocalProvider implements StorageProviderInterface
{
    protected string $root;
    protected string $baseUrl;
    protected HashedLocalEngine $engine;

    public function __construct(string $root, string $baseUrl = '')
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->engine = new HashedLocalEngine($this->root);
    }

    public function put(string $bucket, string $key, string $source, array $options = []): string
    {
        $this->engine->write($bucket, $key, $source, $options);
        return $key;
    }

    public function get(string $bucket, string $key): string
    {
        return $this->engine->read($bucket, $key);
    }

    public function delete(string $bucket, string $key): bool
    {
        return $this->engine->delete($bucket, $key);
    }

    public function exists(string $bucket, string $key): bool
    {
        return $this->engine->exists($bucket, $key);
    }

    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        return $this->engine->copy($bucket, $fromKey, $toKey);
    }

    public function move(string $bucket, string $fromKey, string $toKey): bool
    {
        return $this->engine->move($bucket, $fromKey, $toKey);
    }

    public function metadata(string $bucket, string $key): array
    {
        $meta = $this->engine->getMetadata($bucket, $key);
        return [
            'size' => $meta['size'] ?? 0,
            'mime_type' => $meta['mime_type'] ?? 'application/octet-stream',
            'last_modified' => $meta['updated_at'] ?? time(),
            'etag' => $meta['etag'] ?? '"' . ($meta['md5'] ?? '') . '"',
            'sha256' => $meta['sha256'] ?? '',
            'md5' => $meta['md5'] ?? '',
        ];
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        // For LocalProvider, list objects from DB / metadata directory
        $metaDir = $this->root . '/metadata/' . $this->sanitize($bucket);
        if (!is_dir($metaDir)) {
            return [];
        }

        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($metaDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $json = file_get_contents($file->getRealPath());
            if ($json) {
                $decoded = json_decode($json, true);
                if (isset($decoded['key'])) {
                    $key = $decoded['key'];
                    if ($prefix === '' || str_starts_with($key, $prefix)) {
                        $keys[] = $key;
                    }
                }
            }
        }

        sort($keys);
        return array_values(array_unique($keys));
    }

    public function streamRead(string $bucket, string $key)
    {
        return $this->engine->readStream($bucket, $key);
    }

    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool
    {
        try {
            $this->engine->writeStream($bucket, $key, $resource, $options);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string
    {
        $expires = time() + $expiry;
        $secret = $_ENV['SIGNED_URL_SECRET'] ?? 'local-secret-key-fallback';
        $signature = hash_hmac('sha256', $bucket . '|' . $key . '|' . $expires, $secret);
        
        $baseUrl = $this->baseUrl ?: ($_ENV['APP_URL'] ?? 'http://localhost:8080');
        return $baseUrl . '/' . rawurlencode($bucket) . '/' . rawurlencode($key) . 
               '?expires=' . $expires . 
               '&signature=' . $signature;
    }

    public function listBuckets(): array
    {
        $bucketsDir = $this->root . '/buckets';
        if (!is_dir($bucketsDir)) {
            return [];
        }

        $buckets = [];
        foreach (scandir($bucketsDir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_dir($bucketsDir . '/' . $item)) {
                $buckets[] = $item;
            }
        }
        return $buckets;
    }

    public function createBucket(string $name, array $options = []): bool
    {
        $sanitized = $this->sanitize($name);
        $bDir = $this->root . '/buckets/' . $sanitized;
        $mDir = $this->root . '/metadata/' . $sanitized;

        if (!is_dir($bDir)) {
            mkdir($bDir, 0755, true);
        }
        if (!is_dir($mDir)) {
            mkdir($mDir, 0755, true);
        }

        return true;
    }

    public function deleteBucket(string $name): bool
    {
        return $this->engine->deleteBucketDir($name);
    }

    public function health(): array
    {
        if (is_dir($this->root) && is_writable($this->root)) {
            return ['status' => 'healthy', 'error' => null];
        }
        return ['status' => 'unhealthy', 'error' => "Root path is not writable: {$this->root}"];
    }

    public function getHashedEngine(): HashedLocalEngine
    {
        return $this->engine;
    }

    protected function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $name) ?: 'uploads';
    }
}

