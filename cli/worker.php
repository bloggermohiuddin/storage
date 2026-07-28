#!/usr/bin/env php
<?php

/**
 * CLI Background Queue Worker
 *
 * Runs background jobs like migrations asynchronously.
 * Usage:
 *   php cli/worker.php [--queue=migrations]
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use StoragePlatform\Server\Database;
use StoragePlatform\Server\LifecycleEngine;
use StoragePlatform\Queue\SQLiteQueue;
use StoragePlatform\Queue\Worker;

$opts = getopt('', ['queue:']);
$queueName = $opts['queue'] ?? 'migrations';

$db = Database::getConnection();

// Run bucket lifecycle rules
$lifecycle = new LifecycleEngine($db);
$res = $lifecycle->processLifecycleRules();
if ($res['expired_objects'] > 0 || $res['purged_versions'] > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Lifecycle: expired {$res['expired_objects']} objects, purged {$res['purged_versions']} versions.\n";
}

$queue = new SQLiteQueue($db);
$worker = new Worker($queue, $db);
$worker->run($queueName, 3, 2);

