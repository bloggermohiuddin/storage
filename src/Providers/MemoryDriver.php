<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * MemoryDriver — In-memory ephemeral storage driver for unit testing & high-speed caching.
 */
class MemoryDriver implements StorageProviderInterface
{
    protected static array $storage = [];
    protected static array $buckets = ['uploads' => true];
    protected string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['url'] ?? 'http://localhost:8080';
    }

    public function put(string $bucket, string $key, string $source, array $options = []): string
    {
        if (!is_file($source)) {
            throw new \RuntimeException("Source file not found: {$source}");
        }
        self::$storage[$bucket][$key] = file_get_contents($source);
        return $key;
    }

    public function get(string $bucket, string $key): string
    {
        if (!isset(self::$storage[$bucket][$key])) {
            throw new \RuntimeException("Object not found in memory: [{$bucket}/{$key}]");
        }
        return self::$storage[$bucket][$key];
    }

    public function delete(string $bucket, string $key): bool
    {
        if (isset(self::$storage[$bucket][$key])) {
            unset(self::$storage[$bucket][$key]);
            return true;
        }
        return false;
    }

    public function exists(string $bucket, string $key): bool
    {
        return isset(self::$storage[$bucket][$key]);
    }

    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        if (isset(self::$storage[$bucket][$fromKey])) {
            self::$storage[$bucket][$toKey] = self::$storage[$bucket][$fromKey];
            return true;
        }
        return false;
    }

    public function move(string $bucket, string $fromKey, string $toKey): bool
    {
        if ($this->copy($bucket, $fromKey, $toKey)) {
            return $this->delete($bucket, $fromKey);
        }
        return false;
    }

    public function metadata(string $bucket, string $key): array
    {
        $data = $this->get($bucket, $key);
        return [
            'size' => strlen($data),
            'mime_type' => 'application/octet-stream',
            'last_modified' => time(),
            'etag' => '"' . md5($data) . '"',
            'sha256' => hash('sha256', $data),
            'md5' => md5($data),
        ];
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        if (!isset(self::$storage[$bucket])) {
            return [];
        }
        $keys = array_keys(self::$storage[$bucket]);
        if ($prefix !== '') {
            $keys = array_filter($keys, fn($k) => str_starts_with($k, $prefix));
        }
        sort($keys);
        return array_values($keys);
    }

    public function streamRead(string $bucket, string $key)
    {
        $data = $this->get($bucket, $key);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool
    {
        $data = stream_get_contents($resource);
        self::$storage[$bucket][$key] = $data;
        return true;
    }

    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string
    {
        return $this->baseUrl . '/object/' . urlencode($bucket) . '/' . urlencode($key);
    }

    public function listBuckets(): array
    {
        return array_keys(self::$buckets);
    }

    public function createBucket(string $name, array $options = []): bool
    {
        self::$buckets[$name] = true;
        return true;
    }

    public function deleteBucket(string $name): bool
    {
        unset(self::$buckets[$name], self::$storage[$name]);
        return true;
    }

    public function health(): array
    {
        return ['status' => 'healthy', 'error' => null];
    }
}
