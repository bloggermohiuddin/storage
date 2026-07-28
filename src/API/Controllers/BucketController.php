<?php

declare(strict_types=1);

namespace StoragePlatform\API\Controllers;

use StoragePlatform\Server\BucketManager;

class BucketController
{
    protected \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $manager = new BucketManager($this->db);
        $buckets = $manager->getBucketsWithStats();
        $this->json(['buckets' => $buckets]);
    }

    public function store(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = $input['name'] ?? '';
        $providerId = (int)($input['provider_id'] ?? 0);
        $visibility = $input['visibility'] ?? 'private';

        if ($name === '' || $providerId === 0) {
            $this->json(['error' => 'Bucket name and provider ID are required.'], 400);
            return;
        }

        try {
            $manager = new BucketManager($this->db);
            $manager->createBucket($name, $providerId, $visibility);
            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function delete(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            $this->json(['error' => 'Invalid bucket ID.'], 400);
            return;
        }

        try {
            $manager = new BucketManager($this->db);
            $manager->deleteBucket($id);
            $this->json(['success' => true]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
