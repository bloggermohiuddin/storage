<?php

declare(strict_types=1);

namespace StoragePlatform\API\S3;

/**
 * S3XmlResponse — Formats S3 protocol compliant XML responses.
 */
class S3XmlResponse
{
    /**
     * Build ListBucketResult (ListObjectsV2) XML.
     */
    public static function listObjectsV2(string $bucketName, array $objects, string $prefix = '', int $maxKeys = 1000): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
        $xml->addChild('Name', htmlspecialchars($bucketName));
        $xml->addChild('Prefix', htmlspecialchars($prefix));
        $xml->addChild('KeyCount', (string)count($objects));
        $xml->addChild('MaxKeys', (string)$maxKeys);
        $xml->addChild('IsTruncated', 'false');

        foreach ($objects as $obj) {
            $contents = $xml->addChild('Contents');
            $contents->addChild('Key', htmlspecialchars($obj['key']));
            $contents->addChild('LastModified', gmdate('Y-m-d\TH:i:s.000\Z', strtotime($obj['created_at'] ?? 'now')));
            $contents->addChild('ETag', $obj['etag'] ?? '"' . ($obj['hash_md5'] ?? md5($obj['key'])) . '"');
            $contents->addChild('Size', (string)$obj['size']);
            $contents->addChild('StorageClass', 'STANDARD');

            $owner = $contents->addChild('Owner');
            $owner->addChild('ID', 'local');
            $owner->addChild('DisplayName', 'Owner');
        }

        return $xml->asXML() ?: '';
    }

    /**
     * Build CopyObjectResult XML.
     */
    public static function copyObject(string $etag, string $lastModified): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
        $xml->addChild('LastModified', htmlspecialchars($lastModified));
        $xml->addChild('ETag', htmlspecialchars($etag));
        return $xml->asXML() ?: '';
    }

    /**
     * Build InitiateMultipartUploadResult XML.
     */
    public static function initiateMultipartUpload(string $bucket, string $key, string $uploadId): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
        $xml->addChild('Bucket', htmlspecialchars($bucket));
        $xml->addChild('Key', htmlspecialchars($key));
        $xml->addChild('UploadId', htmlspecialchars($uploadId));
        return $xml->asXML() ?: '';
    }

    /**
     * Build CompleteMultipartUploadResult XML.
     */
    public static function completeMultipartUpload(string $bucket, string $key, string $etag, string $location = ''): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
        $xml->addChild('Location', htmlspecialchars($location));
        $xml->addChild('Bucket', htmlspecialchars($bucket));
        $xml->addChild('Key', htmlspecialchars($key));
        $xml->addChild('ETag', htmlspecialchars($etag));
        return $xml->asXML() ?: '';
    }

    /**
     * Build S3 Error XML.
     */
    public static function error(string $code, string $message, string $resource = '', int $statusCode = 400): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/xml; charset=utf-8');

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error/>');
        $xml->addChild('Code', htmlspecialchars($code));
        $xml->addChild('Message', htmlspecialchars($message));
        $xml->addChild('Resource', htmlspecialchars($resource));
        $xml->addChild('RequestId', bin2hex(random_bytes(8)));
        $xml->addChild('HostId', bin2hex(random_bytes(16)));

        echo $xml->asXML();
        exit;
    }
}
