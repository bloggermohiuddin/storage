<?php

declare(strict_types=1);

namespace StoragePlatform\Server;

/**
 * LifecycleEngine — Evaluates object expiration, noncurrent version cleanup, and quota limits.
 */
class LifecycleEngine
{
    protected \PDO $db;
    protected ObjectManager $objectManager;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->objectManager = new ObjectManager($db);
    }

    /**
     * Run lifecycle evaluation across all buckets.
     */
    public function processLifecycleRules(): array
    {
        $stats = ['expired_objects' => 0, 'purged_versions' => 0];

        $stmt = $this->db->query("SELECT * FROM lifecycle_rules WHERE status = 'enabled'");
        $rules = $stmt->fetchAll();

        foreach ($rules as $rule) {
            $bucketId = (int)$rule['bucket_id'];
            $prefix = $rule['prefix'] ?? '';

            // 1. Check expiration_days
            if (!empty($rule['expiration_days']) && $rule['expiration_days'] > 0) {
                $days = (int)$rule['expiration_days'];
                $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

                $objStmt = $this->db->prepare("
                    SELECT * FROM objects 
                    WHERE bucket_id = :b AND key LIKE :p AND created_at <= :cutoff AND is_deleted = 0
                ");
                $objStmt->execute(['b' => $bucketId, 'p' => $prefix . '%', 'cutoff' => $cutoffDate]);
                $expired = $objStmt->fetchAll();

                foreach ($expired as $obj) {
                    $this->objectManager->deleteObject($bucketId, $obj['key']);
                    $stats['expired_objects']++;
                }
            }

            // 2. Check noncurrent_version_expiration_days
            if (!empty($rule['noncurrent_version_expiration_days']) && $rule['noncurrent_version_expiration_days'] > 0) {
                $vDays = (int)$rule['noncurrent_version_expiration_days'];
                $vCutoff = date('Y-m-d H:i:s', strtotime("-{$vDays} days"));

                $vStmt = $this->db->prepare("
                    SELECT * FROM object_versions 
                    WHERE bucket_id = :b AND key LIKE :p AND created_at <= :cutoff
                ");
                $vStmt->execute(['b' => $bucketId, 'p' => $prefix . '%', 'cutoff' => $vCutoff]);
                $oldVersions = $vStmt->fetchAll();

                foreach ($oldVersions as $v) {
                    $delV = $this->db->prepare("DELETE FROM object_versions WHERE id = :id");
                    $delV->execute(['id' => $v['id']]);
                    $stats['purged_versions']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Verify whether writing $addedBytes to bucket exceeds set quota.
     */
    public function checkQuota(int $bucketId, int $addedBytes = 0): bool
    {
        $stmt = $this->db->prepare("SELECT quota_bytes, quota_objects FROM buckets WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $bucketId]);
        $b = $stmt->fetch();

        if (!$b) {
            return true;
        }

        if ($b['quota_bytes'] > 0) {
            $sumStmt = $this->db->prepare("SELECT SUM(size) as total_size FROM objects WHERE bucket_id = :id AND is_deleted = 0");
            $sumStmt->execute(['id' => $bucketId]);
            $currentBytes = (int)($sumStmt->fetch()['total_size'] ?? 0);

            if (($currentBytes + $addedBytes) > $b['quota_bytes']) {
                throw new \RuntimeException("Storage quota exceeded for bucket: Max allowed is {$b['quota_bytes']} bytes.");
            }
        }

        if ($b['quota_objects'] > 0) {
            $cntStmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM objects WHERE bucket_id = :id AND is_deleted = 0");
            $cntStmt->execute(['id' => $bucketId]);
            $currentCount = (int)($cntStmt->fetch()['cnt'] ?? 0);

            if (($currentCount + 1) > $b['quota_objects']) {
                throw new \RuntimeException("Object count quota exceeded for bucket: Max allowed is {$b['quota_objects']} objects.");
            }
        }

        return true;
    }
}
