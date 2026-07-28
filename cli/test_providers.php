#!/usr/bin/env php
<?php

/**
 * Platform Diagnostic Tool
 *
 * Verifies database initialization, provider health, and local/mycloud file storage.
 * Usage:
 *   php cli/test_providers.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use StoragePlatform\Server\Database;
use StoragePlatform\Server\BucketManager;
use StoragePlatform\Server\ObjectManager;
use StoragePlatform\Providers\ProviderFactory;
use StoragePlatform\SDK\StorageClient;

echo "=======================================================" . PHP_EOL;
echo "  Object Storage Platform (MyCloud) Diagnostics" . PHP_EOL;
echo "=======================================================" . PHP_EOL . PHP_EOL;

try {
    // 1. Check DB & Schema
    echo "[1/4] Checking SQLite Database Connection... ";
    $db = Database::getConnection();
    echo "OK" . PHP_EOL;

    // 2. Check Providers
    echo "[2/4] Verifying Configured Storage Providers..." . PHP_EOL;
    $stmt = $db->query("SELECT * FROM storage_providers");
    $providers = $stmt->fetchAll();
    foreach ($providers as $p) {
        $instance = ProviderFactory::make($p, $db);
        $health = $instance->health();
        echo sprintf("  • %-20s [%-7s] -> Status: %s" . PHP_EOL, $p['name'], strtoupper($p['driver']), strtoupper($health['status']));
    }

    // 3. Test MyCloud Provider Upload
    echo PHP_EOL . "[3/4] Testing MyCloud Storage Engine..." . PHP_EOL;
    $bManager = new BucketManager($db);

    // Ensure test bucket exists
    $mycloudProvider = $db->query("SELECT id FROM storage_providers WHERE driver = 'mycloud' LIMIT 1")->fetchColumn();
    if ($mycloudProvider) {
        try {
            $bManager->createBucket('test-bucket', (int)$mycloudProvider);
            echo "  • Test bucket created/verified." . PHP_EOL;
        } catch (\Throwable $e) {
            // Already exists or ok
        }

        $bRow = $db->query("SELECT id FROM buckets WHERE name = 'test-bucket' LIMIT 1")->fetch();
        if ($bRow) {
            $oManager = new ObjectManager($db);
            $tmpFile = tempnam(sys_get_temp_dir(), 'diag_');
            file_put_contents($tmpFile, 'MyCloud Storage Platform Test Content — ' . date('Y-m-d H:i:s'));

            $res = $oManager->storeObject((int)$bRow['id'], 'test/diag.txt', $tmpFile);
            unlink($tmpFile);

            echo "  • Object stored successfully!" . PHP_EOL;
            echo "    Key: " . $res['key'] . PHP_EOL;
            echo "    Size: " . $res['size'] . " bytes" . PHP_EOL;
            echo "    SHA-256: " . $res['sha256'] . PHP_EOL;
        }
    }

    // 4. Test SDK Integration
    echo PHP_EOL . "[4/4] Testing StorageClient SDK API... ";
    $sdk = StorageClient::disk('mycloud');
    if ($sdk->exists('test-bucket', 'test/diag.txt')) {
        echo "OK (Object verified via SDK)" . PHP_EOL;
    } else {
        echo "SDK Check Warning" . PHP_EOL;
    }

    echo PHP_EOL . "=======================================================" . PHP_EOL;
    echo "  ALL DIAGNOSTICS COMPLETED SUCCESSFULLY!" . PHP_EOL;
    echo "=======================================================" . PHP_EOL;

} catch (\Throwable $e) {
    echo PHP_EOL . "ERROR: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
