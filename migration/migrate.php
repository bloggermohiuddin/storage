#!/usr/bin/env php
<?php

/**
 * ============================================================================
 * Storage Migration Script — Local → S3 / Cloudflare R2 / Backblaze B2
 * ============================================================================
 *
 * This script scans every file in your local storage root and uploads it
 * to the configured S3-compatible destination, preserving the exact same
 * object key (no renames, no path changes).
 *
 * USAGE:
 *   php migration/migrate.php [--dry-run] [--disk=r2] [--prefix=patients/]
 *
 * OPTIONS:
 *   --dry-run           List files that would be migrated without uploading
 *   --disk=NAME         Target disk name from config/storage.php (default: r2)
 *   --prefix=PATH       Only migrate files under this key prefix
 *   --skip-existing     Skip files that already exist in destination
 *   --batch=N           Upload N files per batch (default: 50)
 *   --retry=N           Max retries per file (default: 3)
 *
 * EXAMPLE:
 *   php migration/migrate.php --disk=r2 --skip-existing
 *   php migration/migrate.php --disk=s3 --prefix=patients/ --dry-run
 * ============================================================================
 */

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use StorageSDK\Managers\StorageManager;
use StorageSDK\Exceptions\StorageException;

// ─── Parse CLI arguments ─────────────────────────────────────────────────────

$opts = getopt('', [
    'dry-run',
    'disk:',
    'prefix:',
    'skip-existing',
    'batch:',
    'retry:',
]);

$dryRun       = isset($opts['dry-run']);
$targetDisk   = $opts['disk']   ?? 'r2';
$prefix       = $opts['prefix'] ?? '';
$skipExisting = isset($opts['skip-existing']);
$batchSize    = (int) ($opts['batch'] ?? 50);
$maxRetry     = (int) ($opts['retry'] ?? 3);

// ─── Resolve local and target storage instances ──────────────────────────────

$config     = StorageManager::getConfig();
$localRoot  = $config['disks']['local']['root']
    ?? ($root . '/storage/uploads');

$local  = StorageManager::disk('local');
$target = StorageManager::disk($targetDisk);

// ─── Collect all local files ─────────────────────────────────────────────────

println("═══════════════════════════════════════════════════════");
println("  Storage SDK Migration Tool");
println("  Source  : local ({$localRoot})");
println("  Target  : {$targetDisk}");
println("  Prefix  : " . ($prefix ?: '(all files)'));
println("  Dry run : " . ($dryRun ? 'YES — nothing will be uploaded' : 'NO'));
println("═══════════════════════════════════════════════════════");
println('');

$files = collectFiles($localRoot, $prefix);
$total = count($files);

if ($total === 0) {
    println("No files found to migrate.");
    exit(0);
}

println("Found {$total} file(s) to process.");
println('');

// ─── Migration loop ───────────────────────────────────────────────────────────

$stats = ['success' => 0, 'skipped' => 0, 'failed' => 0];
$failed = [];

foreach (array_chunk($files, $batchSize) as $batchNum => $batch) {
    $batchStart = ($batchNum * $batchSize) + 1;
    $batchEnd   = min($batchStart + count($batch) - 1, $total);
    println("── Batch " . ($batchNum + 1) . " ({$batchStart}–{$batchEnd} of {$total}) ──");

    foreach ($batch as $key => $absolutePath) {
        // Check if already exists at destination
        if ($skipExisting && $target->exists($key)) {
            println("  SKIP  {$key}");
            $stats['skipped']++;
            continue;
        }

        if ($dryRun) {
            println("  DRY   {$key}");
            $stats['success']++;
            continue;
        }

        // Upload with retry
        $uploaded = false;
        for ($attempt = 1; $attempt <= $maxRetry; $attempt++) {
            try {
                $target->put($key, $absolutePath, ['no_acl' => str_starts_with($targetDisk, 'r2')]);
                println("  OK    {$key}");
                $stats['success']++;
                $uploaded = true;
                break;
            } catch (StorageException $e) {
                if ($attempt === $maxRetry) {
                    println("  FAIL  {$key}  [{$e->getMessage()}]");
                    $stats['failed']++;
                    $failed[] = ['key' => $key, 'error' => $e->getMessage()];
                } else {
                    println("  RETRY {$key} (attempt {$attempt}/{$maxRetry})");
                    sleep(2 ** $attempt); // Exponential back-off: 2s, 4s, 8s
                }
            }
        }
    }

    println('');
}

// ─── Summary ──────────────────────────────────────────────────────────────────

println("═══════════════════════════════════════════════════════");
println("  Migration complete");
println("  Total   : {$total}");
println("  Success : {$stats['success']}");
println("  Skipped : {$stats['skipped']}");
println("  Failed  : {$stats['failed']}");
println("═══════════════════════════════════════════════════════");

if (! empty($failed)) {
    println('');
    println("Failed files (saved to migration/failed.json):");
    foreach ($failed as $f) {
        println("  • {$f['key']} — {$f['error']}");
    }
    file_put_contents(
        __DIR__ . '/failed.json',
        json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

exit($stats['failed'] > 0 ? 1 : 0);

// ─── Helper functions ─────────────────────────────────────────────────────────

/**
 * Recursively scan the local root and return an array of [key => absolutePath].
 */
function collectFiles(string $root, string $prefix = ''): array
{
    $root   = rtrim($root, DIRECTORY_SEPARATOR);
    $result = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $absolutePath = $file->getRealPath();
        // Derive the object key by stripping the root prefix
        $key = ltrim(str_replace(
            [DIRECTORY_SEPARATOR, $root . '/'],
            ['/', ''],
            $absolutePath
        ), '/');
        $key = ltrim(str_replace($root, '', $absolutePath), '/\\');
        $key = ltrim(str_replace('\\', '/', $key), '/');

        if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
            continue;
        }

        $result[$key] = $absolutePath;
    }

    ksort($result);
    return $result;
}

function println(string $line): void
{
    echo $line . PHP_EOL;
}
