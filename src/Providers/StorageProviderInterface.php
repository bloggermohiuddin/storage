<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

/**
 * StorageProviderInterface defines the contract for all storage backend providers.
 * Providers are completely decoupled from metadata databases, routers, or queues.
 */
interface StorageProviderInterface
{
    /**
     * Store a file from a local source path.
     *
     * @param  string $bucket
     * @param  string $key
     * @param  string $source   Absolute path to the source file
     * @param  array  $options  MIME type, visibility, etc.
     * @return string           The object key
     */
    public function put(string $bucket, string $key, string $source, array $options = []): string;

    /**
     * Retrieve raw file contents.
     *
     * @param  string $bucket
     * @param  string $key
     * @return string
     */
    public function get(string $bucket, string $key): string;

    /**
     * Delete an object.
     *
     * @param  string $bucket
     * @param  string $key
     * @return bool
     */
    public function delete(string $bucket, string $key): bool;

    /**
     * Check whether an object exists.
     *
     * @param  string $bucket
     * @param  string $key
     * @return bool
     */
    public function exists(string $bucket, string $key): bool;

    /**
     * Copy an object to another key within the same provider.
     *
     * @param  string $bucket
     * @param  string $fromKey
     * @param  string $toKey
     * @return bool
     */
    public function copy(string $bucket, string $fromKey, string $toKey): bool;

    /**
     * Move (rename) an object.
     *
     * @param  string $bucket
     * @param  string $fromKey
     * @param  string $toKey
     * @return bool
     */
    public function move(string $bucket, string $fromKey, string $toKey): bool;

    /**
     * Get metadata for an object.
     *
     * @param  string $bucket
     * @param  string $key
     * @return array  Must contain keys: size (int), mime_type (string), last_modified (int), etag (string)
     */
    public function metadata(string $bucket, string $key): array;

    /**
     * List all object keys matching the prefix.
     *
     * @param  string $bucket
     * @param  string $prefix
     * @return array  List of keys
     */
    public function listObjects(string $bucket, string $prefix = ''): array;

    /**
     * Open a readable stream for the object.
     *
     * @param  string $bucket
     * @param  string $key
     * @return resource Readable stream resource
     */
    public function streamRead(string $bucket, string $key);

    /**
     * Write to an object from an active stream resource.
     *
     * @param  string   $bucket
     * @param  string   $key
     * @param  resource $resource  Readable PHP stream resource
     * @param  array    $options
     * @return bool
     */
    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool;

    /**
     * Generate a time-limited signed temporary URL.
     *
     * @param  string $bucket
     * @param  string $key
     * @param  int    $expiry  Expiry time in seconds
     * @return string
     */
    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string;

    /**
     * List all buckets in the provider.
     *
     * @return array List of bucket names
     */
    public function listBuckets(): array;

    /**
     * Create a new bucket.
     *
     * @param  string $name
     * @param  array  $options
     * @return bool
     */
    public function createBucket(string $name, array $options = []): bool;

    /**
     * Delete a bucket.
     *
     * @param  string $name
     * @return bool
     */
    public function deleteBucket(string $name): bool;

    /**
     * Check connection/credentials health.
     *
     * @return array ['status' => 'healthy'|'unhealthy', 'error' => ?string]
     */
    public function health(): array;
}
