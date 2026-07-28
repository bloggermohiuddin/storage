<?php

declare(strict_types=1);

namespace StoragePlatform\Providers;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

/**
 * BaseS3Provider — shared abstract base class for S3-compatible providers.
 */
abstract class BaseS3Provider implements StorageProviderInterface
{
    protected S3Client $client;
    protected string $defaultBucket;
    protected string $publicUrl;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->defaultBucket = $config['bucket'] ?? '';
        $this->publicUrl = rtrim($config['url'] ?? '', '/');

        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? true,
        ];

        if (!empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        $this->client = new S3Client($clientConfig);
    }

    public function put(string $bucket, string $key, string $source, array $options = []): string
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $args = [
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $source,
            ];

            // Set ACL if provider supports it and not overridden
            if (empty($options['no_acl']) && empty($this->config['no_acl'])) {
                $args['ACL'] = $options['visibility'] ?? 'public-read';
            }

            if (!empty($options['mime'])) {
                $args['ContentType'] = $options['mime'];
            }

            if (!empty($options['cache_control'])) {
                $args['CacheControl'] = $options['cache_control'];
            }

            $this->client->putObject($args);
            return $key;
        } catch (S3Exception $e) {
            throw new \RuntimeException("S3 putObject failed for [{$bucket}/{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $bucket, string $key): string
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $result = $this->client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            return (string)$result['Body'];
        } catch (S3Exception $e) {
            throw new \RuntimeException("S3 getObject failed for [{$bucket}/{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $bucket, string $key): bool
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $this->client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function exists(string $bucket, string $key): bool
    {
        $bucket = $bucket ?: $this->defaultBucket;
        return $this->client->doesObjectExist($bucket, $key);
    }

    public function copy(string $bucket, string $fromKey, string $toKey): bool
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $this->client->copyObject([
                'Bucket' => $bucket,
                'Key' => $toKey,
                'CopySource' => $bucket . '/' . ltrim($fromKey, '/'),
            ]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
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
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $result = $this->client->headObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            
            $meta = $result->toArray();
            $dt = $meta['LastModified'] ?? null;
            $timestamp = ($dt instanceof \DateTimeInterface) ? $dt->getTimestamp() : 0;

            return [
                'size' => (int)($meta['ContentLength'] ?? 0),
                'mime_type' => (string)($meta['ContentType'] ?? 'application/octet-stream'),
                'last_modified' => $timestamp,
                'etag' => trim((string)($meta['ETag'] ?? ''), '"'),
            ];
        } catch (S3Exception $e) {
            throw new \RuntimeException("S3 headObject failed for [{$bucket}/{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $bucket = $bucket ?: $this->defaultBucket;
        $keys = [];

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
            ]);

            foreach ($paginator as $result) {
                $contents = $result->get('Contents') ?? [];
                foreach ($contents as $object) {
                    if (isset($object['Key'])) {
                        $keys[] = $object['Key'];
                    }
                }
            }
            return $keys;
        } catch (S3Exception $e) {
            throw new \RuntimeException("S3 listObjects failed for bucket [{$bucket}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function streamRead(string $bucket, string $key)
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            // Register stream wrapper to open stream context if possible, 
            // or fetch the object body stream directly from Guzzle/AWS result
            $result = $this->client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            
            $body = $result['Body'];
            return $body->detachStream() ?: false;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function streamWrite(string $bucket, string $key, $resource, array $options = []): bool
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $args = [
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $resource,
            ];

            if (empty($options['no_acl']) && empty($this->config['no_acl'])) {
                $args['ACL'] = $options['visibility'] ?? 'public-read';
            }

            if (!empty($options['mime'])) {
                $args['ContentType'] = $options['mime'];
            }

            $this->client->putObject($args);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function temporaryUrl(string $bucket, string $key, int $expiry = 3600): string
    {
        $bucket = $bucket ?: $this->defaultBucket;

        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $request = $this->client->createPresignedRequest($cmd, '+' . $expiry . ' seconds');
            return (string)$request->getUri();
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to generate signed URL for [{$bucket}/{$key}]: " . $e->getMessage(), 0, $e);
        }
    }

    public function listBuckets(): array
    {
        try {
            $result = $this->client->listBuckets();
            $buckets = [];
            foreach ($result['Buckets'] ?? [] as $b) {
                if (isset($b['Name'])) {
                    $buckets[] = $b['Name'];
                }
            }
            return $buckets;
        } catch (S3Exception $e) {
            return [];
        }
    }

    public function createBucket(string $name, array $options = []): bool
    {
        try {
            $args = ['Bucket' => $name];
            // LocationConstraint is needed for non us-east-1 buckets in standard S3
            $region = $this->config['region'] ?? 'us-east-1';
            if ($region !== 'us-east-1' && $region !== 'auto') {
                $args['CreateBucketConfiguration'] = [
                    'LocationConstraint' => $region,
                ];
            }

            $this->client->createBucket($args);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function deleteBucket(string $name): bool
    {
        try {
            $this->client->deleteBucket(['Bucket' => $name]);
            return true;
        } catch (S3Exception $e) {
            return false;
        }
    }

    public function health(): array
    {
        try {
            if ($this->defaultBucket !== '') {
                $this->client->headBucket(['Bucket' => $this->defaultBucket]);
            } else {
                $this->client->listBuckets();
            }
            return ['status' => 'healthy', 'error' => null];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
}
