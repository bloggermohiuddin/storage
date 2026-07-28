#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use StoragePlatform\Server\Database;
use StoragePlatform\SDK\Storage;
use StoragePlatform\StorageEngine\HashedLocalEngine;
use StoragePlatform\Providers\ProviderFactory;
use StoragePlatform\Server\LifecycleEngine;
use StoragePlatform\API\S3\S3XmlResponse;

echo "==============================================================\n";
echo " Self-Hosted Object Storage Platform Diagnostic & Test Suite \n";
echo "==============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$title}\n";
        $failCount++;
    }
}

// 1. Test Database connection & seeding
try {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT * FROM access_keys WHERE access_key = 'local_access_key' LIMIT 1");
    $keyRecord = $stmt->fetch();
    assertTest(!empty($keyRecord), "Database schema & R2 credentials initialized ('local_access_key')");
} catch (\Throwable $e) {
    assertTest(false, "Database initialization failed: " . $e->getMessage());
}

// 2. Test HashedLocalEngine
try {
    $storageDir = sys_get_temp_dir() . '/test_platform_engine_' . bin2hex(random_bytes(4));
    $engine = new HashedLocalEngine($storageDir);
    
    $tmpFile = sys_get_temp_dir() . '/test_src.txt';
    file_put_contents($tmpFile, 'Hello Self-Hosted Object Storage!');
    
    $writeRes = $engine->write('uploads', 'docs/test.txt', $tmpFile, ['mime' => 'text/plain']);
    assertTest($engine->exists('uploads', 'docs/test.txt'), "HashedLocalEngine wrote object to hashed path layout");
    assertTest($writeRes['sha256'] === hash_file('sha256', $tmpFile), "HashedLocalEngine calculated valid SHA256 checksum");
    
    $readData = $engine->read('uploads', 'docs/test.txt');
    assertTest($readData === 'Hello Self-Hosted Object Storage!', "HashedLocalEngine read exact content");

    $engine->delete('uploads', 'docs/test.txt');
    assertTest(!$engine->exists('uploads', 'docs/test.txt'), "HashedLocalEngine deleted object & metadata");
    
    @unlink($tmpFile);
} catch (\Throwable $e) {
    assertTest(false, "HashedLocalEngine test failed: " . $e->getMessage());
}

// 3. Test Storage Facade SDK
try {
    $sdk = Storage::driver('local')->bucket('uploads');
    $tmpSdk = sys_get_temp_dir() . '/sdk_test.png';
    file_put_contents($tmpSdk, 'PNG_IMAGE_DATA_SIMULATION');

    $sdk->put('avatar.png', $tmpSdk);
    assertTest($sdk->exists('avatar.png'), "Storage SDK put() succeeded");
    
    $url = $sdk->url('avatar.png');
    assertTest(str_contains($url, '/object/uploads/avatar.png'), "Storage SDK url() generated correct direct link");

    $tempUrl = $sdk->temporaryUrl('avatar.png', 3600);
    assertTest(str_contains($tempUrl, 'signature='), "Storage SDK temporaryUrl() generated signed link");

    $list = $sdk->list();
    assertTest(in_array('avatar.png', $list, true), "Storage SDK list() enumerated uploaded object");

    $sdk->delete('avatar.png');
    assertTest(!$sdk->exists('avatar.png'), "Storage SDK delete() deleted object");

    @unlink($tmpSdk);
} catch (\Throwable $e) {
    assertTest(false, "Storage SDK test failed: " . $e->getMessage());
}

// 4. Test Memory & Provider Factory
try {
    $memProv = ProviderFactory::make(['driver' => 'memory', 'url' => 'http://localhost:8080']);
    $tmpMem = sys_get_temp_dir() . '/mem.txt';
    file_put_contents($tmpMem, 'MEMORY_DATA');
    
    $memProv->put('uploads', 'mem.txt', $tmpMem);
    assertTest($memProv->get('uploads', 'mem.txt') === 'MEMORY_DATA', "MemoryDriver provider put and get succeeded");
    @unlink($tmpMem);
} catch (\Throwable $e) {
    assertTest(false, "MemoryDriver test failed: " . $e->getMessage());
}

// 5. Test Lifecycle Engine
try {
    $lifecycle = new LifecycleEngine(Database::getConnection());
    $stats = $lifecycle->processLifecycleRules();
    assertTest(is_array($stats), "LifecycleEngine evaluated bucket rules cleanly");
} catch (\Throwable $e) {
    assertTest(false, "LifecycleEngine test failed: " . $e->getMessage());
}

// 6. Test S3 XML Response formatter
try {
    $xml = S3XmlResponse::listObjectsV2('uploads', [['key' => 'test.txt', 'size' => 100]]);
    assertTest(str_contains($xml, '<ListBucketResult') && str_contains($xml, 'test.txt'), "S3XmlResponse produced compliant ListObjectsV2 XML");
} catch (\Throwable $e) {
    assertTest(false, "S3XmlResponse test failed: " . $e->getMessage());
}

echo "\n==============================================================\n";
echo " Results: {$passCount} Passed, {$failCount} Failed\n";
echo "==============================================================\n";

exit($failCount === 0 ? 0 : 1);
