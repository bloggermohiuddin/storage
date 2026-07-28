<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * MyCloudProvider — the default self-hosted storage engine.
 * Stores files physically on disk using a hash-based layout (e.g. data/ab/cd/ef...)
 * to support millions of files, and retrieves metadata/object listings via SQLite database.
 */
class MyCloudProvider implements StorageProviderInterface
{
    protected string $root;
    protected string $baseUrl;
    protected ?\PDO $db;

    public function __construct(string $root, string $baseUrl = '', ?\PDO $db = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->db = $db;
    }

    public function put(string $bucket, string $key, string $source, array $options = []): string
    {
        if (!is_file($source) || !is_readable($source)) {
            throw new \RuntimeException("Source file not readable: {$source}");
        }

        // Generate physical hashed path
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);
        $dir = dirname($physicalPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        if (!copy($source, $physicalPath)) {
            throw new \RuntimeException("Failed to store file [{$key}] in MyCloud");
        }

        chmod($physicalPath, 0644);
        return $key;
    }

    public function get(string $bucket, string $key): string
    {
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);

        if (!is_file($physicalPath)) {
            throw new \RuntimeException("File not found: [{$bucket}/{$key}]");
        }

        $content = file_get_contents($physicalPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$physicalPath}");
        }

        return $content;
    }

    public function delete(string $bucket, string $key): bool
    {
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);

        if (is_file($physicalPath)) {
            return unlink($physicalPath);
        }

        return false;
    }

    public function exists(string $bucket, string $key): bool
    {
        $hash = hash('sha256', $bucket . '/' . $key);
        return is_file($this->getPhysicalPath($hash));
    }

    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        $hashFrom = hash('sha256', $bucket . '/' . $fromKey);
        $hashTo = hash('sha256', $bucket . '/' . $toKey);

        $src = $this->getPhysicalPath($hashFrom);
        $dst = $this->getPhysicalPath($hashTo);

        if (!is_file($src)) {
            return false;
        }

        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        if (copy($src, $dst)) {
            chmod($dst, 0644);
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
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);

        if (!is_file($physicalPath)) {
            throw new \RuntimeException("File not found: [{$bucket}/{$key}]");
        }

        // Query database if available to get actual metadata, else read from disk
        if ($this->db) {
            $stmt = $this->db->prepare("
                SELECT o.* FROM objects o
                JOIN buckets b ON o.bucket_id = b.id
                WHERE b.name = :bucket AND o.key = :key AND o.is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute(['bucket' => $bucket, 'key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'size' => (int)$row['size'],
                    'mime_type' => $row['mime_type'],
                    'last_modified' => strtotime($row['updated_at']),
                    'etag' => $row['hash_md5'] ?: $row['hash_sha256'],
                    'sha256' => $row['hash_sha256'],
                    'metadata' => json_decode($row['metadata'] ?: '{}', true),
                ];
            }
        }

        // Disk fallback
        $size = filesize($physicalPath);
        $mtime = filemtime($physicalPath);
        $mime = 'application/octet-stream';
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $physicalPath) ?: $mime;
            finfo_close($finfo);
        }

        return [
            'size' => $size,
            'mime_type' => $mime,
            'last_modified' => $mtime ?: 0,
            'etag' => md5_file($physicalPath) ?: '',
        ];
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        // For MyCloud, listings MUST come from the database since physical files are stored as hashes
        if ($this->db) {
            $stmt = $this->db->prepare("
                SELECT o.key FROM objects o
                JOIN buckets b ON o.bucket_id = b.id
                WHERE b.name = :bucket AND o.is_deleted = 0 AND o.key LIKE :prefix
                ORDER BY o.key ASC
            ");
            $stmt->execute([
                'bucket' => $bucket,
                'prefix' => $prefix . '%'
            ]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return [];
    }

    public function streamRead(string $bucket, string $key)
    {
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);

        if (!is_file($physicalPath)) {
            return false;
        }

        return fopen($physicalPath, 'rb');
    }

    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool
    {
        $hash = hash('sha256', $bucket . '/' . $key);
        $physicalPath = $this->getPhysicalPath($hash);
        $dir = dirname($physicalPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        $out = fopen($physicalPath, 'wb');
        if (!$out) {
            return false;
        }

        $written = stream_copy_to_stream($resource, $out);
        fclose($out);

        if ($written !== false) {
            chmod($physicalPath, 0644);
            return true;
        }

        return false;
    }

    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string
    {
        $expires = time() + $expiry;
        $secret = $_ENV['SIGNED_URL_SECRET'] ?? 'mycloud-secret-key-fallback';
        $signature = hash_hmac('sha256', $bucket . '|' . $key . '|' . $expires, $secret);

        return $this->baseUrl . '/' . rawurlencode($bucket) . '/' . rawurlencode($key) .
               '?expires=' . $expires .
               '&signature=' . $signature;
    }

    public function listBuckets(): array
    {
        if ($this->db) {
            // Get buckets scoped to mycloud driver in db
            $stmt = $this->db->prepare("
                SELECT b.name FROM buckets b
                JOIN storage_providers p ON b.provider_id = p.id
                WHERE p.driver = 'mycloud'
            ");
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Directory listing fallback
        if (!is_dir($this->root)) {
            return [];
        }

        return array_filter(scandir($this->root), function ($item) {
            return $item !== '.' && $item !== '..' && is_dir($this->root . '/' . $item);
        });
    }

    public function createBucket(string $name, array $options = []): bool
    {
        // Directory for this bucket's physical data folder
        $path = $this->root . '/' . $this->sanitize($name);
        if (is_dir($path)) {
            return true;
        }
        return mkdir($path, 0755, true);
    }

    public function deleteBucket(string $name): bool
    {
        $path = $this->root . '/' . $this->sanitize($name);
        if (!is_dir($path)) {
            return false;
        }

        // Clean directory recursively
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        return rmdir($path);
    }

    public function health(): array
    {
        if (is_dir($this->root) && is_writable($this->root)) {
            return ['status' => 'healthy', 'error' => null];
        }
        return ['status' => 'unhealthy', 'error' => "Root path is not writable: {$this->root}"];
    }

    protected function getPhysicalPath(string $hash): string
    {
        // physical storage folder e.g.: storage/mycloud/data/ab/cd/ef123...
        return $this->root . '/data/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
    }

    protected function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $name) ?: 'default';
    }
}
