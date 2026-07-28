<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Server\ObjectManager;
use StoragePlatform\Server\AuthService;
use StoragePlatform\Providers\ProviderFactory;

class ObjectController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $bucketId = (int)($_GET['bucket_id'] ?? 0);
        $prefix = $_GET['prefix'] ?? '';
        $search = $_GET['search'] ?? '';

        if ($bucketId === 0) {
            $this->json(['error' => 'bucket_id is required.'], 400);
            return;
        }

        $sql = "
            SELECT id, bucket_id, key, size, mime_type, hash_sha256, hash_md5, created_at, updated_at
            FROM objects
            WHERE bucket_id = :bucket_id AND is_deleted = 0
        ";
        $params = ['bucket_id' => $bucketId];

        if ($prefix !== '') {
            $sql .= " AND key LIKE :prefix";
            $params['prefix'] = $prefix . '%';
        }

        if ($search !== '') {
            $sql .= " AND key LIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY key ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $objects = $stmt->fetchAll();

        // Include accessible URLs
        $manager = new ObjectManager($this->db);
        foreach ($objects as &$obj) {
            $obj['url'] = $manager->getUrl($bucketId, $obj['key']);
        }

        $this->json(['objects' => $objects]);
    }

    public function upload(): void
    {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        $key = $_POST['key'] ?? '';
        $prefix = $_POST['prefix'] ?? '';

        if ($bucketId === 0) {
            $this->json(['error' => 'bucket_id is required.'], 400);
            return;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No valid file uploaded.'], 400);
            return;
        }

        $file = $_FILES['file'];
        $originalName = $file['name'];
        $tempPath = $file['tmp_name'];

        if ($key === '') {
            // Generate unique key using prefix and filename
            $cleanPrefix = rtrim($prefix, '/');
            $dateFolder = date('Y/m');
            $uuid = bin2hex(random_bytes(8));
            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $filename = $uuid . ($ext ? '.' . $ext : '');

            $key = ($cleanPrefix !== '' ? $cleanPrefix . '/' : '') . $dateFolder . '/' . $filename;
        }

        try {
            $manager = new ObjectManager($this->db);
            $result = $manager->storeObject($bucketId, $key, $tempPath, [
                'mime' => $file['type'] ?: null,
            ]);

            $result['url'] = $manager->getUrl($bucketId, $key);
            $this->json(['success' => true, 'object' => $result]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function delete(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $bucketId = (int)($input['bucket_id'] ?? 0);
        $key = $input['key'] ?? '';

        if ($bucketId === 0 || $key === '') {
            $this->json(['error' => 'bucket_id and key are required.'], 400);
            return;
        }

        try {
            $manager = new ObjectManager($this->db);
            $manager->deleteObject($bucketId, $key);
            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function stream(): void
    {
        $bucketId   = (int)($_GET['bucket_id'] ?? 0);
        $bucketName = $_GET['bucket'] ?? '';
        $key        = $_GET['key'] ?? '';
        $expires    = (int)($_GET['expires'] ?? 0);
        $signature  = $_GET['signature'] ?? '';

        if (($bucketId === 0 && $bucketName === '') || $key === '') {
            http_response_code(400);
            echo "Missing bucket parameter or key.";
            exit;
        }

        $stmt = $this->db->prepare("
            SELECT b.*, p.driver as provider_driver, p.options as provider_options
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            WHERE (:bucket_id > 0 AND b.id = :bucket_id) OR (:bucket_name != '' AND b.name = :bucket_name)
            LIMIT 1
        ");
        $stmt->execute([
            'bucket_id'   => $bucketId,
            'bucket_name' => $bucketName
        ]);
        $bucket = $stmt->fetch();
        if (!$bucket) {
            http_response_code(404);
            echo "Bucket not found.";
            exit;
        }

        $isPublic = ($bucket['visibility'] ?? 'private') === 'public';

        // If bucket is private, enforce signature verification or active session auth
        if (!$isPublic) {
            $accessGranted = false;

            if ($signature !== '' && $expires > 0) {
                if (time() > $expires) {
                    http_response_code(403);
                    echo "Signed URL has expired.";
                    exit;
                }
                $targetBucket = $bucketName !== '' ? $bucketName : (string)$bucketId;
                $secret = $_ENV['SIGNED_URL_SECRET'] ?? 'mycloud-secret-key-fallback';
                $expected = hash_hmac('sha256', $targetBucket . '|' . $key . '|' . $expires, $secret);
                if (hash_equals($expected, $signature)) {
                    $accessGranted = true;
                }
            }

            if (!$accessGranted) {
                $auth = new AuthService($this->db);
                if ($auth->check()) {
                    $accessGranted = true;
                }
            }

            if (!$accessGranted) {
                http_response_code(403);
                echo "Access Denied: Private bucket requires signed URL or authorization.";
                exit;
            }
        }

        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        $provider = ProviderFactory::make($providerConfig, $this->db);

        try {
            $meta = $provider->metadata($bucket['name'], $key);
            header('Content-Type: ' . ($meta['mime_type'] ?? 'application/octet-stream'));
            header('Content-Length: ' . ($meta['size'] ?? 0));
            header('Content-Disposition: inline; filename="' . basename($key) . '"');

            $stream = $provider->streamRead($bucket['name'], $key);
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            } else {
                echo $provider->get($bucket['name'], $key);
            }
            exit;
        } catch (\Throwable $e) {
            http_response_code(404);
            echo "File not found.";
            exit;
        }
    }

    public function copy(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $bucketId = (int)($input['bucket_id'] ?? 0);
        $fromKey = $input['from_key'] ?? '';
        $toKey = $input['to_key'] ?? '';

        if ($bucketId === 0 || $fromKey === '' || $toKey === '') {
            $this->json(['error' => 'bucket_id, from_key, and to_key are required.'], 400);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT name, provider_id FROM buckets WHERE id = :id");
            $stmt->execute(['id' => $bucketId]);
            $bucket = $stmt->fetch();

            $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
            $provStmt->execute(['id' => $bucket['provider_id']]);
            $providerConfig = $provStmt->fetch();

            $provider = ProviderFactory::make($providerConfig, $this->db);
            if ($provider->copy($bucket['name'], $fromKey, $toKey)) {
                // Read source metadata to mirror in DB
                $meta = $provider->metadata($bucket['name'], $toKey);
                $ins = $this->db->prepare("
                    INSERT INTO objects (bucket_id, key, size, mime_type, hash_sha256, hash_md5, is_deleted)
                    VALUES (:bucket_id, :key, :size, :mime_type, :hash_sha256, :hash_md5, 0)
                ");
                $ins->execute([
                    'bucket_id' => $bucketId,
                    'key' => $toKey,
                    'size' => $meta['size'] ?? 0,
                    'mime_type' => $meta['mime_type'] ?? 'application/octet-stream',
                    'hash_sha256' => $meta['sha256'] ?? '',
                    'hash_md5' => $meta['etag'] ?? '',
                ]);
                $this->json(['success' => true]);
            } else {
                $this->json(['error' => 'Copy failed.'], 400);
            }
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
