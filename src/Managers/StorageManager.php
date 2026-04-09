<?php

declare(strict_types=1);

namespace StorageSDK\Managers;

use StorageSDK\Adapters\LocalAdapter;
use StorageSDK\Adapters\S3Adapter;
use StorageSDK\Contracts\StorageInterface;
use StorageSDK\Exceptions\StorageException;
use StorageSDK\Support\FileValidator;
use StorageSDK\Support\KeyGenerator;
use StorageSDK\Support\Logger;

/**
 * StorageManager — the central facade for the Storage SDK.
 *
 * Resolves the correct adapter based on config, caches adapter instances,
 * and exposes a high-level API that wraps StorageInterface with
 * key generation and file validation.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │  Usage                                                               │
 * │                                                                      │
 * │  $storage = StorageManager::disk('local');                           │
 * │  $key     = $storage->putFile('patients', $_FILES['x']['tmp_name']); │
 * │  echo $storage->url($key);                                           │
 * └──────────────────────────────────────────────────────────────────────┘
 */
class StorageManager
{
    /** @var array Resolved adapter instances, keyed by disk name */
    private static array $resolved = [];

    /** @var array The loaded config (from config/storage.php) */
    private static array $config = [];

    /** @var StorageInterface The current adapter */
    private StorageInterface $adapter;

    /** @var string The disk name this manager wraps */
    private string $diskName;

    /** @var Logger */
    private Logger $logger;

    // =========================================================================
    // Static factory
    // =========================================================================

    /**
     * Get a StorageManager instance for the given disk.
     *
     * @param  string|null $disk  Disk name from config (null = use default)
     * @return static
     */
    public static function disk(?string $disk = null): static
    {
        $config   = static::getConfig();
        $diskName = $disk ?? $config['default'] ?? 'local';

        if (! isset($config['disks'][$diskName])) {
            throw new StorageException("Disk [{$diskName}] is not configured.");
        }

        return new static($diskName, $config);
    }

    /**
     * Load or return the cached storage config.
     */
    public static function getConfig(): array
    {
        if (empty(static::$config)) {
            $configPath = static::findConfigPath();
            if (! is_file($configPath)) {
                throw new StorageException("Config file not found at: {$configPath}");
            }
            static::$config = require $configPath;
        }

        return static::$config;
    }

    /**
     * Override config programmatically (useful for testing).
     */
    public static function setConfig(array $config): void
    {
        static::$config   = $config;
        static::$resolved = []; // Invalidate resolved adapters
    }

    // =========================================================================
    // High-level API (wraps StorageInterface + key generation + validation)
    // =========================================================================

    /**
     * Upload a file with automatic key generation and optional validation.
     *
     * @param  string $prefix          Folder prefix, e.g. "patients" or "invoices"
     * @param  string $sourcePath      Absolute path to the source file
     * @param  string $originalName    Original filename (for extension detection)
     * @param  array  $options         Options: ['validate' => true, 'date_folders' => true, ...]
     * @return string                  Generated object key
     */
    public function putFile(
        string $prefix,
        string $sourcePath,
        string $originalName = '',
        array $options = []
    ): string {
        // Validate if requested (default: true)
        if ($options['validate'] ?? true) {
            $validator = new FileValidator(
                maxSize: (int) ($options['max_size'] ?? 20971520),
                allowedMimes: $options['allowed_mimes'] ?? null
            );
            $validator->validate($sourcePath, $originalName ?: basename($sourcePath));
        }

        // Generate a unique key
        $key = KeyGenerator::generate(
            originalFilename: $originalName ?: basename($sourcePath),
            prefix: $prefix,
            dateFolders: (bool) ($options['date_folders'] ?? true)
        );

        return $this->adapter->put($key, $sourcePath, $options);
    }

    // =========================================================================
    // Direct pass-through to the underlying adapter
    // =========================================================================

    public function put(string $key, string $source, array $options = []): string
    {
        return $this->adapter->put($key, $source, $options);
    }

    public function get(string $key): string
    {
        return $this->adapter->get($key);
    }

    public function delete(string $key): bool
    {
        return $this->adapter->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->adapter->exists($key);
    }

    public function url(string $key): string
    {
        return $this->adapter->url($key);
    }

    public function size(string $key): int
    {
        return $this->adapter->size($key);
    }

    public function mimeType(string $key): string
    {
        return $this->adapter->mimeType($key);
    }

    public function lastModified(string $key): int
    {
        return $this->adapter->lastModified($key);
    }

    public function copy(string $from, string $to): bool
    {
        return $this->adapter->copy($from, $to);
    }

    public function move(string $from, string $to): bool
    {
        return $this->adapter->move($from, $to);
    }

    public function generateSignedUrl(string $key, int $expiry = 3600): string
    {
        return $this->adapter->generateSignedUrl($key, $expiry);
    }

    /**
     * Access the raw adapter (for advanced usage / testing).
     */
    public function getAdapter(): StorageInterface
    {
        return $this->adapter;
    }

    /**
     * Get the current disk name.
     */
    public function getDiskName(): string
    {
        return $this->diskName;
    }

    // =========================================================================
    // Private constructor + adapter resolution
    // =========================================================================

    private function __construct(string $diskName, array $config)
    {
        $this->diskName = $diskName;
        $diskConfig     = $config['disks'][$diskName];

        // Build logger
        $logPath     = $config['log_path'] ?? __DIR__ . '/../../logs/storage.log';
        $loggingOn   = (bool) ($config['logging'] ?? true);
        $this->logger = new Logger($logPath, $loggingOn);

        // Resolve (or reuse cached) adapter
        if (! isset(static::$resolved[$diskName])) {
            static::$resolved[$diskName] = $this->buildAdapter($diskConfig);
        }

        $this->adapter = static::$resolved[$diskName];
    }

    /**
     * Build the correct adapter based on the disk's 'driver' value.
     */
    private function buildAdapter(array $diskConfig): StorageInterface
    {
        $driver = $diskConfig['driver'] ?? throw new StorageException("Disk driver not specified.");

        return match ($driver) {
            'local' => $this->buildLocalAdapter($diskConfig),
            's3'    => $this->buildS3Adapter($diskConfig),
            default => throw new StorageException("Unsupported storage driver: [{$driver}]"),
        };
    }

    private function buildLocalAdapter(array $config): LocalAdapter
    {
        $root   = $config['root']   ?? throw new StorageException("Local driver requires 'root'");
        $url    = $config['url']    ?? '';
        $secret = $config['signed_url_secret'] ?? $this->defaultSignedSecret();

        return new LocalAdapter($root, $url, $secret, $this->logger);
    }

    private function buildS3Adapter(array $config): S3Adapter
    {
        return new S3Adapter($config, $this->logger);
    }

    private static function findConfigPath(): string
    {
        // Walk up from this file to locate config/storage.php
        $dir = dirname(__DIR__, 2);
        return $dir . '/config/storage.php';
    }

    private function defaultSignedSecret(): string
    {
        // In production, always set this explicitly in config
        return 'change-this-secret-in-production-' . php_uname('n');
    }
}
