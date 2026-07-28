<?php

declare(strict_types=1);

namespace StoragePlatform\SDK;

use StoragePlatform\Providers\ProviderFactory;
use StoragePlatform\Providers\StorageProviderInterface;
use StoragePlatform\Server\Database;

/**
 * Storage Facade — Official PHP SDK for Self-Hosted Object Storage Platform.
 * Supports identical API across Local, Cloudflare R2, AWS S3, MinIO, FTP, and Memory.
 *
 * Example Usage:
 *   $storage = Storage::driver('local');
 *   $storage->bucket('uploads')->put('photo.jpg', '/path/to/file.jpg');
 *   $url = $storage->url('photo.jpg');
 *   $temp = $storage->temporaryUrl('photo.jpg', 3600);
 *   $list = $storage->list();
 *   $storage->delete('photo.jpg');
 */
class Storage
{
    protected StorageProviderInterface $provider;
    protected string $activeBucket = 'uploads';

    public function __construct(StorageProviderInterface $provider, string $defaultBucket = 'uploads')
    {
        $this->provider = $provider;
        $this->activeBucket = $defaultBucket;
    }

    /**
     * Get a storage instance for a specified driver or provider configuration.
     */
    public static function driver(?string $driverName = null): self
    {
        $driverName = $driverName ?: 'local';
        $db = Database::getConnection();

        // 1. Query access key config or provider config
        $stmt = $db->prepare("
            SELECT p.* 
            FROM storage_providers p 
            WHERE p.driver = :driver OR p.name = :name 
            LIMIT 1
        ");
        $stmt->execute(['driver' => $driverName, 'name' => $driverName]);
        $config = $stmt->fetch();

        if (!$config) {
            // Fallback for default local driver if DB empty
            $config = [
                'name' => 'Local Default',
                'driver' => 'local',
                'options' => json_encode(['root' => dirname(__DIR__, 2) . '/storage', 'url' => 'http://localhost:8080'])
            ];
        }

        $provider = ProviderFactory::make($config, $db);
        return new self($provider, $config['bucket'] ?? 'uploads');
    }

    /**
     * Set active bucket for subsequent operations (fluent).
     */
    public function bucket(string $bucketName): self
    {
        $clone = clone $this;
        $clone->activeBucket = $bucketName;
        return $clone;
    }

    /**
     * Store file under active bucket.
     */
    public function put(string $key, string $source, array $options = []): string
    {
        return $this->provider->put($this->activeBucket, $key, $source, $options);
    }

    /**
     * Get raw file content.
     */
    public function get(string $key): string
    {
        return $this->provider->get($this->activeBucket, $key);
    }

    /**
     * Delete object from active bucket.
     */
    public function delete(string $key = ''): bool
    {
        if ($key === '') {
            return $this->provider->deleteBucket($this->activeBucket);
        }
        return $this->provider->delete($this->activeBucket, $key);
    }

    /**
     * Check if object exists.
     */
    public function exists(string $key): bool
    {
        return $this->provider->exists($this->activeBucket, $key);
    }

    /**
     * Copy object.
     */
    public function copy(string $fromKey, string $toKey): bool
    {
        return $this->provider->copy($this->activeBucket, $fromKey, $toKey);
    }

    /**
     * Move (rename) object.
     */
    public function move(string $fromKey, string $toKey): bool
    {
        return $this->provider->move($this->activeBucket, $fromKey, $toKey);
    }

    /**
     * Get permanent public or standard access URL.
     */
    public function url(string $key): string
    {
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';
        return rtrim($baseUrl, '/') . '/' . rawurlencode($this->activeBucket) . '/' . rawurlencode($key);
    }

    /**
     * Generate time-limited signed URL.
     */
    public function temporaryUrl(string $key, int $expiry = 3600): string
    {
        return $this->provider->temporaryUrl($this->activeBucket, $key, $expiry);
    }

    /**
     * List all objects matching prefix in active bucket.
     */
    public function list(string $prefix = ''): array
    {
        return $this->provider->listObjects($this->activeBucket, $prefix);
    }

    /**
     * Get object metadata.
     */
    public function metadata(string $key): array
    {
        return $this->provider->metadata($this->activeBucket, $key);
    }

    /**
     * Get lower-level provider instance.
     */
    public function getProvider(): StorageProviderInterface
    {
        return $this->provider;
    }
}
