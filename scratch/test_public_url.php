<?php

require __DIR__ . '/../vendor/autoload.php';

use StoragePlatform\Server\Database;
use StoragePlatform\Server\BucketManager;
use StoragePlatform\Server\ObjectManager;

$db = Database::getConnection();
$bm = new BucketManager($db);
$om = new ObjectManager($db);

// Create a public bucket
$bm->createBucket('public-assets', 2, 'public');

// Fetch bucket ID for public-assets
$stmt = $db->prepare("SELECT id FROM buckets WHERE name = 'public-assets' LIMIT 1");
$stmt->execute();
$bucketId = (int)$stmt->fetchColumn();

$om->storeObject($bucketId, 'branding/logo.png', __DIR__ . '/../README.md');

$publicUrl = $om->getUrl($bucketId, 'branding/logo.png');
echo "Generated Public URL: " . $publicUrl . PHP_EOL;

$content = file_get_contents($publicUrl);
echo "Fetched bytes length: " . strlen($content) . PHP_EOL;
