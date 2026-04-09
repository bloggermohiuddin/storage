<?php

/**
 * ============================================================================
 * Example: Upload a file from an HTML form (plain PHP, no framework)
 * ============================================================================
 *
 * This is a drop-in example you can adapt to your existing application.
 * The storage backend is completely swappable via config — no changes here.
 * ============================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StorageSDK\Managers\StorageManager;
use StorageSDK\Exceptions\InvalidFileException;
use StorageSDK\Exceptions\StorageException;

// ─── Handle POST upload ───────────────────────────────────────────────────────

$response = ['success' => false, 'key' => null, 'url' => null, 'error' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];

    // Basic PHP upload checks
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $response['error'] = 'Upload error code: ' . $file['error'];
    } else {
        try {
            // Get the configured storage disk (reads STORAGE_DRIVER from .env)
            $storage = StorageManager::disk(); // uses 'default' disk

            // To force a specific disk, pass the name:
            // $storage = StorageManager::disk('r2');

            // Upload with auto key generation + built-in validation
            $key = $storage->putFile(
                prefix: 'patients',                  // folder prefix
                sourcePath: $file['tmp_name'],        // temp path from PHP
                originalName: $file['name'],          // original filename (for extension)
                options: [
                    'validate'     => true,
                    'max_size'     => 10 * 1024 * 1024, // 10 MB
                    'date_folders' => true,              // patients/2026/04/uuid.jpg
                    'allowed_mimes'=> [                  // optional allowlist
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'application/pdf',
                    ],
                ]
            );

            $response = [
                'success'       => true,
                'key'           => $key,
                'url'           => $storage->url($key),
                'signed_url'    => $storage->generateSignedUrl($key, expiry: 3600),
                'size'          => $storage->size($key),
                'mime'          => $storage->mimeType($key),
                'last_modified' => date('Y-m-d H:i:s', $storage->lastModified($key)),
                'error'         => null,
            ];

            // ─── Store the KEY in your database, NOT the full URL ──────────────
            // Example (PDO):
            // $pdo->prepare("UPDATE patients SET profile_photo = ? WHERE id = ?")
            //     ->execute([$key, $patientId]);
            // ──────────────────────────────────────────────────────────────────

        } catch (InvalidFileException $e) {
            $response['error'] = 'File rejected: ' . $e->getMessage();
        } catch (StorageException $e) {
            $response['error'] = 'Storage error: ' . $e->getMessage();
        }
    }
}

// ─── Output JSON if called via AJAX ──────────────────────────────────────────

if (! empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Storage SDK — Upload Example</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; }
        pre  { background: #f4f4f4; padding: 16px; border-radius: 6px; overflow-x: auto; }
        .ok  { color: green; } .err { color: red; }
    </style>
</head>
<body>
<h2>File Upload — Storage SDK Demo</h2>

<?php if ($response['error']): ?>
    <p class="err">❌ <?= htmlspecialchars($response['error']) ?></p>
<?php elseif ($response['success']): ?>
    <p class="ok">✅ File uploaded successfully!</p>
    <pre><?= htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)) ?></pre>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <label>Choose file:
        <input type="file" name="file" accept="image/*,application/pdf">
    </label>
    <br><br>
    <button type="submit">Upload</button>
</form>

<hr>
<h3>Quick Other Operations</h3>
<pre>
<?php

use StorageSDK\Exceptions\FileNotFoundException;

// These examples only run if a key was uploaded above
if ($response['success']) {
    $storage = StorageManager::disk();
    $key     = $response['key'];

    // Copy
    $copyKey = str_replace('patients/', 'patients_archive/', $key);
    $storage->copy($key, $copyKey);
    echo "Copied  → {$copyKey}\n";

    // Check existence
    echo "Exists  → " . ($storage->exists($key) ? 'true' : 'false') . "\n";

    // Signed URL (expires in 1 hour)
    echo "Signed  → " . $storage->generateSignedUrl($key, 3600) . "\n";

    // Clean up copy
    $storage->delete($copyKey);
    echo "Deleted copy\n";
}
?>
</pre>

</body>
</html>
