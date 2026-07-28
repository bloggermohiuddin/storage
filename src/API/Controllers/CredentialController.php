<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Server\Database;
use StoragePlatform\API\Controllers\ServerInfoController;

/**
 * CredentialController — R2-style Credential Manager API.
 *
 * The endpoint URL is always auto-detected from the current HTTP request
 * context so it works correctly on localhost, shared hosting subdomains,
 * HTTPS deployments, and any custom port — zero configuration needed.
 */
class CredentialController
{
    protected \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * GET /api/credentials
     * Returns current R2-style credentials, all key list, and ready-to-paste code snippets.
     */
    public function index(): void
    {
        $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 1);

        $stmt = $this->db->prepare(
            "SELECT * FROM access_keys WHERE user_id = :uid ORDER BY id ASC LIMIT 20"
        );
        $stmt->execute(['uid' => $userId]);
        $keys = $stmt->fetchAll();

        $activeKey = $keys[0] ?? [
            'account_id'     => 'local',
            'access_key'     => 'local_access_key',
            'secret_key'     => 'local_secret_key_1234567890',
            'default_bucket' => 'uploads',
            'permissions'    => 'admin',
        ];

        $baseUrl       = ServerInfoController::detectBaseUrl();
        $defaultBucket = $activeKey['default_bucket'] ?? 'uploads';
        $accountId     = $activeKey['account_id'] ?? 'local';
        $accessKey     = $activeKey['access_key']  ?? 'local_access_key';
        $secretKey     = $activeKey['secret_key']  ?? 'local_secret_key_1234567890';
        $publicUrl     = $baseUrl;

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'      => 'success',
            'server_url'  => $baseUrl,   // expose detected URL for debug/display
            'credentials' => [
                'ACCOUNT_ID'     => $accountId,
                'ACCESS_KEY'     => $accessKey,
                'SECRET_KEY'     => $secretKey,
                'DEFAULT_BUCKET' => $defaultBucket,
                'ENDPOINT'       => $baseUrl,
                'PUBLIC_URL'     => $publicUrl,
            ],
            'keys' => $keys,
            // Legacy code_snippets kept for backward compatibility
            'code_snippets' => [
                'dotenv' => implode("\n", [
                    'STORAGE_ACCOUNT_ID=' . $accountId,
                    'STORAGE_ACCESS_KEY='  . $accessKey,
                    'STORAGE_SECRET_KEY='  . $secretKey,
                    'STORAGE_BUCKET='      . $defaultBucket,
                    'STORAGE_ENDPOINT='    . $baseUrl,
                    'STORAGE_PUBLIC_URL='  . $publicUrl,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/credentials/generate
     * Generate a new R2 / S3 credential pair.
     */
    public function generate(): void
    {
        $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 1);
        $data   = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $name   = trim($data['name']           ?? 'R2 Key ' . date('Y-m-d H:i'));
        $bucket = trim($data['default_bucket'] ?? 'uploads');

        $accessKey = 'ak_' . bin2hex(random_bytes(10));
        $secretKey = 'sk_' . bin2hex(random_bytes(20));

        $stmt = $this->db->prepare("
            INSERT INTO access_keys (user_id, account_id, name, access_key, secret_key, default_bucket, permissions)
            VALUES (:uid, 'local', :name, :ak, :sk, :bucket, 'admin')
        ");
        $stmt->execute([
            'uid'    => $userId,
            'name'   => $name,
            'ak'     => $accessKey,
            'sk'     => $secretKey,
            'bucket' => $bucket,
        ]);

        $baseUrl = ServerInfoController::detectBaseUrl();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'success',
            'message' => 'New R2 credential pair generated.',
            'key'     => [
                'name'           => $name,
                'access_key'     => $accessKey,
                'secret_key'     => $secretKey,
                'default_bucket' => $bucket,
                'endpoint'       => $baseUrl,
            ],
        ]);
    }
}
