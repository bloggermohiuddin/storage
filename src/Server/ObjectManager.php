<?php

declare(strict_types=1);

namespace StoragePlatform\Server;

use StoragePlatform\Providers\ProviderFactory;
use StoragePlatform\API\Controllers\ServerInfoController;

/**
 * ObjectManager — tracks file metadata, performs hashing, and handles file uploads/downloads.
 */
class ObjectManager
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Upload an object physically to the provider and register it in SQLite.
     */
    public function storeObject(int $bucketId, string $key, string $tempFilePath, array $options = []): array
    {
        if (!is_file($tempFilePath) || !is_readable($tempFilePath)) {
            throw new \RuntimeException("Upload failed: source file not readable.");
        }

        // 1. Resolve bucket and provider config
        $stmt = $this->db->prepare("
            SELECT b.*, p.driver as provider_driver, p.options as provider_options
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            WHERE b.id = :bucket_id
            LIMIT 1
        ");
        $stmt->execute(['bucket_id' => $bucketId]);
        $bucket = $stmt->fetch();
        if (!$bucket) {
            throw new \RuntimeException("Bucket not found.");
        }

        // Fetch complete provider details
        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        // 2. Extract metrics & hashes
        $size = filesize($tempFilePath);
        $hashSha = hash_file('sha256', $tempFilePath);
        $hashMd5 = md5_file($tempFilePath);
        
        $mime = $options['mime'] ?? '';
        if ($mime === '') {
            if (function_exists('finfo_file')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tempFilePath) ?: 'application/octet-stream';
                finfo_close($finfo);
            } else {
                $mime = 'application/octet-stream';
            }
        }

        // 3. Upload physically to the provider
        $providerInstance = ProviderFactory::make($providerConfig, $this->db);
        $providerInstance->put($bucket['name'], $key, $tempFilePath, [
            'mime' => $mime,
            'visibility' => $bucket['visibility'] === 'public' ? 'public-read' : 'private',
        ]);

        // 4. Save metadata index in SQLite
        // If versioning is disabled, we delete/overwrite the existing key
        if (!$bucket['versioning']) {
            $del = $this->db->prepare("DELETE FROM objects WHERE bucket_id = :bucket_id AND key = :key");
            $del->execute(['bucket_id' => $bucketId, 'key' => $key]);
        }

        $versionId = $bucket['versioning'] ? bin2hex(random_bytes(8)) : null;

        $insert = $this->db->prepare("
            INSERT INTO objects (bucket_id, key, size, mime_type, hash_sha256, hash_md5, metadata, version_id, is_deleted)
            VALUES (:bucket_id, :key, :size, :mime_type, :hash_sha256, :hash_md5, :metadata, :version_id, 0)
        ");

        $insert->execute([
            'bucket_id' => $bucketId,
            'key' => $key,
            'size' => $size,
            'mime_type' => $mime,
            'hash_sha256' => $hashSha,
            'hash_md5' => $hashMd5,
            'metadata' => json_encode($options['metadata'] ?? []),
            'version_id' => $versionId,
        ]);

        return [
            'key' => $key,
            'size' => $size,
            'mime_type' => $mime,
            'sha256' => $hashSha,
            'version_id' => $versionId,
        ];
    }

    /**
     * Delete an object physically and from SQLite.
     */
    public function deleteObject(int $bucketId, string $key, ?string $versionId = null): bool
    {
        $stmt = $this->db->prepare("
            SELECT b.*, p.driver as provider_driver, p.options as provider_options
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            WHERE b.id = :bucket_id
            LIMIT 1
        ");
        $stmt->execute(['bucket_id' => $bucketId]);
        $bucket = $stmt->fetch();
        if (!$bucket) {
            return false;
        }

        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        // Instantiate provider
        $providerInstance = ProviderFactory::make($providerConfig, $this->db);

        // Delete physically
        $providerInstance->delete($bucket['name'], $key);

        // Delete from DB index
        if ($versionId) {
            $del = $this->db->prepare("
                DELETE FROM objects 
                WHERE bucket_id = :bucket_id AND key = :key AND version_id = :version_id
            ");
            return $del->execute([
                'bucket_id' => $bucketId,
                'key' => $key,
                'version_id' => $versionId
            ]);
        } else {
            $del = $this->db->prepare("
                DELETE FROM objects 
                WHERE bucket_id = :bucket_id AND key = :key
            ");
            return $del->execute([
                'bucket_id' => $bucketId,
                'key' => $key
            ]);
        }
    }

    /**
     * Generate access URL for an object (either native provider URL or custom signed stream).
     */
    public function getUrl(int $bucketId, string $key, int $expiry = 3600): string
    {
        $stmt = $this->db->prepare("
            SELECT b.*, p.driver as provider_driver, p.options as provider_options
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            WHERE b.id = :bucket_id
            LIMIT 1
        ");
        $stmt->execute(['bucket_id' => $bucketId]);
        $bucket = $stmt->fetch();
        if (!$bucket) {
            return '';
        }

        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        $providerInstance = ProviderFactory::make($providerConfig, $this->db);

        if ($bucket['visibility'] === 'public') {
            // Public objects get clean permanent S3-style direct URLs without signatures
            $baseUrl = rtrim($_ENV['APP_URL'] ?? ServerInfoController::detectBaseUrl(), '/');
            return $baseUrl . '/' . rawurlencode($bucket['name']) . '/' . rawurlencode($key);
        }

        // Private objects get time-limited signed URLs
        return $providerInstance->temporaryUrl($bucket['name'], $key, $expiry);
    }
}
