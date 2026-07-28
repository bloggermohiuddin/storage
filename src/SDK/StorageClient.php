<?php

declare(strict_types=1);

namespace StoragePlatform\SDK;

use StoragePlatform\Providers\ProviderFactory;
use StoragePlatform\Providers\StorageProviderInterface;
use StoragePlatform\Server\Database;

/**
 * StorageClient — clean PHP SDK for the Object Storage Platform.
 * Provides access to local or remote storage providers with zero code changes.
 *
 * Usage:
 *   $storage = StorageClient::disk('mycloud');
 *   $storage->put('my-bucket', 'docs/invoice.pdf', '/tmp/file.pdf');
 *   echo $storage->temporaryUrl('my-bucket', 'docs/invoice.pdf', 3600);
 */
class StorageClient
{
    protected static array $instances = [];

    /**
     * Get a storage disk instance by provider name or driver.
     *
     * @param  string|null $diskName
     * @return StorageProviderInterface
     */
    public static function disk(?string $diskName = null): StorageProviderInterface
    {
        $diskName = $diskName ?: 'mycloud';

        if (isset(self::$instances[$diskName])) {
            return self::$instances[$diskName];
        }

        $db = Database::getConnection();

        // Query provider config from DB
        $stmt = $db->prepare("SELECT * FROM storage_providers WHERE driver = :driver OR name = :name LIMIT 1");
        $stmt->execute(['driver' => $diskName, 'name' => $diskName]);
        $config = $stmt->fetch();

        if (!$config) {
            throw new \RuntimeException("Storage provider disk [{$diskName}] is not configured.");
        }

        $instance = ProviderFactory::make($config, $db);
        self::$instances[$diskName] = $instance;

        return $instance;
    }
}
