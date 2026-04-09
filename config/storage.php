<?php

/**
 * ============================================================================
 * Storage SDK — Disk Configuration
 * ============================================================================
 *
 * This file controls which storage backend is active and how each disk is
 * configured. Switch the 'default' key to change backends with zero code
 * changes in your application.
 *
 * Environment variables override the values here — set them in .env or
 * in your server/hosting control panel.
 * ============================================================================
 */

// ---------------------------------------------------------------------------
// Simple .env loader (no dependency required)
// ---------------------------------------------------------------------------
(static function (): void {
    $envFile = dirname(__DIR__) . '/.env';
    if (! is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\"'");
            if (! array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }
})();

// ---------------------------------------------------------------------------
// Helper: read env var with fallback
// ---------------------------------------------------------------------------
$env = static fn(string $key, mixed $default = null): mixed =>
    $_ENV[$key] ?? getenv($key) ?: $default;

// ============================================================================

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    |
    | Change this to 'r2', 's3', or 'b2' to switch the entire application
    | to that backend instantly — no code changes required.
    |
    */
    'default' => $env('STORAGE_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging'  => (bool) $env('STORAGE_LOGGING', true),
    'log_path' => dirname(__DIR__) . '/logs/storage.log',

    /*
    |--------------------------------------------------------------------------
    | Disk Definitions
    |--------------------------------------------------------------------------
    */
    'disks' => [

        // ─────────────────────────────────────────────────────────────────
        // LOCAL DISK — shared hosting, no external services
        // ─────────────────────────────────────────────────────────────────
        'local' => [
            'driver' => 'local',

            // Absolute path to the storage root on this server
            'root'   => dirname(__DIR__) . '/storage/uploads',

            // Public base URL pointing to the root (trailing slash excluded)
            // e.g. https://example.com/storage/uploads
            'url'    => $env('LOCAL_STORAGE_URL', 'https://files.example.com'),

            // HMAC secret used to sign temporary URLs
            // Set a strong random value in .env: openssl rand -hex 32
            'signed_url_secret' => $env('SIGNED_URL_SECRET', 'change-this-to-a-random-32-char-secret'),
        ],

        // ─────────────────────────────────────────────────────────────────
        // CLOUDFLARE R2
        // ─────────────────────────────────────────────────────────────────
        'r2' => [
            'driver'   => 's3',
            'endpoint' => $env('R2_ENDPOINT', 'https://<accountid>.r2.cloudflarestorage.com'),
            'region'   => 'auto',                   // R2 always uses 'auto'
            'bucket'   => $env('R2_BUCKET', 'my-bucket'),
            'key'      => $env('R2_ACCESS_KEY_ID', ''),
            'secret'   => $env('R2_SECRET_ACCESS_KEY', ''),

            // Custom domain pointing to your R2 bucket (recommended for public files)
            'url'      => $env('R2_PUBLIC_URL', 'https://files.example.com'),

            // R2 does NOT support ACLs; this option skips the ACL header
            'no_acl'   => true,
        ],

        // ─────────────────────────────────────────────────────────────────
        // AMAZON S3
        // ─────────────────────────────────────────────────────────────────
        's3' => [
            'driver'  => 's3',
            'region'  => $env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket'  => $env('AWS_BUCKET', 'my-bucket'),
            'key'     => $env('AWS_ACCESS_KEY_ID', ''),
            'secret'  => $env('AWS_SECRET_ACCESS_KEY', ''),

            // Optional: use CloudFront or custom domain instead of S3 native URL
            'url'     => $env('S3_PUBLIC_URL', ''),
        ],

        // ─────────────────────────────────────────────────────────────────
        // BACKBLAZE B2
        // ─────────────────────────────────────────────────────────────────
        'b2' => [
            'driver'   => 's3',
            'endpoint' => $env('B2_ENDPOINT', 'https://s3.us-west-004.backblazeb2.com'),
            'region'   => $env('B2_REGION', 'us-west-004'),
            'bucket'   => $env('B2_BUCKET', 'my-bucket'),
            'key'      => $env('B2_APPLICATION_KEY_ID', ''),
            'secret'   => $env('B2_APPLICATION_KEY', ''),
            'url'      => $env('B2_PUBLIC_URL', ''),
        ],
    ],

];
