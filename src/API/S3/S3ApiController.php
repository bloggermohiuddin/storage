<?php

declare(strict_types=1);

namespace StoragePlatform\API\S3;

use StoragePlatform\Server\Database;
use StoragePlatform\Server\ObjectManager;
use StoragePlatform\Providers\ProviderFactory;

/**
 * S3ApiController — Dispatcher for Amazon S3 / Cloudflare R2 compatible REST operations.
 */
class S3ApiController
{
    protected \PDO $db;
    protected ObjectManager $objectManager;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->objectManager = new ObjectManager($this->db);
    }

    /**
     * Entrypoint for S3 request handling.
     */
    public function dispatch(): void
    {
        $authKey = SigV4Authenticator::authenticate();

        $method = $_SERVER['REQUEST_METHOD'];
        $uri = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
        $uri = trim($uri, '/');

        // Route: GET /
        if (empty($uri)) {
            if ($method === 'GET') {
                $this->listBuckets();
                return;
            }
            S3XmlResponse::error('InvalidRequest', 'Invalid request', '/', 400);
            return;
        }

        $parts = explode('/', $uri);
        $bucketName = array_shift($parts);
        $key = implode('/', $parts);

        // Resolve bucket
        $stmt = $this->db->prepare("SELECT * FROM buckets WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $bucketName]);
        $bucket = $stmt->fetch();

        if (!$bucket) {
            S3XmlResponse::error('NoSuchBucket', 'The specified bucket does not exist.', '/' . $bucketName, 404);
            return;
        }

        // Authorization check: if bucket is private and no authKey provided, reject
        if ($bucket['visibility'] === 'private' && !$authKey) {
            S3XmlResponse::error('AccessDenied', 'Access Denied', '/' . $bucketName . '/' . $key, 403);
            return;
        }

        // Multipart Upload Query Parameters
        $isUploads = isset($_GET['uploads']);
        $uploadId = $_GET['uploadId'] ?? null;
        $partNumber = isset($_GET['partNumber']) ? (int)$_GET['partNumber'] : null;

        // Bucket level request: GET /:bucket (List objects)
        if (empty($key)) {
            if ($method === 'GET') {
                $this->listObjects($bucket);
                return;
            }
            if ($method === 'PUT') {
                // Bucket creation request via S3 API
                header('Content-Type: application/xml');
                echo '<?xml version="1.0" encoding="UTF-8"?><CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>';
                return;
            }
            if ($method === 'DELETE') {
                // Bucket deletion
                $del = $this->db->prepare("DELETE FROM buckets WHERE id = :id");
                $del->execute(['id' => $bucket['id']]);
                http_response_code(204);
                return;
            }
        }

        // Object level & Multipart routes
        if ($isUploads && $method === 'POST') {
            $this->initiateMultipartUpload($bucket, $key);
            return;
        }

        if ($uploadId) {
            if ($method === 'PUT' && $partNumber !== null) {
                $this->uploadPart($bucket, $key, $uploadId, $partNumber);
                return;
            }
            if ($method === 'POST') {
                $this->completeMultipartUpload($bucket, $key, $uploadId);
                return;
            }
            if ($method === 'DELETE') {
                $this->abortMultipartUpload($bucket, $key, $uploadId);
                return;
            }
        }

        // Standard S3 Operations: GET, PUT, HEAD, DELETE, COPY
        switch ($method) {
            case 'GET':
                $this->getObject($bucket, $key);
                break;
            case 'PUT':
                $copySource = $_SERVER['HTTP_X_AMZ_COPY_SOURCE'] ?? null;
                if ($copySource) {
                    $this->copyObject($bucket, $key, ltrim($copySource, '/'));
                } else {
                    $this->putObject($bucket, $key);
                }
                break;
            case 'HEAD':
                $this->headObject($bucket, $key);
                break;
            case 'DELETE':
                $this->deleteObject($bucket, $key);
                break;
            default:
                S3XmlResponse::error('MethodNotAllowed', 'The specified method is not allowed.', '/' . $bucketName . '/' . $key, 405);
        }
    }

    /**
     * GET / -> List Buckets XML
     */
    protected function listBuckets(): void
    {
        $stmt = $this->db->query("SELECT * FROM buckets ORDER BY name ASC");
        $buckets = $stmt->fetchAll();

        header('Content-Type: application/xml; charset=utf-8');
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
        $owner = $xml->addChild('Owner');
        $owner->addChild('ID', 'local');
        $owner->addChild('DisplayName', 'Owner');

        $bContainer = $xml->addChild('Buckets');
        foreach ($buckets as $b) {
            $bucketXml = $bContainer->addChild('Bucket');
            $bucketXml->addChild('Name', htmlspecialchars($b['name']));
            $bucketXml->addChild('CreationDate', gmdate('Y-m-d\TH:i:s.000\Z', strtotime($b['created_at'])));
        }

        echo $xml->asXML();
    }

    /**
     * GET /:bucket -> List Objects XML
     */
    protected function listObjects(array $bucket): void
    {
        $prefix = $_GET['prefix'] ?? '';
        $stmt = $this->db->prepare("
            SELECT * FROM objects 
            WHERE bucket_id = :b_id AND is_deleted = 0 AND key LIKE :prefix 
            ORDER BY key ASC LIMIT 1000
        ");
        $stmt->execute(['b_id' => $bucket['id'], 'prefix' => $prefix . '%']);
        $objects = $stmt->fetchAll();

        header('Content-Type: application/xml; charset=utf-8');
        echo S3XmlResponse::listObjectsV2($bucket['name'], $objects, $prefix);
    }

    /**
     * GET /:bucket/*key -> Get Object Stream
     */
    protected function getObject(array $bucket, string $key): void
    {
        $stmt = $this->db->prepare("SELECT * FROM objects WHERE bucket_id = :b AND key = :k AND is_deleted = 0 LIMIT 1");
        $stmt->execute(['b' => $bucket['id'], 'k' => $key]);
        $obj = $stmt->fetch();

        if (!$obj) {
            S3XmlResponse::error('NoSuchKey', 'The specified key does not exist.', '/' . $bucket['name'] . '/' . $key, 404);
            return;
        }

        // Fetch provider
        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        $provider = ProviderFactory::make($providerConfig, $this->db);
        $stream = $provider->streamRead($bucket['name'], $key);

        if (!$stream) {
            S3XmlResponse::error('NoSuchKey', 'Failed to read object content.', '/' . $bucket['name'] . '/' . $key, 404);
            return;
        }

        header('Content-Type: ' . ($obj['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . $obj['size']);
        header('ETag: ' . ($obj['etag'] ?? '"' . $obj['hash_md5'] . '"'));
        header('x-amz-request-id: ' . bin2hex(random_bytes(8)));

        fpassthru($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        exit;
    }

    /**
     * PUT /:bucket/*key -> Upload Object
     */
    protected function putObject(array $bucket, string $key): void
    {
        $input = fopen('php://input', 'rb');
        if (!$input) {
            S3XmlResponse::error('InternalError', 'Failed to read request body', '/' . $bucket['name'] . '/' . $key, 500);
            return;
        }

        // Write stream to temp file
        $temp = sys_get_temp_dir() . '/s3_put_' . bin2hex(random_bytes(8)) . '.tmp';
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($input, $out);
        fclose($out);
        fclose($input);

        $mime = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';

        $stored = $this->objectManager->storeObject((int)$bucket['id'], $key, $temp, [
            'mime' => $mime,
        ]);

        @unlink($temp);

        header('ETag: "' . $stored['sha256'] . '"');
        header('x-amz-request-id: ' . bin2hex(random_bytes(8)));
        http_response_code(200);
    }

    /**
     * HEAD /:bucket/*key -> Object Metadata
     */
    protected function headObject(array $bucket, string $key): void
    {
        $stmt = $this->db->prepare("SELECT * FROM objects WHERE bucket_id = :b AND key = :k AND is_deleted = 0 LIMIT 1");
        $stmt->execute(['b' => $bucket['id'], 'k' => $key]);
        $obj = $stmt->fetch();

        if (!$obj) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . ($obj['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . $obj['size']);
        header('ETag: ' . ($obj['etag'] ?? '"' . $obj['hash_md5'] . '"'));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s GMT', strtotime($obj['updated_at'])));
        http_response_code(200);
    }

    /**
     * DELETE /:bucket/*key -> Delete Object
     */
    protected function deleteObject(array $bucket, string $key): void
    {
        $this->objectManager->deleteObject((int)$bucket['id'], $key);
        http_response_code(204);
    }

    /**
     * COPY Object: PUT /:bucket/*key with x-amz-copy-source
     */
    protected function copyObject(array $targetBucket, string $targetKey, string $sourcePath): void
    {
        $srcParts = explode('/', ltrim($sourcePath, '/'));
        $srcBucketName = array_shift($srcParts);
        $srcKey = implode('/', $srcParts);

        $stmt = $this->db->prepare("SELECT id FROM buckets WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $srcBucketName]);
        $srcBucket = $stmt->fetch();

        if (!$srcBucket) {
            S3XmlResponse::error('NoSuchBucket', 'Source bucket not found', '/' . $sourcePath, 404);
            return;
        }

        $objStmt = $this->db->prepare("SELECT * FROM objects WHERE bucket_id = :b AND key = :k LIMIT 1");
        $objStmt->execute(['b' => $srcBucket['id'], 'k' => $srcKey]);
        $srcObj = $objStmt->fetch();

        if (!$srcObj) {
            S3XmlResponse::error('NoSuchKey', 'Source key not found', '/' . $sourcePath, 404);
            return;
        }

        // Copy in DB and physical provider
        $insert = $this->db->prepare("
            INSERT INTO objects (bucket_id, key, size, mime_type, hash_sha256, hash_md5, etag, storage_path, metadata)
            VALUES (:b, :k, :size, :mime, :sha, :md5, :etag, :path, :meta)
        ");

        $insert->execute([
            'b' => $targetBucket['id'],
            'k' => $targetKey,
            'size' => $srcObj['size'],
            'mime' => $srcObj['mime_type'],
            'sha' => $srcObj['hash_sha256'],
            'md5' => $srcObj['hash_md5'],
            'etag' => $srcObj['etag'],
            'path' => $srcObj['storage_path'],
            'meta' => $srcObj['metadata'],
        ]);

        header('Content-Type: application/xml; charset=utf-8');
        echo S3XmlResponse::copyObject($srcObj['etag'] ?? '"' . $srcObj['hash_md5'] . '"', gmdate('Y-m-d\TH:i:s.000\Z'));
    }

    /**
     * POST /:bucket/*key?uploads -> Initiate Multipart Upload
     */
    protected function initiateMultipartUpload(array $bucket, string $key): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare("
            INSERT INTO multipart_uploads (upload_id, bucket_id, key, mime_type, status)
            VALUES (:uid, :b, :k, :mime, 'in_progress')
        ");
        $stmt->execute([
            'uid' => $uploadId,
            'b' => $bucket['id'],
            'k' => $key,
            'mime' => $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'
        ]);

        header('Content-Type: application/xml; charset=utf-8');
        echo S3XmlResponse::initiateMultipartUpload($bucket['name'], $key, $uploadId);
    }

    /**
     * PUT /:bucket/*key?uploadId=X&partNumber=Y -> Upload Part
     */
    protected function uploadPart(array $bucket, string $key, string $uploadId, int $partNumber): void
    {
        $stmt = $this->db->prepare("SELECT * FROM multipart_uploads WHERE upload_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $uploadId]);
        $mp = $stmt->fetch();

        if (!$mp) {
            S3XmlResponse::error('NoSuchUpload', 'Multipart upload not found', '/' . $bucket['name'] . '/' . $key, 404);
            return;
        }

        $tempDir = sys_get_temp_dir() . '/mp_' . $uploadId;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $partPath = $tempDir . '/part_' . $partNumber . '.part';
        $input = fopen('php://input', 'rb');
        $out = fopen($partPath, 'wb');
        stream_copy_to_stream($input, $out);
        fclose($out);
        fclose($input);

        $size = filesize($partPath);
        $etag = '"' . md5_file($partPath) . '"';

        $insert = $this->db->prepare("
            INSERT OR REPLACE INTO upload_parts (upload_id, part_number, etag, size, storage_path, hash_md5)
            VALUES (:uid, :pnum, :etag, :size, :path, :md5)
        ");
        $insert->execute([
            'uid' => $uploadId,
            'pnum' => $partNumber,
            'etag' => $etag,
            'size' => $size,
            'path' => $partPath,
            'md5' => md5_file($partPath)
        ]);

        header('ETag: ' . $etag);
        http_response_code(200);
    }

    /**
     * POST /:bucket/*key?uploadId=X -> Complete Multipart Upload
     */
    protected function completeMultipartUpload(array $bucket, string $key, string $uploadId): void
    {
        $partsStmt = $this->db->prepare("
            SELECT * FROM upload_parts WHERE upload_id = :uid ORDER BY part_number ASC
        ");
        $partsStmt->execute(['uid' => $uploadId]);
        $parts = $partsStmt->fetchAll();

        if (empty($parts)) {
            S3XmlResponse::error('InvalidPart', 'No parts uploaded', '/' . $bucket['name'] . '/' . $key, 400);
            return;
        }

        // Assemble parts into combined temp file
        $combinedTemp = sys_get_temp_dir() . '/mp_complete_' . $uploadId . '.tmp';
        $combined = fopen($combinedTemp, 'wb');

        foreach ($parts as $p) {
            if (is_file($p['storage_path'])) {
                $pr = fopen($p['storage_path'], 'rb');
                stream_copy_to_stream($pr, $combined);
                fclose($pr);
                @unlink($p['storage_path']);
            }
        }
        fclose($combined);

        $stored = $this->objectManager->storeObject((int)$bucket['id'], $key, $combinedTemp);
        @unlink($combinedTemp);

        $upd = $this->db->prepare("UPDATE multipart_uploads SET status = 'completed' WHERE upload_id = :uid");
        $upd->execute(['uid' => $uploadId]);

        header('Content-Type: application/xml; charset=utf-8');
        echo S3XmlResponse::completeMultipartUpload($bucket['name'], $key, '"' . $stored['sha256'] . '"');
    }

    /**
     * DELETE /:bucket/*key?uploadId=X -> Abort Multipart Upload
     */
    protected function abortMultipartUpload(array $bucket, string $key, string $uploadId): void
    {
        $partsStmt = $this->db->prepare("SELECT storage_path FROM upload_parts WHERE upload_id = :uid");
        $partsStmt->execute(['uid' => $uploadId]);
        foreach ($partsStmt->fetchAll() as $p) {
            if (is_file($p['storage_path'])) {
                @unlink($p['storage_path']);
            }
        }

        $del = $this->db->prepare("DELETE FROM multipart_uploads WHERE upload_id = :uid");
        $del->execute(['uid' => $uploadId]);

        http_response_code(204);
    }
}
