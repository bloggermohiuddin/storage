<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * ProviderFactory — instantiates concrete storage provider classes based on configuration.
 */
class ProviderFactory
{
    /**
     * Create a storage provider instance from config.
     *
     * @param  array     $config
     * @param  \PDO|null $db      Injected PDO context (required for mycloud provider listings)
     * @return StorageProviderInterface
     */
    public static function make(array $config, ?\PDO $db = null): StorageProviderInterface
    {
        // Decode JSON options column if stored in database
        if (!empty($config['options']) && is_string($config['options'])) {
            $decodedOptions = json_decode($config['options'], true);
            if (is_array($decodedOptions)) {
                $config = array_merge($config, $decodedOptions);
            }
        }

        $driver = $config['driver'] ?? throw new \InvalidArgumentException("Storage provider configuration is missing 'driver'.");
        $projectRoot = dirname(__DIR__, 2);

        switch (strtolower($driver)) {
            case 'local':
                $root = $config['root'] ?? ($projectRoot . '/storage/uploads');
                if (!is_dir($root)) @mkdir($root, 0755, true);
                return new LocalProvider($root, $config['url'] ?? '');

            case 'mycloud':
                $root = $config['root'] ?? ($projectRoot . '/storage/mycloud');
                if (!is_dir($root)) @mkdir($root, 0755, true);
                return new MyCloudProvider($root, $config['url'] ?? '', $db);

            case 's3':
                return new S3Provider($config);
            case 'r2':
                return new R2Provider($config);
            case 'b2':
                return new B2Provider($config);
            case 'minio':
                return new MinIOProvider($config);
            case 'ftp':
                return new FTPDriver($config);
            case 'sftp':
                return new FTPDriver($config); // Fallback adapter
            case 'memory':
                return new MemoryDriver($config);
            case 'custom':
                return new LocalProvider($config['root'] ?? ($projectRoot . '/storage'), $config['url'] ?? '');
            default:
                throw new \InvalidArgumentException("Unsupported storage provider driver: [{$driver}]");
        }
    }
}
