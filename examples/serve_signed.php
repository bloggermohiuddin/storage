<?php

/**
 * ============================================================================
 * Serve Script — Verify & Serve Signed URLs for Private Local Files
 * ============================================================================
 *
 * For the LocalAdapter, "signed URLs" are HMAC-verified tokens.
 * This script sits at a URL like https://example.com/storage/serve.php
 * and proxies the file content only if the token is valid.
 *
 * NGINX / Apache rewrite (optional — makes URLs cleaner):
 *   RewriteRule ^storage/private/(.+)$ storage/serve.php?key=$1 [QSA,L]
 *
 * For S3Adapter, signed URLs are native presigned S3 URLs and need no
 * serve script — the SDK generates them directly.
 * ============================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StorageSDK\Adapters\LocalAdapter;
use StorageSDK\Managers\StorageManager;
use StorageSDK\Exceptions\FileNotFoundException;

// ─── Read request parameters ──────────────────────────────────────────────────

$key       = $_GET['key']       ?? '';
$expires   = (int) ($_GET['expires']   ?? 0);
$signature = $_GET['signature'] ?? '';

// ─── Basic input validation ───────────────────────────────────────────────────

if ($key === '' || $expires === 0 || $signature === '') {
    http_response_code(400);
    exit('Bad Request: missing signed URL parameters');
}

// ─── Verify the signature via LocalAdapter ────────────────────────────────────

/** @var LocalAdapter $adapter */
$adapter = StorageManager::disk('local')->getAdapter();

if (! $adapter->verifySignedUrl($key, $expires, $signature)) {
    http_response_code(403);
    exit('Forbidden: invalid or expired signature');
}

// ─── Stream the file to the browser ──────────────────────────────────────────

try {
    $mime    = $adapter->mimeType($key);
    $size    = $adapter->size($key);
    $content = $adapter->get($key);

    header('Content-Type: '   . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    // Suggest filename from key
    $filename = basename($key);
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');

    echo $content;
} catch (FileNotFoundException $e) {
    http_response_code(404);
    exit('Not Found');
}
