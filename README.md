# Storage SDK for PHP

> A production-ready, S3-compatible storage abstraction layer for PHP.
> **Works on shared hosting today. Migrates to Cloudflare R2 or Amazon S3 tomorrow — with zero application code changes.**

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Installation](#installation)
4. [Folder Structure](#folder-structure)
5. [Configuration](#configuration)
6. [Local Storage — Usage Guide](#local-storage--usage-guide)
7. [Cloudflare R2 / Amazon S3 Usage](#cloudflare-r2--amazon-s3-usage)
8. [Full API Reference](#full-api-reference)
9. [File Key System](#file-key-system)
10. [Migration Guide: Local → R2/S3](#migration-guide-local--r2s3)
11. [Security Best Practices](#security-best-practices)
12. [Performance Tips](#performance-tips)
13. [Plain PHP Integration Example](#plain-php-integration-example)
14. [Troubleshooting](#troubleshooting)

---

## Overview

### The Problem

Shared hosting means you can't use S3 today. But you want:

- Clean architecture so you can migrate to S3 later
- No changes to your business logic when you switch
- Existing uploaded files preserved with the same keys

### The Solution

This SDK uses the **Adapter Pattern** to completely decouple your application from the storage backend. Your code always talks to `StorageManager` — never to the filesystem or S3 directly.

```
Your App
   │
   ▼
StorageManager  ──►  LocalAdapter   (shared hosting, today)
                ──►  S3Adapter      (Cloudflare R2 / S3, tomorrow)
```

Switching requires changing one line in `.env`:

```
# Today
STORAGE_DRIVER=local

# Tomorrow
STORAGE_DRIVER=r2
```

---

## Architecture

```
SOLID Principles Applied:
  S — Single Responsibility  (each class has one job)
  O — Open/Closed            (add new adapters without modifying existing code)
  L — Liskov Substitution    (LocalAdapter and S3Adapter are fully interchangeable)
  I — Interface Segregation  (StorageInterface is lean, no fat contracts)
  D — Dependency Inversion   (code depends on StorageInterface, not concrete classes)

Design Patterns Used:
  • Adapter Pattern    — LocalAdapter and S3Adapter both implement StorageInterface
  • Facade Pattern     — StorageManager hides adapter complexity
  • Factory Method     — StorageManager::disk() creates the right adapter
```

---

## Installation

### Requirements

- PHP >= 8.1
- Composer
- (For S3/R2) AWS SDK for PHP (installed automatically via Composer)

### Step 1: Install via Composer

```bash
composer require bloggermohiuddin/storage
```

Or clone and install:

```bash
git clone https://github.com/bloggermohiuddin/storage.git
cd storage-sdk
composer install
```

### Step 2: Create your .env file

```bash
cp .env.example .env
```

Edit `.env` with your values (see [Configuration](#configuration)).

### Step 3: Create storage directory

```bash
mkdir -p storage/uploads
chmod 755 storage/uploads
```

> **Shared Hosting Tip:** The SDK auto-creates subdirectories, but the `storage/uploads` root must exist and be writable.

### Step 4: Protect the storage directory

Add a `.htaccess` inside `storage/uploads/` to block direct script execution:

```apache
Options -Indexes
<FilesMatch "\.(php|php3|php4|php5|phtml|phar|pl|py|cgi|sh|asp|aspx)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
```

---

## Folder Structure

```
storage-sdk/
│
├── config/
│   └── storage.php          ← All disk configurations
│
├── src/
│   ├── Contracts/
│   │   └── StorageInterface.php   ← The contract everything depends on
│   │
│   ├── Adapters/
│   │   ├── LocalAdapter.php       ← Filesystem implementation
│   │   └── S3Adapter.php          ← S3/R2/B2 implementation (AWS SDK)
│   │
│   ├── Managers/
│   │   └── StorageManager.php     ← Facade + driver resolver
│   │
│   ├── Support/
│   │   ├── FileValidator.php      ← MIME, size, extension validation
│   │   ├── KeyGenerator.php       ← UUID-based unique key generation
│   │   └── Logger.php             ← File-based logging
│   │
│   └── Exceptions/
│       ├── StorageException.php
│       ├── FileNotFoundException.php
│       └── InvalidFileException.php
│
├── migration/
│   └── migrate.php          ← CLI migration script (local → S3/R2)
│
├── examples/
│   ├── upload.php           ← HTML form upload demo
│   ├── retrieve.php         ← File info retrieval demo
│   └── serve_signed.php     ← Signed URL serve script
│
├── storage/
│   └── uploads/             ← Local file storage root
│
├── logs/
│   └── storage.log          ← SDK activity log
│
├── .env.example
├── composer.json
└── README.md
```

---

## Configuration

All configuration lives in `config/storage.php`. Values are overridden by environment variables from `.env`.

### Switch the Default Disk

```php
// config/storage.php
'default' => 'local',    // or 'r2', 's3', 'b2'
```

Or via `.env`:

```
STORAGE_DRIVER=r2
```

### Local Disk Configuration

```php
'local' => [
    'driver' => 'local',
    'root'   => dirname(__DIR__) . '/storage/uploads',
    'url'    => 'https://files.example.com',         // public base URL
    'signed_url_secret' => 'your-32-char-secret',    // for token signing
],
```

Generate a secure secret:

```bash
openssl rand -hex 32
```

### Cloudflare R2 Configuration

```php
'r2' => [
    'driver'   => 's3',
    'endpoint' => 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',
    'region'   => 'auto',
    'bucket'   => 'my-bucket',
    'key'      => 'your-r2-access-key-id',
    'secret'   => 'your-r2-secret-access-key',
    'url'      => 'https://files.example.com',  // custom domain (optional)
    'no_acl'   => true,                         // R2 does not support ACLs
],
```

> **R2 Note:** Get your credentials from Cloudflare Dashboard → R2 → API Tokens.
> The endpoint format is: `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`

### Amazon S3 Configuration

```php
's3' => [
    'driver'  => 's3',
    'region'  => 'us-east-1',
    'bucket'  => 'my-bucket',
    'key'     => 'AKIAIOSFODNN7EXAMPLE',
    'secret'  => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    'url'     => '',  // leave empty to use native S3 URLs
],
```

### Backblaze B2 Configuration

```php
'b2' => [
    'driver'   => 's3',
    'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
    'region'   => 'us-west-004',
    'bucket'   => 'my-bucket',
    'key'      => 'your-key-id',
    'secret'   => 'your-application-key',
    'url'      => '',
],
```

---

## Local Storage — Usage Guide

### Basic Upload

```php
<?php
require 'vendor/autoload.php';

use StorageSDK\Managers\StorageManager;

$storage = StorageManager::disk('local');

// Upload from PHP file upload ($_FILES)
$key = $storage->putFile(
    prefix: 'patients',
    sourcePath: $_FILES['photo']['tmp_name'],
    originalName: $_FILES['photo']['name']
);

// SAVE $key TO YOUR DATABASE — not the full URL
// e.g. "patients/2026/04/a1b2c3d4-e5f6-7890.jpg"

echo $storage->url($key);
// → https://files.example.com/patients/2026/04/a1b2c3d4-e5f6-7890.jpg
```

### Upload with Validation

```php
$key = $storage->putFile(
    prefix: 'documents',
    sourcePath: $_FILES['doc']['tmp_name'],
    originalName: $_FILES['doc']['name'],
    options: [
        'validate'      => true,
        'max_size'      => 5 * 1024 * 1024,  // 5 MB
        'date_folders'  => true,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'application/pdf'],
    ]
);
```

### Generate a Signed URL (Temporary Access)

```php
// Valid for 1 hour
$signedUrl = $storage->generateSignedUrl($key, 3600);

// Valid for 24 hours
$signedUrl = $storage->generateSignedUrl($key, 86400);
```

For the **LocalAdapter**, signed URLs use HMAC-SHA256 tokens. Your serve script
(`examples/serve_signed.php`) verifies the token and streams the file.

### Other Operations

```php
// Check existence
if ($storage->exists($key)) { ... }

// Read raw content
$content = $storage->get($key);

// Delete
$storage->delete($key);

// Copy
$storage->copy('patients/original.jpg', 'patients/backup.jpg');

// Move (rename)
$storage->move('patients/temp.jpg', 'patients/final.jpg');

// Metadata
$storage->size($key);          // → 204800  (bytes)
$storage->mimeType($key);      // → "image/jpeg"
$storage->lastModified($key);  // → 1714521600  (Unix timestamp)
```

---

## Cloudflare R2 / Amazon S3 Usage

Switch to R2 in `.env`:

```
STORAGE_DRIVER=r2
```

**Your application code does not change at all.** The same API works identically:

```php
$storage = StorageManager::disk(); // Now uses R2

$key = $storage->putFile('patients', $_FILES['photo']['tmp_name'], $_FILES['photo']['name']);

echo $storage->url($key);
// → https://files.example.com/patients/2026/04/uuid.jpg  (via custom domain)

echo $storage->generateSignedUrl($key, 3600);
// → https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com/...?X-Amz-Signature=...
```

### Switching Per-Request

```php
// Force a specific disk regardless of default
$local  = StorageManager::disk('local');
$r2     = StorageManager::disk('r2');
$s3     = StorageManager::disk('s3');
```

---

## Full API Reference

### `putFile(prefix, sourcePath, originalName, options)` — High-Level Upload

Generates a unique key, validates the file, and stores it.

| Param | Type | Description |
|-------|------|-------------|
| `prefix` | string | Key prefix, e.g. `"patients"` or `"invoices/2026"` |
| `sourcePath` | string | Absolute path to the source file (e.g. `$_FILES['x']['tmp_name']`) |
| `originalName` | string | Original filename (used for extension extraction only) |
| `options` | array | See below |

**Options:**

| Key | Default | Description |
|-----|---------|-------------|
| `validate` | `true` | Run FileValidator before storing |
| `max_size` | `20971520` | Max file size in bytes (20 MB) |
| `allowed_mimes` | `null` | Array of allowed MIME types (null = all non-blocked) |
| `date_folders` | `true` | Add `/year/month/` to key path |
| `no_acl` | `false` | Skip ACL header (required for Cloudflare R2) |

---

### `put(key, source, options)` — Low-Level Store

```php
$storage->put('custom/path/file.jpg', '/tmp/uploaded.jpg');
```

### `get(key)` → string

Returns raw file content.

### `delete(key)` → bool

### `exists(key)` → bool

### `url(key)` → string

Returns the public URL. Uses custom domain if configured.

### `size(key)` → int

File size in bytes.

### `mimeType(key)` → string

Returns MIME type (e.g. `"image/jpeg"`).

### `lastModified(key)` → int

Unix timestamp of last modification.

### `copy(from, to)` → bool

S3 server-side copy (no bandwidth consumed on S3/R2).

### `move(from, to)` → bool

Atomic rename. On S3: copy + delete.

### `generateSignedUrl(key, expiry)` → string

- **LocalAdapter:** HMAC-SHA256 token URL. Requires `serve_signed.php`.
- **S3Adapter:** Native AWS presigned URL. No additional script needed.

---

## File Key System

### The Golden Rule: Store Keys, Not URLs

**❌ Wrong:**
```php
// Don't store full URLs — they break when you change domains or storage providers
$patient->photo = 'https://files.example.com/patients/2026/04/uuid.jpg';
```

**✅ Correct:**
```php
// Store only the key — reconstruct the URL from it anytime
$patient->photo_key = 'patients/2026/04/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg';
```

### Key Format

```
{prefix}/{year}/{month}/{uuid}.{ext}

Examples:
  patients/2026/04/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg
  invoices/2026/04/b2c3d4e5-f6g7-8901-bcde-fg2345678901.pdf
  xrays/2026/04/c3d4e5f6-g7h8-9012-cdef-gh3456789012.png
```

### Key Generation

Keys are auto-generated by `KeyGenerator::generate()`:

```php
use StorageSDK\Support\KeyGenerator;

$key = KeyGenerator::generate(
    originalFilename: 'profile-photo.jpg',
    prefix: 'patients',
    dateFolders: true
);
// → "patients/2026/04/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg"
```

UUID v4 is used (CSPRNG-safe via `random_bytes()`).

---

## Migration Guide: Local → R2/S3

When you're ready to move from local to cloud storage:

### Step 1: Configure the target disk

Add R2/S3 credentials to `.env`:

```
R2_ENDPOINT=https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com
R2_BUCKET=my-bucket
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_PUBLIC_URL=https://files.example.com
```

### Step 2: Dry-run the migration

See what will be migrated without uploading anything:

```bash
php migration/migrate.php --disk=r2 --dry-run
```

### Step 3: Run the migration

```bash
# Migrate all files
php migration/migrate.php --disk=r2

# Migrate only specific prefix
php migration/migrate.php --disk=r2 --prefix=patients/

# Skip files already at the destination (safe to re-run)
php migration/migrate.php --disk=r2 --skip-existing
```

Progress is logged to console and to `logs/storage.log`.

Failed files are saved to `migration/failed.json` for retry.

### Step 4: Switch the default driver

```
# .env
STORAGE_DRIVER=r2
```

No code changes. Deploy and done.

### Step 5: Verify

```bash
# Confirm a few known keys are accessible
php -r "
require 'vendor/autoload.php';
use StorageSDK\Managers\StorageManager;
\$s = StorageManager::disk('r2');
echo \$s->url('patients/2026/04/some-file.jpg') . PHP_EOL;
"
```

### Step 6: (Optional) Clean up local files

Only after confirming R2 migration is complete:

```bash
rm -rf storage/uploads/*
```

---

## Security Best Practices

### 1. Never Serve Uploaded Files Directly Through PHP Execution

```apache
# .htaccess in storage/uploads/
Options -Indexes
<FilesMatch "\.php">
    Order Allow,Deny
    Deny from all
</FilesMatch>
```

### 2. Use a Strong HMAC Secret

```bash
# Generate in terminal
openssl rand -hex 32
```

Set in `.env`:

```
SIGNED_URL_SECRET=a3f9c2d8b1e4...
```

### 3. Keep Storage Root Outside Webroot (Recommended)

```
/var/www/
├── html/          ← webroot (publicly accessible)
│   └── index.php
└── storage/       ← outside webroot (not accessible via URL)
    └── uploads/
```

Configure root in `config/storage.php`:

```php
'root' => '/var/www/storage/uploads',
'url'  => 'https://example.com/serve_signed.php',
```

### 4. Validate All Uploads

The SDK blocks all executable MIME types and extensions by default. Always use `putFile()` with `'validate' => true` for user uploads.

### 5. Use HTTPS Only

Never serve files over plain HTTP. In production, enforce HTTPS in your web server config.

### 6. Rotate Credentials Regularly

- Rotate your HMAC `SIGNED_URL_SECRET` periodically (this invalidates all existing signed URLs)
- Rotate your S3/R2 API keys per your security policy

### 7. Minimum IAM Permissions for S3

```json
{
  "Effect": "Allow",
  "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject", "s3:HeadObject"],
  "Resource": "arn:aws:s3:::my-bucket/*"
}
```

---

## Performance Tips

### 1. Use S3 Server-Side Copy

When using S3Adapter, `copy()` uses server-side copy — no data moves through your server. Extremely efficient for duplication or archiving.

### 2. CDN / Custom Domain for Public Files

In `config/storage.php`, set `url` to your CDN or custom domain:

```php
'url' => 'https://cdn.example.com'
```

This avoids signed URL overhead for public files and leverages edge caching.

### 3. Avoid Fetching File Content Unless Necessary

```php
// For download links — use URL, don't read the whole file
$url = $storage->url($key);    // ✅ Just a string operation

// Only use get() when you need to process the content
$content = $storage->get($key); // Downloads everything into memory
```

### 4. Batch Delete / Background Uploads

For bulk operations, use the migration script as a reference pattern and implement job queues for background processing.

### 5. Limit Log Verbosity in Production

Set `'logging' => false` in `config/storage.php` or `STORAGE_LOGGING=false` in `.env` on high-traffic servers.

---

## Plain PHP Integration Example

Full example without any framework:

```php
<?php
require 'vendor/autoload.php';

use StorageSDK\Managers\StorageManager;
use StorageSDK\Exceptions\InvalidFileException;
use StorageSDK\Exceptions\StorageException;

// ── Upload ────────────────────────────────────────────────────────────────────

function handlePatientPhotoUpload(array $file, int $patientId, PDO $db): string
{
    $storage = StorageManager::disk();

    try {
        $key = $storage->putFile(
            prefix: 'patients',
            sourcePath: $file['tmp_name'],
            originalName: $file['name'],
            options: [
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_size'      => 5 * 1024 * 1024,
            ]
        );

        // Store only the KEY in the database
        $stmt = $db->prepare("UPDATE patients SET photo_key = ? WHERE id = ?");
        $stmt->execute([$key, $patientId]);

        return $key;

    } catch (InvalidFileException $e) {
        throw new \RuntimeException("Invalid file: " . $e->getMessage());
    }
}

// ── Get photo URL for a patient ───────────────────────────────────────────────

function getPatientPhotoUrl(int $patientId, PDO $db): string
{
    $stmt = $db->prepare("SELECT photo_key FROM patients WHERE id = ?");
    $stmt->execute([$patientId]);
    $key = $stmt->fetchColumn();

    if (! $key) {
        return '/assets/default-avatar.png';
    }

    $storage = StorageManager::disk();

    return $storage->url($key);
    // Returns the correct URL regardless of whether backend is local or R2
}

// ── Delete patient photo ──────────────────────────────────────────────────────

function deletePatientPhoto(int $patientId, PDO $db): void
{
    $stmt = $db->prepare("SELECT photo_key FROM patients WHERE id = ?");
    $stmt->execute([$patientId]);
    $key = $stmt->fetchColumn();

    if ($key) {
        StorageManager::disk()->delete($key);

        $db->prepare("UPDATE patients SET photo_key = NULL WHERE id = ?")
           ->execute([$patientId]);
    }
}
```

---

## Troubleshooting

### "Failed to create directory"

Ensure the `storage/uploads` root is writable:

```bash
chmod 755 storage/uploads
```

### "Source file not readable" on S3

The temp file was cleaned up before uploading. Always pass `$_FILES['x']['tmp_name']` directly — don't move the file first.

### "Invalid signature" on signed URL

- Check that `SIGNED_URL_SECRET` matches between URL generation and verification
- Ensure system clocks are synchronised (NTP)

### R2 Access Denied

- Ensure your API token has `Object Read & Write` permissions
- Check `no_acl => true` is set in the `r2` disk config
- Verify your `R2_ENDPOINT` includes the correct Account ID

### S3 Adapter — "Could not resolve host"

- Check `endpoint` format (must include `https://`)
- Verify DNS / network from your server: `curl -I https://your-endpoint`

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

*Built for PHP 8.1+ | Designed for shared hosting | Ready for the cloud*
