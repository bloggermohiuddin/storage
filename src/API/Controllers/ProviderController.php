<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Providers\ProviderFactory;

class ProviderController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $stmt = $this->db->query("
            SELECT p.*, COUNT(b.id) as bucket_count 
            FROM storage_providers p
            LEFT JOIN buckets b ON b.provider_id = p.id
            GROUP BY p.id
            ORDER BY p.name ASC
        ");
        $providers = $stmt->fetchAll();

        // Run quick health check for each provider
        foreach ($providers as &$p) {
            try {
                $instance = ProviderFactory::make($p, $this->db);
                $health = $instance->health();
                $p['health_status'] = $health['status'];
                $p['health_error'] = $health['error'];
            } catch (\Throwable $e) {
                $p['health_status'] = 'unhealthy';
                $p['health_error'] = $e->getMessage();
            }

            // Redact secret keys before returning to UI for security
            if (!empty($p['secret_key'])) {
                $p['secret_key'] = '••••••••' . substr($p['secret_key'], -4);
            }
        }

        $this->json(['providers' => $providers]);
    }

    public function store(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['name'] ?? '');
        $driver = trim($input['driver'] ?? '');

        if ($name === '' || $driver === '') {
            $this->json(['error' => 'Provider name and driver are required.'], 400);
            return;
        }

        $endpoint = $input['endpoint'] ?? null;
        $region = $input['region'] ?? null;
        $accessKey = $input['access_key'] ?? null;
        $secretKey = $input['secret_key'] ?? null;
        $bucket = $input['bucket'] ?? null;
        $options = isset($input['options']) ? json_encode($input['options']) : null;

        try {
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                // Update existing provider
                $sql = "
                    UPDATE storage_providers 
                    SET name = :name, driver = :driver, endpoint = :endpoint, region = :region,
                        access_key = :access_key, bucket = :bucket, options = :options
                ";
                $params = [
                    'name' => $name,
                    'driver' => $driver,
                    'endpoint' => $endpoint,
                    'region' => $region,
                    'access_key' => $accessKey,
                    'bucket' => $bucket,
                    'options' => $options,
                    'id' => $id,
                ];

                // Only update secret key if a new non-redacted one is provided
                if (!empty($secretKey) && !str_starts_with($secretKey, '••••')) {
                    $sql .= ", secret_key = :secret_key";
                    $params['secret_key'] = $secretKey;
                }

                $sql .= " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            } else {
                // Create new provider
                $stmt = $this->db->prepare("
                    INSERT INTO storage_providers (name, driver, endpoint, region, access_key, secret_key, bucket, options, is_active)
                    VALUES (:name, :driver, :endpoint, :region, :access_key, :secret_key, :bucket, :options, 1)
                ");
                $stmt->execute([
                    'name' => $name,
                    'driver' => $driver,
                    'endpoint' => $endpoint,
                    'region' => $region,
                    'access_key' => $accessKey,
                    'secret_key' => $secretKey,
                    'bucket' => $bucket,
                    'options' => $options,
                ]);
            }

            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function validateConnection(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($input['driver'])) {
            $this->json(['error' => 'Provider configuration is incomplete.'], 400);
            return;
        }

        try {
            $instance = ProviderFactory::make($input, $this->db);
            $health = $instance->health();
            
            if ($health['status'] === 'healthy') {
                $this->json(['success' => true, 'message' => 'Connection successful!']);
            } else {
                $this->json(['error' => 'Connection failed: ' . $health['error']], 400);
            }
        } catch (\Throwable $e) {
            $this->json(['error' => 'Validation error: ' . $e->getMessage()], 400);
        }
    }

    public function delete(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            $this->json(['error' => 'Invalid provider ID.'], 400);
            return;
        }

        // Check if any bucket uses this provider
        $chk = $this->db->prepare("SELECT id FROM buckets WHERE provider_id = :id LIMIT 1");
        $chk->execute(['id' => $id]);
        if ($chk->fetch()) {
            $this->json(['error' => 'Cannot delete provider that has active buckets.'], 400);
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM storage_providers WHERE id = :id");
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
