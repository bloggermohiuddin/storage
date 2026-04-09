<?php

declare(strict_types=1);

namespace StorageSDK\Adapters;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use StorageSDK\Contracts\StorageInterface;
use StorageSDK\Exceptions\FileNotFoundException;
use StorageSDK\Exceptions\StorageException;
use StorageSDK\Support\Logger;

/**
 * S3Adapter — production-ready adapter for any S3-compatible object store.
 *
 * Tested with:
 *  • Amazon S3       (region = us-east-1, no custom endpoint)
 *  • Cloudflare R2   (endpoint = https://<accountid>.r2.cloudflarestorage.com)
 *  • Backblaze B2    (endpoint = https://s3.<region>.backblazeb2.com)
 *
 * Switching between providers requires only a config change.
 */
class S3Adapter implements StorageInterface
{
    private S3ClientInterface $client;
    private string $bucket;
    private string $publicUrl;   // e.g. https://files.example.com (custom domain / CDN)
    private Logger $logger;

    /**
     * @param array  $config  Disk config from config/storage.php
     * @param Logger $logger
     *
     * Expected config keys:
     *   endpoint  (optional, for R2/B2)
     *   region    ('auto' for R2, real region for S3)
     *   bucket
     *   key       (access key ID)
     *   secret    (secret access key)
     *   url       (public base URL, e.g. custom domain)
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->bucket    = $config['bucket'] ?? throw new StorageException('S3 bucket is required');
        $this->publicUrl = rtrim($config['url'] ?? '', '/');
        $this->logger    = $logger;

        $clientConfig = [
            'version'     => 'latest',
            'region'      => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key'    => $config['key']    ?? throw new StorageException('S3 key is required'),
                'secret' => $config['secret'] ?? throw new StorageException('S3 secret is required'),
            ],
            // Cloudflare R2 / B2 require path-style endpoint
            'use_path_style_endpoint' => true,
        ];

        // Custom endpoint (R2, B2, MinIO, etc.)
        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        $this->client = new S3Client($clientConfig);
    }

    // =========================================================================
    // Allow injecting a mock client for testing
    // =========================================================================

    public function setClient(S3ClientInterface $client): void
    {
        $this->client = $client;
    }

    // =========================================================================
    // StorageInterface implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function put(string $key, string $source, array $options = []): string
    {
        if (! is_readable($source)) {
            throw new StorageException("Source file not readable: {$source}");
        }

        try {
            $args = [
                'Bucket'     => $this->bucket,
                'Key'        => $key,
                'SourceFile' => $source,
            ];

            // Visibility: default public-read, override for private
            $acl = $options['visibility'] ?? 'public-read';
            // Note: Cloudflare R2 does not support ACLs — omit if using R2
            if (empty($options['no_acl'])) {
                $args['ACL'] = $acl;
            }

            // MIME type override
            if (! empty($options['mime'])) {
                $args['ContentType'] = $options['mime'];
            } else {
                // Auto-detect
                $args['ContentType'] = $this->detectMime($source);
            }

            // Cache-Control
            if (! empty($options['cache_control'])) {
                $args['CacheControl'] = $options['cache_control'];
            }

            $this->client->putObject($args);

            $this->logger->info('S3:PUT', [
                'key'    => $key,
                'bucket' => $this->bucket,
                'size'   => filesize($source),
            ]);

            return $key;
        } catch (S3Exception $e) {
            $this->logger->error('S3:PUT failed', ['key' => $key, 'error' => $e->getMessage()]);
            throw new StorageException("Failed to put [{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            return (string) $result['Body'];
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                throw new FileNotFoundException($key);
            }
            throw new StorageException("Failed to get [{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            $this->logger->info('S3:DELETE', ['key' => $key]);
            return true;
        } catch (S3Exception $e) {
            $this->logger->error('S3:DELETE failed', ['key' => $key, 'error' => $e->getMessage()]);
            throw new StorageException("Failed to delete [{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * {@inheritdoc}
     *
     * If a custom CDN URL is configured, it uses that.
     * Otherwise falls back to native S3 URL.
     */
    public function url(string $key): string
    {
        if ($this->publicUrl !== '') {
            return $this->publicUrl . '/' . ltrim($key, '/');
        }

        // Native S3 URL (note: R2 does not support native public URLs without custom domain)
        return $this->client->getObjectUrl($this->bucket, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $key): int
    {
        $meta = $this->headObject($key);
        return (int) ($meta['ContentLength'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $key): string
    {
        $meta = $this->headObject($key);
        return (string) ($meta['ContentType'] ?? 'application/octet-stream');
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $key): int
    {
        $meta = $this->headObject($key);
        $dt   = $meta['LastModified'] ?? null;

        if ($dt instanceof \DateTimeInterface) {
            return $dt->getTimestamp();
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     *
     * S3 server-side copy — no bandwidth used, extremely efficient.
     */
    public function copy(string $from, string $to): bool
    {
        try {
            $this->client->copyObject([
                'Bucket'     => $this->bucket,
                'Key'        => $to,
                'CopySource' => $this->bucket . '/' . ltrim($from, '/'),
            ]);

            $this->logger->info('S3:COPY', ['from' => $from, 'to' => $to]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                throw new FileNotFoundException($from);
            }
            throw new StorageException("Failed to copy [{$from}→{$to}]: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $from, string $to): bool
    {
        $this->copy($from, $to);
        $this->delete($from);
        $this->logger->info('S3:MOVE', ['from' => $from, 'to' => $to]);
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Generates a presigned URL using AWS SDK's built-in presigner.
     * Works with S3, R2, and B2 (all honour AWS-style presigned URLs).
     */
    public function generateSignedUrl(string $key, int $expiry = 3600): string
    {
        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            $request = $this->client->createPresignedRequest($cmd, '+' . $expiry . ' seconds');
            return (string) $request->getUri();
        } catch (S3Exception $e) {
            throw new StorageException(
                "Failed to generate signed URL for [{$key}]: " . $e->getMessage(), 0, $e
            );
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Run a HeadObject request and return metadata.
     */
    private function headObject(string $key): array
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            return $result->toArray();
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                throw new FileNotFoundException($key);
            }
            throw new StorageException("HeadObject failed for [{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime !== false) {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }
}
