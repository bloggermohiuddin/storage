<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Server\AuthService;
use StoragePlatform\API\Controllers\ServerInfoController;

class AuthController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function login(): void
    {
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if ($username === '' || $password === '') {
            $this->json(['error' => 'Username and password are required.'], 400);
            return;
        }

        $authService = new AuthService($this->db);
        $user        = $authService->login($username, $password);

        if ($user) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['user']    = $user;
            $_SESSION['user_id'] = (int)$user['id']; // kept for compatibility
            $this->json(['success' => true, 'user' => $user]);
        } else {
            $this->json(['error' => 'Invalid username or password.'], 401);
        }
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        $this->json(['success' => true]);
    }

    public function me(): void
    {
        $user = $_REQUEST['user'] ?? null;
        if ($user) {
            $this->json(['user' => $user]);
        } else {
            $this->json(['error' => 'Not authenticated.'], 401);
        }
    }

    /**
     * GET /api/auth/keys
     * Returns all programmatic API keys for the authenticated user.
     * Reads from BOTH api_keys (session-generated) and access_keys (R2-compatible).
     */
    public function getKeys(): void
    {
        $user = $_REQUEST['user'] ?? null;
        if (!$user) {
            $this->json(['error' => 'Unauthorized.'], 401);
            return;
        }
        $userId = (int)($user['id'] ?? 0);

        // api_keys table — session-generated programmatic keys
        $stmt = $this->db->prepare("
            SELECT
                id,
                'api' AS key_type,
                name,
                access_key,
                '' AS default_bucket,
                'read-write' AS permissions,
                created_at
            FROM api_keys
            WHERE user_id = :uid
            ORDER BY id DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $apiKeys = $stmt->fetchAll();

        // access_keys table — R2-compatible credential pairs
        $stmt2 = $this->db->prepare("
            SELECT
                id,
                'r2' AS key_type,
                name,
                access_key,
                COALESCE(default_bucket, '') AS default_bucket,
                COALESCE(permissions, 'admin') AS permissions,
                created_at
            FROM access_keys
            WHERE user_id = :uid
            ORDER BY id DESC
        ");
        $stmt2->execute(['uid' => $userId]);
        $r2Keys = $stmt2->fetchAll();

        // Merge both — R2 keys first, then API keys
        $this->json(['keys' => array_merge($r2Keys, $apiKeys)]);
    }

    /**
     * POST /api/auth/keys
     * Generate a new programmatic API key pair (api_keys table).
     */
    public function createKey(): void
    {
        $user = $_REQUEST['user'] ?? null;
        if (!$user) {
            $this->json(['error' => 'Unauthorized.'], 401);
            return;
        }
        $userId = (int)($user['id'] ?? 0);

        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $name   = trim($input['name'] ?? 'SDK Key ' . date('Y-m-d'));

        $authService = new AuthService($this->db);
        $keyPair     = $authService->createApiKey($userId, $name);

        $this->json([
            'success' => true,
            'key'     => [
                'name'           => $keyPair['name'],
                'access_key'     => $keyPair['access_key'],
                'secret_key'     => $keyPair['secret_key'],
                'default_bucket' => '',
                'permissions'    => 'read-write',
            ],
        ]);
    }

    /**
     * DELETE /api/auth/keys/{id}
     * Revoke an API key. Tries both api_keys and access_keys tables.
     */
    public function deleteKey(array $params): void
    {
        $user = $_REQUEST['user'] ?? null;
        if (!$user) {
            $this->json(['error' => 'Unauthorized.'], 401);
            return;
        }
        $userId = (int)($user['id'] ?? 0);
        $id     = (int)($params['id'] ?? 0);

        if ($id === 0) {
            $this->json(['error' => 'Invalid key ID.'], 400);
            return;
        }

        // Try api_keys first
        $stmt = $this->db->prepare(
            "DELETE FROM api_keys WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);

        // Also try access_keys (never delete the last key)
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM access_keys WHERE user_id = :uid"
        );
        $countStmt->execute(['uid' => $userId]);
        $keyCount = (int)$countStmt->fetchColumn();

        if ($keyCount > 1) {
            $stmt2 = $this->db->prepare(
                "DELETE FROM access_keys WHERE id = :id AND user_id = :uid"
            );
            $stmt2->execute(['id' => $id, 'uid' => $userId]);
        }

        $this->json(['success' => true]);
    }

    // ── helpers ──────────────────────────────────────────────
    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
