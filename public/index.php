<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use StoragePlatform\API\Router;
use StoragePlatform\API\Controllers\AuthController;
use StoragePlatform\API\Controllers\BucketController;
use StoragePlatform\API\Controllers\ObjectController;
use StoragePlatform\API\Controllers\ProviderController;
use StoragePlatform\API\Controllers\MigrationController;
use StoragePlatform\API\Controllers\MetricsController;
use StoragePlatform\API\Controllers\CredentialController;
use StoragePlatform\API\Controllers\ServerInfoController;
use StoragePlatform\API\S3\S3ApiController;

$uri = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Handle direct /object/:bucket/*key public object URLs
if (str_starts_with($uri, '/object/')) {
    $subPath = substr($uri, 8); // trim '/object/'
    $parts = explode('/', $subPath, 2);
    $bucket = $parts[0] ?? '';
    $key = $parts[1] ?? '';
    $_GET['bucket'] = $bucket;
    $_GET['key'] = $key;
    $db = \StoragePlatform\Server\Database::getConnection();
    (new ObjectController($db))->stream();
    exit;
}

// Handle S3 protocol / S3 headers / non-api bucket routes
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$hasAmzHeaders = isset($_SERVER['HTTP_X_AMZ_DATE']) || isset($_SERVER['HTTP_X_AMZ_CONTENT_SHA256']) || str_contains($authHeader, 'AWS4-HMAC-SHA256');

if (!str_starts_with($uri, '/api/') && !str_starts_with($uri, '/css/') && !str_starts_with($uri, '/js/') && $uri !== '/' && $uri !== '/index.php') {
    if ($hasAmzHeaders || in_array($method, ['PUT', 'DELETE', 'HEAD'], true) || isset($_GET['uploads']) || isset($_GET['uploadId'])) {
        (new S3ApiController())->dispatch();
        exit;
    }
}

// Handle S3-style /{bucket}/{key} direct public/private object access
if (!str_starts_with($uri, '/api/') && !str_starts_with($uri, '/css/') && !str_starts_with($uri, '/js/') && !str_starts_with($uri, '/object/') && $uri !== '/' && $uri !== '/index.php') {
    $trimmed = ltrim($uri, '/');
    $slashPos = strpos($trimmed, '/');
    if ($slashPos !== false) {
        $bucket = substr($trimmed, 0, $slashPos);
        $key = substr($trimmed, $slashPos + 1);
        if ($bucket !== '' && $key !== '') {
            $_GET['bucket'] = $bucket;
            $_GET['key'] = $key;
            $db = \StoragePlatform\Server\Database::getConnection();
            (new ObjectController($db))->stream();
            exit;
        }
    }
}

$router = new Router();

// Public Info Route (no auth — used by login page & debugging)
$router->get('/api/server-info', [ServerInfoController::class, 'index']);

// Auth Routes
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me'], ['auth']);
$router->get('/api/auth/keys', [AuthController::class, 'getKeys'], ['auth']);
$router->post('/api/auth/keys', [AuthController::class, 'createKey'], ['auth']);
$router->delete('/api/auth/keys/{id}', [AuthController::class, 'deleteKey'], ['auth']);

// Credentials & R2 API Routes
$router->get('/api/credentials', [CredentialController::class, 'index'], ['auth']);
$router->post('/api/credentials/generate', [CredentialController::class, 'generate'], ['auth']);

// Bucket Routes
$router->get('/api/buckets', [BucketController::class, 'index'], ['auth']);
$router->post('/api/buckets', [BucketController::class, 'store'], ['auth']);
$router->delete('/api/buckets/{id}', [BucketController::class, 'delete'], ['auth']);

// Object Routes
$router->get('/api/objects', [ObjectController::class, 'index'], ['auth']);
$router->post('/api/objects', [ObjectController::class, 'upload'], ['auth']);
$router->post('/api/objects/delete', [ObjectController::class, 'delete'], ['auth']);
$router->post('/api/objects/copy', [ObjectController::class, 'copy'], ['auth']);
$router->get('/api/objects/stream', [ObjectController::class, 'stream']); // Stream link handles signed or public validation internally

// Storage Provider Routes
$router->get('/api/providers', [ProviderController::class, 'index'], ['auth']);
$router->post('/api/providers', [ProviderController::class, 'store'], ['auth']);
$router->post('/api/providers/validate', [ProviderController::class, 'validateConnection'], ['auth']);
$router->delete('/api/providers/{id}', [ProviderController::class, 'delete'], ['auth']);

// Migration Engine Routes
$router->get('/api/migrations', [MigrationController::class, 'index'], ['auth']);
$router->post('/api/migrations', [MigrationController::class, 'store'], ['auth']);
$router->get('/api/migrations/{id}/logs', [MigrationController::class, 'logs'], ['auth']);
$router->post('/api/migrations/{id}/cancel', [MigrationController::class, 'cancel'], ['auth']);

// Metrics Route
$router->get('/api/metrics', [MetricsController::class, 'index'], ['auth']);

// Dispatch HTTP request
$router->dispatch();

