<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

/**
 * ServerInfoController — Returns dynamic server metadata.
 *
 * Provides the auto-detected base URL and platform version info.
 * Used by the login page and admin JS to show the live endpoint URL.
 */
class ServerInfoController
{
    public function __construct(?\PDO $db = null) {}

    /**
     * Detect the base URL of the current PHP request dynamically.
     * Works for: localhost, HTTPS subdomains, shared hosting sub-folders,
     * reverse proxies (X-Forwarded-*), and any custom port.
     */
    public static function detectBaseUrl(): string
    {
        // 1. Honour an explicit APP_URL env override
        $envUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/');
        }

        // 2. Scheme detection (check reverse-proxy headers first)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])   && $_SERVER['HTTP_X_FORWARDED_SSL']   === 'on');

        $scheme = $isHttps ? 'https' : 'http';

        // 3. Host (HTTP_HOST already includes port when non-standard)
        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
            ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // Proxy headers can be comma-separated — take the first
        $host = trim(strtok($host, ','));

        // 4. Sub-directory prefix (for installations inside /storage/public/ etc.)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(dirname($scriptName), '/\\');
        $prefix = ($dir === '' || $dir === '/' || $dir === '.') ? '' : $dir;

        return $scheme . '://' . $host . $prefix;
    }

    /**
     * GET /api/server-info (public, no auth required)
     * Returns the detected server base URL and platform metadata.
     */
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'platform'    => 'MyCloud Storage',
            'version'     => '2.0.0',
            'base_url'    => self::detectBaseUrl(),
            's3_endpoint' => self::detectBaseUrl(),
            'compatible'  => ['S3 API v4', 'Cloudflare R2', 'AWS SDK', 'Boto3', 'MinIO'],
            'php_version' => PHP_VERSION,
            'timestamp'   => date('c'),
        ]);
    }
}
