<?php

declare(strict_types=1);

namespace StoragePlatform\Transfer;

use StoragePlatform\Providers\ProviderFactory;

/**
 * TransferJob — background queue job representing a migration.
 */
class TransferJob
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function handle(array $payload): void
    {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId === 0) {
            return;
        }

        // Fetch job metadata
        $stmt = $this->db->prepare("SELECT * FROM migration_jobs WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$job || in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        // Update status to processing
        $update = $this->db->prepare("
            UPDATE migration_jobs 
            SET status = 'processing', started_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $update->execute(['id' => $jobId]);

        try {
            // Resolve source provider config
            $srcProviderStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
            $srcProviderStmt->execute(['id' => $job['source_provider_id']]);
            $srcConfig = $srcProviderStmt->fetch(\PDO::FETCH_ASSOC);

            // Resolve target provider config
            $tgtProviderStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
            $tgtProviderStmt->execute(['id' => $job['target_provider_id']]);
            $tgtConfig = $tgtProviderStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$srcConfig || !$tgtConfig) {
                throw new \RuntimeException("Source or target storage provider configuration not found.");
            }

            // Create provider instances
            $sourceProvider = ProviderFactory::make($srcConfig, $this->db);
            $targetProvider = ProviderFactory::make($tgtConfig, $this->db);

            $sourceBucket = $srcConfig['bucket'] ?: 'default';
            $targetBucket = $tgtConfig['bucket'] ?: 'default';

            $rules = json_decode($job['rules'] ?: '{}', true);
            $prefix = $rules['prefix'] ?? '';

            // Get the list of all keys from the source provider
            $keys = $sourceProvider->listObjects($sourceBucket, $prefix);
            $totalObjects = count($keys);

            // Calculate total bytes
            $totalBytes = 0;
            $objectSizes = [];
            foreach ($keys as $key) {
                try {
                    $meta = $sourceProvider->metadata($sourceBucket, $key);
                    $sz = (int)($meta['size'] ?? 0);
                    $totalBytes += $sz;
                    $objectSizes[$key] = $sz;
                } catch (\Throwable $e) {
                    $objectSizes[$key] = 0;
                }
            }

            // Update total counts
            $updateCounts = $this->db->prepare("
                UPDATE migration_jobs 
                SET total_objects = :total_objects, total_bytes = :total_bytes 
                WHERE id = :id
            ");
            $updateCounts->execute([
                'total_objects' => $totalObjects,
                'total_bytes' => $totalBytes,
                'id' => $jobId
            ]);

            if ($totalObjects === 0) {
                $complete = $this->db->prepare("
                    UPDATE migration_jobs 
                    SET status = 'completed', completed_at = CURRENT_TIMESTAMP 
                    WHERE id = :id
                ");
                $complete->execute(['id' => $jobId]);
                return;
            }

            $engine = new TransferEngine();

            $processed = 0;
            $failed = 0;
            $bytesTransferred = 0;

            // Callback to update progress and logs in the database
            $onProgress = function(string $key, string $status, int $bytes, ?string $error) use ($jobId, &$processed, &$failed, &$bytesTransferred) {
                if ($status === 'success') {
                    $processed++;
                    $bytesTransferred += $bytes;
                } elseif ($status === 'skipped') {
                    // Skipped counts as processed for tracking but doesn't add bytes
                    $processed++;
                    $bytesTransferred += $bytes;
                } else {
                    $failed++;
                }

                // Log migration detail in DB
                $logStmt = $this->db->prepare("
                    INSERT INTO migration_logs (job_id, object_key, status, error_message, bytes)
                    VALUES (:job_id, :key, :status, :error, :bytes)
                ");
                $logStmt->execute([
                    'job_id' => $jobId,
                    'key' => $key,
                    'status' => $status,
                    'error' => $error,
                    'bytes' => $bytes
                ]);

                // Update job metrics periodically
                $progressStmt = $this->db->prepare("
                    UPDATE migration_jobs 
                    SET processed_objects = :processed, 
                        failed_objects = :failed, 
                        bytes_transferred = :bytes
                    WHERE id = :id
                ");
                $progressStmt->execute([
                    'processed' => $processed,
                    'failed' => $failed,
                    'bytes' => $bytesTransferred,
                    'id' => $jobId
                ]);
            };

            // Callback to check if user cancelled the job via the UI
            $checkCancelled = function() use ($jobId): bool {
                $statusStmt = $this->db->prepare("SELECT status FROM migration_jobs WHERE id = :id");
                $statusStmt->execute(['id' => $jobId]);
                $currentStatus = $statusStmt->fetchColumn();
                return in_array($currentStatus, ['cancelled', 'paused'], true);
            };

            // Execute the transfer
            $summary = $engine->transfer(
                $sourceProvider,
                $sourceBucket,
                $targetProvider,
                $targetBucket,
                $keys,
                $rules,
                $onProgress,
                $checkCancelled
            );

            // Fetch final status to see if it was cancelled mid-run
            $statusStmt = $this->db->prepare("SELECT status FROM migration_jobs WHERE id = :id");
            $statusStmt->execute(['id' => $jobId]);
            $finalStatus = $statusStmt->fetchColumn();

            if ($finalStatus === 'cancelled') {
                echo "Migration job ID {$jobId} was cancelled by user." . PHP_EOL;
            } elseif ($finalStatus === 'paused') {
                echo "Migration job ID {$jobId} was paused." . PHP_EOL;
            } else {
                $finalJobStatus = ($summary['failed'] > 0) ? 'failed' : 'completed';
                $complete = $this->db->prepare("
                    UPDATE migration_jobs 
                    SET status = :status, completed_at = CURRENT_TIMESTAMP 
                    WHERE id = :id
                ");
                $complete->execute([
                    'status' => $finalJobStatus,
                    'id' => $jobId
                ]);
                echo "Migration job ID {$jobId} finished with status: {$finalJobStatus}." . PHP_EOL;
            }

        } catch (\Throwable $e) {
            $fail = $this->db->prepare("
                UPDATE migration_jobs 
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $fail->execute(['id' => $jobId]);

            $logFail = $this->db->prepare("
                INSERT INTO migration_logs (job_id, object_key, status, error_message)
                VALUES (:job_id, 'SYSTEM', 'failed', :error)
            ");
            $logFail->execute([
                'job_id' => $jobId,
                'error' => 'Fatal: ' . $e->getMessage()
            ]);

            throw $e;
        }
    }
}
