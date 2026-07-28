<?php

declare(strict_types=1);

namespace StoragePlatform\Queue;

/**
 * SQLiteQueue — background job runner backed by SQLite database.
 * Uses strict transactions and locking to allow multiple worker processes to safely pull jobs.
 */
class SQLiteQueue implements QueueInterface
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function push(string $jobClass, array $payload = [], string $queue = 'default'): bool
    {
        $now = time();
        $payloadJson = json_encode([
            'job' => $jobClass,
            'data' => $payload,
        ]);

        $stmt = $this->db->prepare("
            INSERT INTO queue_jobs (queue, payload, attempts, reserved_at, available_at, created_at)
            VALUES (:queue, :payload, 0, NULL, :available_at, :created_at)
        ");

        return $stmt->execute([
            'queue' => $queue,
            'payload' => $payloadJson,
            'available_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function pop(string $queue = 'default'): ?array
    {
        $now = time();
        $timeout = $now - 600; // 10 minutes processing timeout

        // Ensure we execute this inside a transaction to prevent double-delivery
        $this->db->beginTransaction();
        try {
            // Find next available job
            $stmt = $this->db->prepare("
                SELECT * FROM queue_jobs
                WHERE queue = :queue
                  AND (reserved_at IS NULL OR reserved_at < :timeout)
                  AND available_at <= :now
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([
                'queue' => $queue,
                'timeout' => $timeout,
                'now' => $now,
            ]);

            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($job) {
                // Reserve job immediately
                $update = $this->db->prepare("
                    UPDATE queue_jobs
                    SET reserved_at = :reserved, attempts = attempts + 1
                    WHERE id = :id
                ");
                $update->execute([
                    'reserved' => $now,
                    'id' => $job['id'],
                ]);

                $this->db->commit();

                $payloadData = json_decode($job['payload'], true) ?: [];

                return [
                    'id' => (int)$job['id'],
                    'job' => $payloadData['job'] ?? '',
                    'payload' => $payloadData['data'] ?? [],
                    'attempts' => (int)$job['attempts'] + 1,
                ];
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return null;
    }

    public function delete(int $jobId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM queue_jobs WHERE id = :id");
        return $stmt->execute(['id' => $jobId]);
    }

    public function release(int $jobId, int $delay = 0): bool
    {
        $availableAt = time() + $delay;
        $stmt = $this->db->prepare("
            UPDATE queue_jobs
            SET reserved_at = NULL, available_at = :available_at
            WHERE id = :id
        ");
        return $stmt->execute([
            'available_at' => $availableAt,
            'id' => $jobId,
        ]);
    }

    public function clear(string $queue = 'default'): bool
    {
        $stmt = $this->db->prepare("DELETE FROM queue_jobs WHERE queue = :queue");
        return $stmt->execute(['queue' => $queue]);
    }
}
