<?php

/**
 * ============================================================================
 * Example: Retrieve file info and URLs from stored keys
 * ============================================================================
 *
 * In your database you store only the KEY:
 *   patients/2026/04/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg
 *
 * Here we show how to reconstruct URLs and metadata from that key.
 * ============================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StorageSDK\Managers\StorageManager;
use StorageSDK\Exceptions\FileNotFoundException;
use StorageSDK\Exceptions\StorageException;

// ─── Simulate a key fetched from the database ─────────────────────────────────

// In a real app: $key = $patient['profile_photo_key'];
$key = $_GET['key'] ?? 'patients/2026/04/example.jpg';

$storage = StorageManager::disk(); // reads STORAGE_DRIVER from .env

try {
    if (! $storage->exists($key)) {
        throw new FileNotFoundException($key);
    }

    $info = [
        'key'           => $key,
        'public_url'    => $storage->url($key),
        'signed_url'    => $storage->generateSignedUrl($key, 86400), // 24h
        'size_bytes'    => $storage->size($key),
        'size_human'    => formatBytes($storage->size($key)),
        'mime_type'     => $storage->mimeType($key),
        'last_modified' => date('Y-m-d H:i:s', $storage->lastModified($key)),
    ];

    header('Content-Type: application/json');
    echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (FileNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found', 'key' => $key]);
} catch (StorageException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i     = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
