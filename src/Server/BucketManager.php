<?php

declare(strict_types=1);

namespace StoragePlatform\Server;

use StoragePlatform\Providers\ProviderFactory;

/**
 * BucketManager — manages lifecycle and DB indexing of virtual/physical storage buckets.
 */
class BucketManager
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a bucket in SQLite and physically in the provider.
     */
    public function createBucket(string $name, int $providerId, string $visibility = 'private'): bool
    {
        // 1. Sanitize bucket name (alphanumeric and dashes/underscores)
        $name = preg_replace('/[^a-zA-Z0-9\-_]/', '', $name);
        if (empty($name)) {
            throw new \InvalidArgumentException("Invalid bucket name.");
        }

        // 2. Resolve provider configurations
        $stmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $stmt->execute(['id' => $providerId]);
        $providerConfig = $stmt->fetch();
        if (!$providerConfig) {
            throw new \RuntimeException("Provider not found.");
        }

        // 3. Check duplicate bucket name in DB
        $chk = $this->db->prepare("SELECT id FROM buckets WHERE name = :name");
        $chk->execute(['name' => $name]);
        if ($chk->fetch()) {
            throw new \RuntimeException("Bucket with name [{$name}] already exists.");
        }

        // 4. Instantiate physical provider and create bucket
        $providerInstance = ProviderFactory::make($providerConfig, $this->db);
        if (!$providerInstance->createBucket($name)) {
            throw new \RuntimeException("Failed to create bucket physically in [{$providerConfig['name']}] provider.");
        }

        // 5. Insert into DB index
        $insert = $this->db->prepare("
            INSERT INTO buckets (provider_id, name, visibility, versioning)
            VALUES (:provider_id, :name, :visibility, 0)
        ");
        return $insert->execute([
            'provider_id' => $providerId,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    /**
     * Delete a bucket in SQLite and physically from the provider.
     */
    public function deleteBucket(int $id): bool
    {
        // 1. Fetch bucket and provider info
        $stmt = $this->db->prepare("
            SELECT b.*, p.name as provider_name, p.driver as provider_driver 
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            WHERE b.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $bucket = $stmt->fetch();
        if (!$bucket) {
            throw new \RuntimeException("Bucket not found.");
        }

        // 2. Fetch provider credentials
        $provStmt = $this->db->prepare("SELECT * FROM storage_providers WHERE id = :id");
        $provStmt->execute(['id' => $bucket['provider_id']]);
        $providerConfig = $provStmt->fetch();

        // 3. Instantiate physical provider and delete bucket
        $providerInstance = ProviderFactory::make($providerConfig, $this->db);
        
        // Remove physically (this will delete files in bucket on provider)
        $providerInstance->deleteBucket($bucket['name']);

        // 4. Remove from DB index
        $delete = $this->db->prepare("DELETE FROM buckets WHERE id = :id");
        return $delete->execute(['id' => $id]);
    }

    /**
     * Get list of all buckets with aggregated stats.
     */
    public function getBucketsWithStats(): array
    {
        $sql = "
            SELECT 
                b.id,
                b.name,
                b.visibility,
                b.versioning,
                b.created_at,
                p.id as provider_id,
                p.name as provider_name,
                p.driver as provider_driver,
                COUNT(o.id) as object_count,
                COALESCE(SUM(o.size), 0) as total_size
            FROM buckets b
            JOIN storage_providers p ON b.provider_id = p.id
            LEFT JOIN objects o ON o.bucket_id = b.id AND o.is_deleted = 0
            GROUP BY b.id
            ORDER BY b.name ASC
        ";

        return $this->db->query($sql)->fetchAll();
    }
}
