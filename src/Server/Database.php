<?php

declare(strict_types=1);

namespace StoragePlatform\Server;

/**
 * Database — manages PDO connection to SQLite database and handles automatic schema setup.
 */
class Database
{
    private static ?\PDO $pdo = null;

    /**
     * Get a PDO connection to the SQLite database.
     * Initializes tables and seeds initial admin user if database is empty.
     *
     * @param  string|null $dbPath
     * @return \PDO
     */
    public static function getConnection(?string $dbPath = null): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if ($dbPath === null) {
            $dbPath = dirname(__DIR__, 2) . '/database/database.sqlite';
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create database directory: {$dir}");
        }

        $dsn = 'sqlite:' . $dbPath;
        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Enable foreign key support in SQLite
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // Check if schema is loaded by checking for 'users' table
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $tableExists = $stmt->fetch();

        // Execute schema file if missing tables
        $schemaFile = dirname(__DIR__, 2) . '/database/schema.sql';
        if (is_file($schemaFile)) {
            $schemaSql = file_get_contents($schemaFile);
            $pdo->exec($schemaSql);
        }

        // Auto-migrate missing columns for existing SQLite DBs
        self::migrateSchema($pdo);

        // Check if initial admin user exists
        $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
        if (!$stmt->fetch()) {
            // Seed default admin user (username: admin, password: adminpassword, account_id: local)
            $adminUser = 'admin';
            $adminPass = 'adminpassword';
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);

            $insertUser = $pdo->prepare("
                INSERT INTO users (username, password_hash, role, account_id)
                VALUES (:username, :password, 'admin', 'local')
            ");
            $insertUser->execute([
                'username' => $adminUser,
                'password' => $hash,
            ]);
            $userId = (int)$pdo->lastInsertId();

            // Seed default S3 / R2 credentials for local development
            $insertKey = $pdo->prepare("
                INSERT INTO access_keys (user_id, account_id, name, access_key, secret_key, default_bucket, permissions)
                VALUES (:user_id, 'local', 'Default Local Key', 'local_access_key', 'local_secret_key_1234567890', 'uploads', 'admin')
            ");
            $insertKey->execute(['user_id' => $userId]);

            // Create default Local storage provider in DB
            $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
            $providerUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';
            $insertProvider = $pdo->prepare("
                INSERT INTO storage_providers (name, driver, options, is_active)
                VALUES (:name, :driver, :options, 1)
            ");
            $insertProvider->execute([
                'name' => 'Local Storage Engine',
                'driver' => 'local',
                'options' => json_encode([
                    'root' => $projectRoot . '/storage',
                    'url' => $providerUrl
                ]),
            ]);
            $providerId = (int)$pdo->lastInsertId();

            // Create default bucket 'uploads'
            $insertBucket = $pdo->prepare("
                INSERT INTO buckets (provider_id, name, visibility, versioning)
                VALUES (:provider_id, 'uploads', 'public', 0)
            ");
            $insertBucket->execute(['provider_id' => $providerId]);
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * Safely add any missing columns to existing SQLite tables if schema evolved.
     */
    private static function migrateSchema(\PDO $pdo): void
    {
        $columnsToCheck = [
            'users' => ['account_id' => "TEXT UNIQUE NOT NULL DEFAULT 'local'"],
            'buckets' => ['quota_bytes' => "INTEGER DEFAULT 0", 'quota_objects' => "INTEGER DEFAULT 0"],
            'objects' => ['etag' => "TEXT", 'storage_path' => "TEXT", 'is_latest' => "INTEGER DEFAULT 1"]
        ];

        foreach ($columnsToCheck as $table => $columns) {
            $tableCols = [];
            $pragma = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
            foreach ($pragma as $col) {
                $tableCols[] = $col['name'];
            }
            foreach ($columns as $colName => $colDef) {
                if (!in_array($colName, $tableCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$colName} {$colDef}");
                    } catch (\Exception $e) {
                        // Ignore duplicate column alter errors
                    }
                }
            }
        }

        // Update existing local provider URL to use APP_URL if it's still the old default
        $appUrl = $_ENV['APP_URL'] ?? null;
        if ($appUrl) {
            $providers = $pdo->query("SELECT id, options FROM storage_providers WHERE driver = 'local'")->fetchAll();
            foreach ($providers as $provider) {
                $opts = json_decode($provider['options'], true) ?? [];
                $currentUrl = $opts['url'] ?? '';
                if ($currentUrl === '' || $currentUrl === 'http://localhost:8080' || $currentUrl === 'http://localhost:8300') {
                    $opts['url'] = rtrim($appUrl, '/');
                    $update = $pdo->prepare("UPDATE storage_providers SET options = :options WHERE id = :id");
                    $update->execute(['options' => json_encode($opts), 'id' => $provider['id']]);
                }
            }
        }
    }
}
