<?php

declare(strict_types=1);

namespace StoragePlatform\Server;

/**
 * AuthService — manages session authentication, Bearer tokens, and programmatic API Keys.
 */
class AuthService
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Authenticate a user by username and password.
     */
    public function login(string $username, string $password): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Return user details without password hash
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }

    /**
     * Validate session cookie token.
     */
    public function validateSession(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user'])) {
            return $_SESSION['user'];
        }

        return null;
    }

    /**
     * Validate programmatic API key (Access Key + Secret Key).
     */
    public function validateApiKey(string $accessKey, string $secretKey): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.* FROM users u
            JOIN api_keys k ON k.user_id = u.id
            WHERE k.access_key = :access_key AND k.secret_key = :secret_key
            LIMIT 1
        ");
        $stmt->execute([
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
        ]);
        
        $user = $stmt->fetch();
        if ($user) {
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }

    /**
     * Validate authorization header (Bearer token or basic API credentials).
     */
    public function authenticateRequest(): ?array
    {
        // 1. Check Session First
        $user = $this->validateSession();
        if ($user) {
            return $user;
        }

        // 2. Check Authorization Header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader !== '') {
            // Bearer Token check (useful for dashboard integrations / JWT)
            if (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
                // In this modular setup, we can query session token or look for API access key in Bearer format
                $stmt = $this->db->prepare("
                    SELECT u.* FROM users u
                    JOIN api_keys k ON k.user_id = u.id
                    WHERE k.access_key = :token
                    LIMIT 1
                ");
                $stmt->execute(['token' => $token]);
                $user = $stmt->fetch();
                if ($user) {
                    unset($user['password_hash']);
                    return $user;
                }
            }
            
            // Basic authentication check
            if (str_starts_with($authHeader, 'Basic ')) {
                $credentials = base64_decode(substr($authHeader, 6));
                $parts = explode(':', $credentials, 2);
                if (count($parts) === 2) {
                    [$accessKey, $secretKey] = $parts;
                    return $this->validateApiKey($accessKey, $secretKey);
                }
            }
        }

        // 3. Fallback: Query parameters for streaming links/downloads
        $accessKey = $_GET['access_key'] ?? '';
        $secretKey = $_GET['secret_key'] ?? '';
        if ($accessKey !== '' && $secretKey !== '') {
            return $this->validateApiKey($accessKey, $secretKey);
        }

        return null;
    }

    /**
     * Simple boolean check: is the current request authenticated?
     * Checks session first, then Authorization header, then query params.
     */
    public function check(): bool
    {
        return $this->authenticateRequest() !== null;
    }

    public function createApiKey(int $userId, string $name): array
    {
        $accessKey = 'SP_' . bin2hex(random_bytes(12)); // e.g. SP_a1b2c3d4...
        $secretKey = bin2hex(random_bytes(24));

        $stmt = $this->db->prepare("
            INSERT INTO api_keys (user_id, name, access_key, secret_key)
            VALUES (:user_id, :name, :access_key, :secret_key)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
        ]);

        return [
            'name' => $name,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
        ];
    }
}
