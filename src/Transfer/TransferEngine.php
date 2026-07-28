<?php

declare(strict_types=1);

namespace StoragePlatform\Transfer;

use StoragePlatform\Providers\StorageProviderInterface;

/**
 * TransferEngine — handles copy operations between any two storage providers.
 * Supports filtering rules, dry runs, stream-copying, and live progress hooks.
 */
class TransferEngine
{
    /**
     * Run the transfer operation for a list of files.
     *
     * @param  StorageProviderInterface $source
     * @param  string                   $sourceBucket
     * @param  StorageProviderInterface $target
     * @param  string                   $targetBucket
     * @param  array                    $keys
     * @param  array                    $rules          Rules like overwrite, dry_run, regex
     * @param  callable|null            $onProgress     Callback: fn(string $key, string $status, int $bytes, ?string $error)
     * @param  callable|null            $checkCancelled Callback: fn() -> bool (returns true if job was cancelled)
     * @return array                    Summary stats: ['success' => int, 'skipped' => int, 'failed' => int]
     */
    public function transfer(
        StorageProviderInterface $source,
        string $sourceBucket,
        StorageProviderInterface $target,
        string $targetBucket,
        array $keys,
        array $rules = [],
        ?callable $onProgress = null,
        ?callable $checkCancelled = null
    ): array {
        $stats = ['success' => 0, 'skipped' => 0, 'failed' => 0];

        $overwrite = (bool)($rules['overwrite'] ?? true);
        $dryRun = (bool)($rules['dry_run'] ?? false);
        $regex = $rules['regex'] ?? '';
        $extensions = $rules['extensions'] ?? [];
        $minSize = $rules['min_size'] ?? null;
        $maxSize = $rules['max_size'] ?? null;

        foreach ($keys as $key) {
            // Check cancellation flag first
            if ($checkCancelled && $checkCancelled()) {
                break;
            }

            try {
                // Apply regex filter if provided
                if ($regex !== '' && !preg_match($regex, $key)) {
                    if ($onProgress) {
                        $onProgress($key, 'skipped', 0, "Does not match regex rule: {$regex}");
                    }
                    $stats['skipped']++;
                    continue;
                }

                // Apply extension filter
                if (!empty($extensions)) {
                    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                    if (!in_array($ext, $extensions, true)) {
                        if ($onProgress) {
                            $onProgress($key, 'skipped', 0, "Extension [{$ext}] not allowed by rules");
                        }
                        $stats['skipped']++;
                        continue;
                    }
                }

                // Fetch metadata for size filter & overwrite checks
                $meta = $source->metadata($sourceBucket, $key);
                $size = (int)($meta['size'] ?? 0);

                // Size filters
                if ($minSize !== null && $size < $minSize) {
                    if ($onProgress) {
                        $onProgress($key, 'skipped', 0, "File size {$size}B below minimum {$minSize}B");
                    }
                    $stats['skipped']++;
                    continue;
                }
                if ($maxSize !== null && $size > $maxSize) {
                    if ($onProgress) {
                        $onProgress($key, 'skipped', 0, "File size {$size}B exceeds maximum {$maxSize}B");
                    }
                    $stats['skipped']++;
                    continue;
                }

                // Check exists on target if overwrite is disabled
                if (!$overwrite && $target->exists($targetBucket, $key)) {
                    if ($onProgress) {
                        $onProgress($key, 'skipped', $size, "Object already exists at destination");
                    }
                    $stats['skipped']++;
                    continue;
                }

                // If dry run, count as success and skip upload
                if ($dryRun) {
                    if ($onProgress) {
                        $onProgress($key, 'success', $size, "Dry-run: copied simulated");
                    }
                    $stats['success']++;
                    continue;
                }

                // Perform copy using streaming
                $readStream = $source->streamRead($sourceBucket, $key);
                $uploadSuccess = false;

                if ($readStream !== false) {
                    $uploadSuccess = $target->streamWrite($targetBucket, $key, $readStream);
                    fclose($readStream);
                } else {
                    // Fall back to buffer-based copy
                    $content = $source->get($sourceBucket, $key);
                    $tempFile = tempnam(sys_get_temp_dir(), 'platform_trans_');
                    file_put_contents($tempFile, $content);
                    try {
                        $target->put($targetBucket, $key, $tempFile);
                        $uploadSuccess = true;
                    } finally {
                        @unlink($tempFile);
                    }
                }

                if ($uploadSuccess) {
                    if ($onProgress) {
                        $onProgress($key, 'success', $size, null);
                    }
                    $stats['success']++;
                } else {
                    throw new \RuntimeException("Stream write failed on target provider.");
                }

            } catch (\Throwable $e) {
                if ($onProgress) {
                    $onProgress($key, 'failed', 0, $e->getMessage());
                }
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
