<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Queue\SQLiteQueue;
use StoragePlatform\Transfer\TransferJob;

class MigrationController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $sql = "
            SELECT 
                j.*,
                sp.name as source_provider_name,
                tp.name as target_provider_name
            FROM migration_jobs j
            JOIN storage_providers sp ON j.source_provider_id = sp.id
            JOIN storage_providers tp ON j.target_provider_id = tp.id
            ORDER BY j.id DESC
        ";
        $jobs = $this->db->query($sql)->fetchAll();

        foreach ($jobs as &$job) {
            $total = (int)$job['total_objects'];
            $processed = (int)$job['processed_objects'];
            $job['progress_percent'] = ($total > 0) ? round(($processed / $total) * 100, 1) : 0;
            $job['rules'] = json_decode($job['rules'] ?: '{}', true);
        }

        $this->json(['migrations' => $jobs]);
    }

    public function store(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $sourceId = (int)($input['source_provider_id'] ?? 0);
        $targetId = (int)($input['target_provider_id'] ?? 0);
        $rules = $input['rules'] ?? [];

        if ($sourceId === 0 || $targetId === 0) {
            $this->json(['error' => 'Source and target providers are required.'], 400);
            return;
        }

        if ($sourceId === $targetId) {
            $this->json(['error' => 'Source and target provider cannot be the same.'], 400);
            return;
        }

        try {
            // 1. Save migration job entry in database
            $stmt = $this->db->prepare("
                INSERT INTO migration_jobs (source_provider_id, target_provider_id, rules, status)
                VALUES (:source_id, :target_id, :rules, 'pending')
            ");
            $stmt->execute([
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'rules' => json_encode($rules),
            ]);
            $jobId = (int)$this->db->lastInsertId();

            // 2. Dispatch to background SQLite queue
            $queue = new SQLiteQueue($this->db);
            $queue->push(TransferJob::class, ['job_id' => $jobId], 'migrations');

            $this->json(['success' => true, 'job_id' => $jobId]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function logs(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            $this->json(['error' => 'Invalid migration job ID.'], 400);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM migration_logs 
            WHERE job_id = :job_id 
            ORDER BY id DESC 
            LIMIT 100
        ");
        $stmt->execute(['job_id' => $id]);
        $logs = $stmt->fetchAll();

        $this->json(['logs' => $logs]);
    }

    public function cancel(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            $this->json(['error' => 'Invalid migration job ID.'], 400);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE migration_jobs 
            SET status = 'cancelled', completed_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status IN ('pending', 'processing')
        ");
        $stmt->execute(['id' => $id]);

        $this->json(['success' => true]);
    }

    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
