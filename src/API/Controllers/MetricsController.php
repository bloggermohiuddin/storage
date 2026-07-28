<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

class MetricsController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $buckets  = (int) $this->db->query("SELECT COUNT(*) FROM buckets")->fetchColumn();
        $objects  = (int) $this->db->query("SELECT COUNT(*) FROM objects WHERE is_deleted = 0")->fetchColumn();
        $totalBytes = (int) $this->db->query("SELECT COALESCE(SUM(size),0) FROM objects WHERE is_deleted = 0")->fetchColumn();
        $providers = (int) $this->db->query("SELECT COUNT(*) FROM storage_providers WHERE is_active = 1")->fetchColumn();
        $apiKeys  = (int) $this->db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();

        $pendingJobs    = (int) $this->db->query("SELECT COUNT(*) FROM migration_jobs WHERE status='pending'")->fetchColumn();
        $processingJobs = (int) $this->db->query("SELECT COUNT(*) FROM migration_jobs WHERE status='processing'")->fetchColumn();
        $completedJobs  = (int) $this->db->query("SELECT COUNT(*) FROM migration_jobs WHERE status='completed'")->fetchColumn();
        $failedJobs     = (int) $this->db->query("SELECT COUNT(*) FROM migration_jobs WHERE status='failed'")->fetchColumn();

        $queueDepth = (int) $this->db->query("SELECT COUNT(*) FROM queue_jobs WHERE reserved_at IS NULL")->fetchColumn();

        // Per-provider breakdown
        $providerStats = $this->db->query("
            SELECT
                p.name,
                p.driver,
                COUNT(b.id) as bucket_count,
                COALESCE(SUM(o.size), 0) as total_bytes,
                COUNT(o.id) as object_count
            FROM storage_providers p
            LEFT JOIN buckets b ON b.provider_id = p.id
            LEFT JOIN objects o ON o.bucket_id = b.id AND o.is_deleted = 0
            GROUP BY p.id
            ORDER BY p.name ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Recent audit trail (last 20 entries)
        $recentLogs = $this->db->query("
            SELECT a.action, a.details, a.created_at, u.username
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.id DESC
            LIMIT 20
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $this->json([
            'summary' => [
                'buckets'       => $buckets,
                'objects'       => $objects,
                'total_bytes'   => $totalBytes,
                'providers'     => $providers,
                'api_keys'      => $apiKeys,
            ],
            'queue' => [
                'depth'         => $queueDepth,
                'pending_jobs'  => $pendingJobs,
                'processing'    => $processingJobs,
                'completed'     => $completedJobs,
                'failed'        => $failedJobs,
            ],
            'providers' => $providerStats,
            'recent_logs' => $recentLogs,
        ]);
    }

    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
