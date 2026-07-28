<?php

declare(strict_types=1);

namespace StoragePlatform\Queue;

/**
 * Worker — executes queued background tasks.
 */
class Worker
{
    protected QueueInterface $queue;
    protected \PDO $db; // Inject PDO if jobs need DB access
    protected bool $shouldQuit = false;

    public function __construct(QueueInterface $queue, \PDO $db)
    {
        $this->queue = $queue;
        $this->db = $db;
    }

    /**
     * Run the worker loop.
     *
     * @param  string $queueName
     * @param  int    $maxAttempts
     * @param  int    $sleep
     * @return void
     */
    public function run(string $queueName = 'default', int $maxAttempts = 3, int $sleep = 2): void
    {
        echo "Worker started. Listening on queue [{$queueName}]..." . PHP_EOL;

        // Register signal handlers for graceful shutdown (where supported)
        if (function_exists('pcntl_signal')) {
            declare(ticks=1);
            pcntl_signal(SIGTERM, function() { $this->shouldQuit = true; });
            pcntl_signal(SIGINT, function() { $this->shouldQuit = true; });
        }

        while (!$this->shouldQuit) {
            try {
                $job = $this->queue->pop($queueName);

                if ($job === null) {
                    sleep($sleep);
                    continue;
                }

                $jobId = $job['id'];
                $jobClass = $job['job'];
                $payload = $job['payload'];
                $attempts = $job['attempts'];

                echo sprintf("[%s] Processing job ID %d (%s), Attempt %d/%d..." . PHP_EOL, date('Y-m-d H:i:s'), $jobId, $jobClass, $attempts, $maxAttempts);

                if (!class_exists($jobClass)) {
                    throw new \RuntimeException("Job class not found: {$jobClass}");
                }

                // Instantiate and execute job
                // We inject PDO database context to jobs if they require it
                $instance = new $jobClass($this->db);
                $instance->handle($payload);

                // Success! Delete the job
                $this->queue->delete($jobId);
                echo sprintf("[%s] Job ID %d completed successfully." . PHP_EOL, date('Y-m-d H:i:s'), $jobId);

            } catch (\Throwable $e) {
                echo sprintf("[%s] Job failed: %s" . PHP_EOL, date('Y-m-d H:i:s'), $e->getMessage());

                if (isset($jobId)) {
                    if ($attempts >= $maxAttempts) {
                        echo sprintf("[%s] Job ID %d exceeded maximum attempts (%d). Deleting." . PHP_EOL, date('Y-m-d H:i:s'), $jobId, $maxAttempts);
                        $this->queue->delete($jobId);
                        
                        // Log job failure specifically in migration if applicable
                        $this->logJobFailure($jobClass, $payload, $e->getMessage());
                    } else {
                        // Release with exponential backoff
                        $delay = (int)pow(2, $attempts) * 5; 
                        echo sprintf("[%s] Releasing Job ID %d back to queue in %d seconds." . PHP_EOL, date('Y-m-d H:i:s'), $jobId, $delay);
                        $this->queue->release($jobId, $delay);
                    }
                }
                
                sleep($sleep);
            }
        }

        echo "Worker shutting down gracefully..." . PHP_EOL;
    }

    protected function logJobFailure(string $jobClass, array $payload, string $error): void
    {
        try {
            // If it was a migration job, update the migration job status in DB
            if (isset($payload['job_id'])) {
                $stmt = $this->db->prepare("
                    UPDATE migration_jobs 
                    SET status = 'failed', completed_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND status = 'processing'
                ");
                $stmt->execute(['id' => $payload['job_id']]);

                $logStmt = $this->db->prepare("
                    INSERT INTO migration_logs (job_id, object_key, status, error_message)
                    VALUES (:job_id, 'SYSTEM', 'failed', :error)
                ");
                $logStmt->execute([
                    'job_id' => $payload['job_id'],
                    'error' => 'Job aborted: ' . $error
                ]);
            }
        } catch (\Exception $ex) {
            // Ignore DB log failures inside worker
        }
    }
}
