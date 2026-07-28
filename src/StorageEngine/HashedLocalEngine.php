<?php

declare(strict_types=1);

namespace StoragePlatform\StorageEngine;

/**
 * HashedLocalEngine — High-performance local storage engine.
 * Stores files in nested 2-level hash subdirectories:
 * storage/buckets/<bucket>/<a1>/<b2>/<sha256>.data
 * metadata/buckets/<bucket>/<a1>/<b2>/<sha256>.json
 */
class HashedLocalEngine
{
    protected string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        $this->ensureDirectories();
    }

    /**
     * Ensure core base directories exist.
     */
    public function ensureDirectories(): void
    {
        $dirs = [
            $this->baseDir . '/buckets',
            $this->baseDir . '/metadata',
            $this->baseDir . '/tmp',
            $this->baseDir . '/multipart',
            $this->baseDir . '/logs',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new \RuntimeException("Failed to create engine directory: {$dir}");
            }
        }
    }

    /**
     * Compute hashed path relative to bucket root.
     * e.g., bucket=uploads, key=file.txt => buckets/uploads/a1/b2/<sha256>.data
     */
    public function getHashedPath(string $bucket, string $key): string
    {
        $bucket = $this->sanitizeBucket($bucket);
        $hash = hash('sha256', $key);
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        return "{$this->baseDir}/buckets/{$bucket}/{$dir1}/{$dir2}/{$hash}.data";
    }

    /**
     * Compute metadata path for an object.
     */
    public function getMetadataPath(string $bucket, string $key): string
    {
        $bucket = $this->sanitizeBucket($bucket);
        $hash = hash('sha256', $key);
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        return "{$this->baseDir}/metadata/{$bucket}/{$dir1}/{$dir2}/{$hash}.json";
    }

    /**
     * Get temporary file path.
     */
    public function getTempPath(string $prefix = 'tmp_'): string
    {
        return $this->baseDir . '/tmp/' . uniqid($prefix, true) . '.tmp';
    }

    /**
     * Get multipart upload directory.
     */
    public function getMultipartDir(string $uploadId): string
    {
        $dir = $this->baseDir . '/multipart/' . preg_replace('/[^a-zA-Z0-9\-_]/', '', $uploadId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Write file to storage using local path.
     */
    public function write(string $bucket, string $key, string $sourcePath, array $options = []): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException("Source file for write is invalid or unreadable: [{$sourcePath}]");
        }

        $destPath = $this->getHashedPath($bucket, $key);
        $metaPath = $this->getMetadataPath($bucket, $key);

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            throw new \RuntimeException("Failed to create hashed target directory: {$destDir}");
        }

        $metaDir = dirname($metaPath);
        if (!is_dir($metaDir) && !mkdir($metaDir, 0755, true)) {
            throw new \RuntimeException("Failed to create metadata directory: {$metaDir}");
        }

        // Copy atomically via temp file
        $tempDest = $destPath . '.tmp_' . bin2hex(random_bytes(4));
        if (!copy($sourcePath, $tempDest)) {
            throw new \RuntimeException("Failed to copy file to temp destination: {$tempDest}");
        }
        rename($tempDest, $destPath);
        chmod($destPath, 0644);

        // Compute object metrics
        $size = filesize($destPath);
        $sha256 = hash_file('sha256', $destPath);
        $md5 = md5_file($destPath);

        $mime = $options['mime'] ?? '';
        if (empty($mime) && function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $destPath) ?: 'application/octet-stream';
            finfo_close($finfo);
        }

        $metaData = [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $size,
            'sha256' => $sha256,
            'md5' => $md5,
            'etag' => '"' . $md5 . '"',
            'mime_type' => $mime ?: 'application/octet-stream',
            'updated_at' => time(),
            'options' => $options,
        ];

        file_put_contents($metaPath, json_encode($metaData, JSON_PRETTY_PRINT));

        return $metaData;
    }

    /**
     * Write file from open PHP stream.
     */
    public function writeStream(string $bucket, string $key, $resource, array $options = []): array
    {
        $temp = $this->getTempPath('stream_');
        $out = fopen($temp, 'wb');
        if (!$out) {
            throw new \RuntimeException("Failed to create temp stream file.");
        }

        stream_copy_to_stream($resource, $out);
        fclose($out);

        try {
            $result = $this->write($bucket, $key, $temp, $options);
            @unlink($temp);
            return $result;
        } catch (\Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }

    /**
     * Read content as string.
     */
    public function read(string $bucket, string $key): string
    {
        $path = $this->getHashedPath($bucket, $key);
        if (!is_file($path)) {
            throw new \RuntimeException("Object not found: [{$bucket}/{$key}]");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: [{$path}]");
        }
        return $content;
    }

    /**
     * Open readable stream.
     */
    public function readStream(string $bucket, string $key)
    {
        $path = $this->getHashedPath($bucket, $key);
        if (!is_file($path)) {
            return false;
        }
        return fopen($path, 'rb');
    }

    /**
     * Check if object exists.
     */
    public function exists(string $bucket, string $key): bool
    {
        return is_file($this->getHashedPath($bucket, $key));
    }

    /**
     * Delete object and metadata file.
     */
    public function delete(string $bucket, string $key): bool
    {
        $path = $this->getHashedPath($bucket, $key);
        $metaPath = $this->getMetadataPath($bucket, $key);

        $deleted = false;
        if (is_file($path)) {
            $deleted = unlink($path);
        }
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }

        return $deleted;
    }

    /**
     * Copy object.
     */
    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        $srcPath = $this->getHashedPath($bucket, $fromKey);
        if (!is_file($srcPath)) {
            return false;
        }

        $meta = $this->getMetadata($bucket, $fromKey);
        $this->write($bucket, $toKey, $srcPath, $meta['options'] ?? []);
        return true;
    }

    /**
     * Move object.
     */
    public function move(string $bucket, string $fromKey, string $toKey): bool
    {
        if ($this->copy($bucket, $fromKey, $toKey)) {
            return $this->delete($bucket, $fromKey);
        }
        return false;
    }

    /**
     * Read object metadata.
     */
    public function getMetadata(string $bucket, string $key): array
    {
        $metaPath = $this->getMetadataPath($bucket, $key);
        if (is_file($metaPath)) {
            $json = file_get_contents($metaPath);
            if ($json !== false) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $filePath = $this->getHashedPath($bucket, $key);
        if (!is_file($filePath)) {
            throw new \RuntimeException("Object not found: [{$bucket}/{$key}]");
        }

        $size = filesize($filePath);
        $mtime = filemtime($filePath);
        $md5 = md5_file($filePath);

        return [
            'bucket' => $bucket,
            'key' => $key,
            'size' => $size,
            'sha256' => hash_file('sha256', $filePath),
            'md5' => $md5,
            'etag' => '"' . $md5 . '"',
            'mime_type' => 'application/octet-stream',
            'updated_at' => $mtime ?: time(),
            'options' => [],
        ];
    }

    /**
     * Clean up bucket directory.
     */
    public function deleteBucketDir(string $bucket): bool
    {
        $bucket = $this->sanitizeBucket($bucket);
        $dir = $this->baseDir . '/buckets/' . $bucket;
        $metaDir = $this->baseDir . '/metadata/' . $bucket;

        $this->removeDirectory($dir);
        $this->removeDirectory($metaDir);

        return true;
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        @rmdir($dir);
    }

    protected function sanitizeBucket(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $name) ?: 'uploads';
    }
}
