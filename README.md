# Self-Hosted Object Storage Platform (Cloudflare R2 & S3 Compatible)

> A production-grade, self-hosted Object Storage platform for PHP 8.1+.  
> Behaves like **Cloudflare R2** while remaining fully **Amazon S3-compatible**.  
> Runs locally on high-performance hashed storage or SQLite — no external server dependencies required.  
> Seamlessly switch between **Local → R2 → S3 → MinIO → B2 → FTP** simply by updating environment configurations.

---

## Key Highlights

- **S3-Style Public URLs**: Files are accessible at `/{bucket}/{key}` — clean, permanent URLs for public buckets, signed URLs for private buckets. No query-string soup.
- **Cloudflare R2 Credentials & Compatibility**: Generates `ACCOUNT_ID`, `ACCESS_KEY`, `SECRET_KEY`, `DEFAULT_BUCKET`, `ENDPOINT`, and `PUBLIC_URL` format. Applications switch between local storage and production R2/S3 with **zero code changes**.
- **Amazon S3 API Protocol Handler**: Native support for S3 endpoints (`PUT /:bucket/:key`, `GET /:bucket/:key`, `HEAD`, `DELETE`, `GET /:bucket`, S3 Multipart Uploads) with AWS Signature Version 4 (SigV4) authorization and S3 XML responses.
- **Hashed Local Storage Engine**: Nested 2-level hashed directory layout (`storage/buckets/uploads/a1/b2/object.data`) with isolated metadata storage supporting millions of files efficiently.
- **Fluent PHP SDK (`Storage` Facade)**: Standard chainable API: `$storage = Storage::driver('local'); $storage->bucket('uploads')->put('doc.pdf', $file);`.
- **Background Migration Engine**: Multi-provider migration engine with parallel chunking, stream copy, progress tracking, and checksum verification.
- **Full Admin Console**: Dark glassmorphic dashboard for bucket management, object file browser, credentials manager, live analytics, and migration control.

---

## Quick Start (Local Development)

### 1. Start Server
```bash
php -S localhost:8080 -t public
```

### 2. Configure `.env`

```ini
# Required — your application base URL
APP_URL=http://localhost:8080

# Required — HMAC secret for signing private URLs (generate with: openssl rand -hex 32)
SIGNED_URL_SECRET=2096cbd880a40dc729954fc56970e8609d92210b75f12e2ba85b3d8a150f96fd

# Storage driver
STORAGE_DRIVER=local
```

### 3. Default Local Credentials
```ini
ACCOUNT_ID=local
ACCESS_KEY=local_access_key
SECRET_KEY=local_secret_key_1234567890
DEFAULT_BUCKET=uploads
ENDPOINT=http://localhost:8080
PUBLIC_URL=http://localhost:8080
```

> **Note:** Update `ENDPOINT` and `PUBLIC_URL` to match your `APP_URL` if not using localhost.

### 4. Usage with PHP SDK
```php
use StoragePlatform\SDK\Storage;

$storage = Storage::driver('local'); // Or 'r2', 's3', 'minio'

// Upload file
$storage->bucket('uploads')->put('invoice.pdf', '/path/to/local/file.pdf');

// Get direct public URL (S3-style: /{bucket}/{key})
$publicUrl = $storage->url('invoice.pdf');
// → http://localhost:8080/uploads/invoice.pdf

// Get signed temporary URL (for private buckets)
$signedUrl = $storage->temporaryUrl('invoice.pdf', 3600);
// → http://localhost:8080/uploads/invoice.pdf?expires=...&signature=...

// Delete file
$storage->delete('invoice.pdf');
```

### 5. Deploying to Shared Hosting (cPanel / DirectAdmin Subdomain)

1. **Upload Files**: Upload the repository to your server (e.g. `/home/username/storage`).
2. **Set Subdomain Document Root**:
   - In cPanel / DirectAdmin, create your subdomain (e.g., `storage.yourdomain.com`).
   - Set the **Document Root** to `/home/username/storage/public` (the `public/` directory).
3. **Configure `.env`**: Set `APP_URL` to your domain (e.g. `APP_URL=https://storage.yourdomain.com`) and generate a secure `SIGNED_URL_SECRET`.
4. **Verify PHP Version & Extensions**:
   - Set PHP version to **PHP 8.2+** (or 8.1+).
   - Ensure `pdo_sqlite` extension is enabled.
5. **Directory Permissions**:
   - Make `storage/` and `database/` writable (`755` permissions).
6. **Set Up Cron Job for Background Worker**:
   - In cPanel Cron Jobs, add a 1-minute cron job to run background migrations and lifecycle tasks:
     ```bash
     * * * * * php /home/username/storage/cli/worker.php >> /dev/null 2>&1
     ```

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Module Breakdown](#module-breakdown)
4. [Folder Structure](#folder-structure)
5. [Quick Start](#quick-start)
6. [Admin Dashboard](#admin-dashboard)
7. [Storage Providers & Drivers](#storage-providers)
8. [URL Formats & Access](#url-formats)
9. [Migration Engine](#migration-engine)
10. [REST API Reference](#rest-api-reference)
11. [PHP SDK Facade](#php-sdk)
12. [CLI Tools & Testing](#cli-tools)

---

## Architecture

```
+-----------------------------------------------------------------------------------+
|                                  Client / App                                    |
|   (AWS SDK / R2 Client / PHP SDK / S3cmd / Web Dashboard / REST API Clients)      |
+----------------------------------------+------------------------------------------+
                                         |
                                  http://localhost:8080
                                         |
+----------------------------------------v------------------------------------------+
|                            Unified Server Entrypoint                              |
|                              (public/index.php)                                   |
+-------------------+-----------------------------------+---------------------------+
                    |                                   |
        +-----------v-----------+           +-----------v-----------+
        |   REST API Router     |           |    S3 API Router      |
        |     (/api/...)        |           |  (SigV4 & S3 Protocol)|
        +-----------+-----------+           +-----------+-----------+
                    |                                   |
                    +-----------------+-----------------+
                                      |
+-------------------------------------v---------------------------------------------+
|                                Core Domain Services                               |
|   BucketService | ObjectService | AuthEngine | MetadataService | LifecycleService    |
|   MultipartEngine | SignedUrlEngine | StorageQuotaService | MigrationEngine       |
+-------------------------------------v---------------------------------------------+
                                      |
+-------------------------------------v---------------------------------------------+
|                                Driver Subsystem                                   |
|   LocalDriver (Hashed) | S3Driver | R2Driver | MinIODriver | FTP | SFTP | Memory    |
+-------------------------------------v---------------------------------------------+
                                      |
                  +-------------------+-------------------+
                  |                                       |
        +---------v---------+                   +---------v---------+
        |  Local Filesystem |                   |  SQLite Database  |
        |  (storage/...)    |                   |  (Metadata/Keys)  |
        +-------------------+                   +-------------------+
```

---

## Module Breakdown

### 1. Providers Module (`src/Providers/`)

Every provider implements `StorageProviderInterface` with identical capabilities:

```php
put(bucket, key, source, options): string
get(bucket, key): string
delete(bucket, key): bool
exists(bucket, key): bool
copy(bucket, fromKey, toKey): bool
move(bucket, fromKey, toKey): bool
metadata(bucket, key): array
listObjects(bucket, prefix): array
streamRead(bucket, key): resource
streamWrite(bucket, key, resource, options): bool
temporaryUrl(bucket, key, expiry): string
```
listBuckets(): array
createBucket(name, options): bool
deleteBucket(name): bool
health(): array
```

Available providers: `LocalProvider`, `MyCloudProvider`, `S3Provider`, `R2Provider`, `B2Provider`, `MinIOProvider`.

Adding a new provider requires only implementing the interface — zero changes elsewhere.

---

### 2. MyCloud Provider (default engine)

MyCloud is the built-in self-hosted storage engine. It uses:
- **SQLite** for all metadata (object index, bucket registry, hashes)
- **Hashed filesystem layout** for physical file storage

Physical layout uses SHA-256 to prevent directory bloat at scale:
```
storage/mycloud/data/
  ab/
    cd/
      abcdef1234567890...   ← actual file bytes
```

This layout supports millions of files without filesystem performance degradation.

---

### 3. Queue Module (`src/Queue/`)

A generic, swappable background queue backed by SQLite. Designed to be replaced with Redis, RabbitMQ, or SQS with no changes to the Transfer Engine.

```
QueueInterface::push(jobClass, payload, queue)
QueueInterface::pop(queue): ?array
QueueInterface::delete(jobId): bool
QueueInterface::release(jobId, delay): bool
```

---

### 4. Transfer Engine (`src/Transfer/`)

Moves objects between any two `StorageProviderInterface` instances using streaming (no full-file buffering). Supports:

- Prefix filters
- Regex filters  
- Extension allow-lists
- Min/max file size filters
- Skip-existing / overwrite rules
- Dry-run simulation
- Per-object progress callbacks
- Cancellation checks between objects

---

### 5. Server Core (`src/Server/`)

Manages all SQLite metadata:
- `Database` — PDO connection, auto-schema init, seed admin user
- `AuthService` — session, Bearer token, API key validation
- `BucketManager` — bucket lifecycle (create/delete/stats)
- `ObjectManager` — object store/delete/URL generation with hash tracking

---

### 6. API Module (`src/API/`)

Thin REST layer. Routes map directly to controller methods. Auth middleware runs as a named gate (`['auth']`).

---

### 7. SDK Module (`src/SDK/`)

Provides a clean developer-facing API:

```php
use StoragePlatform\SDK\StorageClient;

$storage = StorageClient::disk('mycloud'); // or 's3', 'r2', 'b2', 'local', 'minio'

$storage->put('my-bucket', 'invoices/2026/abc.pdf', '/tmp/file.pdf');
$url = $storage->temporaryUrl('my-bucket', 'invoices/2026/abc.pdf', 3600);
$content = $storage->get('my-bucket', 'invoices/2026/abc.pdf');
```

All providers expose the exact same method signatures. Switch disks with a string change.

---

## Security

### Signed URLs for Private Buckets

When a bucket's visibility is set to `private`, files require a valid HMAC-SHA256 signature to access. The signature is computed as:

```
HMAC-SHA256(SIGNED_URL_SECRET, "{bucket}|{key}|{expires}")
```

**PHP example:**
```php
$secret = $_ENV['SIGNED_URL_SECRET'];
$expires = time() + 3600; // 1 hour
$signature = hash_hmac('sha256', "uploads|photos/file.jpg|{$expires}", $secret);
$url = "http://storage.localhost/uploads/photos/file.jpg?expires={$expires}&signature={$signature}";
```

**Python example:**
```python
import hmac, hashlib, time

secret = os.environ['SIGNED_URL_SECRET']
expires = int(time.time()) + 3600
signature = hmac.new(secret.encode(), f"uploads|photos/file.jpg|{expires}".encode(), hashlib.sha256).hexdigest()
url = f"http://storage.localhost/uploads/photos/file.jpg?expires={expires}&signature={signature}"
```

### Authentication Methods

The server supports multiple authentication methods for API access:

| Method | Usage |
|---|---|
| Session cookie | Admin dashboard (browser login) |
| `Authorization: Bearer {access_key}` | API clients |
| `Authorization: Basic base64(access_key:secret_key)` | SDK / curl |
| `?access_key=X&secret_key=Y` | Download links |
| AWS SigV4 | S3-compatible clients (AWS SDK, boto3, s3cmd) |
| `X-Access-Key` / `X-Secret-Key` headers | Custom integrations |

---

## Folder Structure

```
storage/
├── cli/
│   ├── worker.php            ← Background queue worker daemon
│   └── test_providers.php    ← Platform diagnostics tool
│
├── database/
│   ├── schema.sql            ← SQLite schema definition
│   └── database.sqlite       ← Auto-created on first run
│
├── public/                   ← Document root (point Apache/Nginx here)
│   ├── index.php             ← Front controller + S3-style route handling
│   ├── .htaccess             ← Apache mod_rewrite for SPA routing
│   ├── css/
│   │   └── app.css           ← Glassmorphic dark dashboard stylesheet
│   ├── js/
│   │   └── app.js            ← SPA controller (no framework, vanilla JS)
│   └── views/
│       ├── dashboard.php     ← Authenticated dashboard shell
│       └── login.php         ← Login page
│
├── src/
│   ├── API/
│   │   ├── Router.php
│   │   └── Controllers/
│   │       ├── AuthController.php
│   │       ├── BucketController.php
│   │       ├── CredentialController.php
│   │       ├── MetricsController.php
│   │       ├── MigrationController.php
│   │       ├── ObjectController.php        ← Stream + S3-style access handler
│   │       ├── ProviderController.php
│   │       └── ServerInfoController.php
│   │   └── S3/
│   │       ├── S3ApiController.php
│   │       ├── S3XmlResponse.php
│   │       └── SigV4Authenticator.php
│   ├── Providers/
│   │   ├── StorageProviderInterface.php
│   │   ├── ProviderFactory.php
│   │   ├── LocalProvider.php              ← Uses SIGNED_URL_SECRET
│   │   ├── MyCloudProvider.php            ← Uses SIGNED_URL_SECRET
│   │   ├── BaseS3Provider.php
│   │   ├── S3Provider.php
│   │   ├── R2Provider.php
│   │   ├── B2Provider.php
│   │   ├── MinIOProvider.php
│   │   ├── FTPDriver.php
│   │   └── MemoryDriver.php
│   ├── Queue/
│   │   ├── QueueInterface.php
│   │   ├── SQLiteQueue.php
│   │   └── Worker.php
│   ├── SDK/
│   │   ├── Storage.php                    ← S3-style URL generation
│   │   └── StorageClient.php
│   ├── Server/
│   │   ├── Database.php                   ← Auto-migrates provider URLs
│   │   ├── AuthService.php
│   │   ├── BucketManager.php
│   │   └── ObjectManager.php              ← S3-style URL generation
│   ├── StorageEngine/
│   │   └── HashedLocalEngine.php
│   └── Transfer/
│       ├── TransferEngine.php
│       └── TransferJob.php
│
├── storage/
│   ├── uploads/              ← Local provider physical files
│   └── mycloud/
│       └── data/             ← MyCloud hashed file layout
│
├── logs/                     ← Application logs
├── .env                      ← Environment configuration (APP_URL, SIGNED_URL_SECRET)
├── .env.example              ← Environment variable template
├── .gitignore
└── composer.json
```

---

## Quick Start

### Requirements
- PHP 8.1+
- Composer
- Apache (XAMPP) or `php -S` built-in server
- SQLite extension enabled (`php_pdo_sqlite`)

### Install

```bash
git clone https://github.com/bloggermohiuddin/storage.git
cd storage-platform
composer install
cp .env.example .env
```

### Run (built-in PHP server)

```bash
php -S localhost:8000 -t public/
```

Open `http://localhost:8000` — the database is initialized automatically on first load.

**Default credentials:** `admin` / `adminpassword`

> Change the password immediately after first login via the API Keys section.

### Run Background Worker

In a separate terminal:

```bash
php cli/worker.php --queue=migrations
```

This processes all background migration jobs dispatched from the dashboard.

### Diagnostics

```bash
php cli/test_providers.php
```

---

## Admin Dashboard

The dashboard is a single-page application (SPA) with six sections:

| Section | Description |
|---|---|
| **Dashboard** | Platform metrics, provider usage, queue status |
| **Buckets** | Create, list, delete buckets across any provider |
| **Object Browser** | Drag-and-drop upload, list, download, delete, copy |
| **Storage Providers** | Configure S3, R2, B2, MinIO, Local, MyCloud |
| **Migration Engine** | Create and monitor background transfer jobs |
| **API Keys & SDK** | Generate programmatic access credentials |

---

## Storage Providers

Configure additional providers from the Admin UI → **Storage Providers** tab, or via `POST /api/providers`.

| Driver | Description |
|---|---|
| `mycloud` | Built-in self-hosted engine (SQLite + hashed FS) |
| `local` | Raw local filesystem |
| `s3` | Amazon S3 |
| `r2` | Cloudflare R2 (auto-disables ACLs, forces `region=auto`) |
| `b2` | Backblaze B2 (auto-builds S3-compatible endpoint) |
| `minio` | Self-hosted MinIO (forces path-style endpoint) |

---

## URL Formats

Files are accessible via S3-style path-based URLs. The URL format depends on bucket visibility.

### Public Buckets

```
GET /{bucket}/{key}
```

**Example:**
```
GET /uploads/photos/2026/07/28/abc123.png
→ http://storage.localhost/uploads/photos/2026/07/28/abc123.png
```

No authentication required. Anyone with the URL can access the file.

### Private Buckets (Signed URLs)

```
GET /{bucket}/{key}?expires={timestamp}&signature={hmac_sha256}
```

**Example:**
```
GET /private/invoices/2026/07/28/invoice.pdf?expires=1753800000&signature=a1b2c3...
→ http://storage.localhost/private/invoices/2026/07/28/invoice.pdf?expires=1753800000&signature=a1b2c3...
```

The signature is an HMAC-SHA256 computed as:
```
HMAC-SHA256(SIGNED_URL_SECRET, "{bucket}|{key}|{expires}")
```

Signed URLs expire after the `expires` timestamp. The `SIGNED_URL_SECRET` in `.env` must match between the server and any URL-generating client.

### Legacy URL Formats

These formats still work but the S3-style format above is preferred:
- `/api/objects/stream?bucket={name}&key={key}` — query-parameter style
- `/object/{bucket}/{key}` — alias for S3-style

---

## Migration Engine

Migrate objects between **any two configured providers** — in any direction:

```
Local    → MyCloud
MyCloud  → R2
R2       → S3
B2       → MyCloud
S3       → MinIO
```

### How it works

1. From the dashboard, click **New Migration Job**
2. Select Source and Target provider
3. Set optional filter rules (prefix, overwrite, dry-run)
4. Click **Start Background Migration**
5. A job is pushed to the SQLite queue
6. The CLI worker picks it up and streams objects from source → target
7. Live progress is visible in the dashboard (auto-refreshes every 4s)

### Migration Rules

| Rule | Description |
|---|---|
| `prefix` | Only migrate keys starting with this prefix |
| `regex` | Only migrate keys matching this regex pattern |
| `extensions` | Whitelist of file extensions to transfer |
| `min_size` | Minimum file size in bytes |
| `max_size` | Maximum file size in bytes |
| `overwrite` | Overwrite existing files at destination |
| `dry_run` | Simulate the transfer without moving any data |

---

## Queue System

The `SQLiteQueue` stores jobs in the `queue_jobs` table with transactional reservation to prevent double-delivery across multiple workers.

### Job Lifecycle

```
pending → reserved (processing) → deleted (success)
                                → released with backoff (retry)
                                → deleted after max_attempts (failure)
```

Retry strategy: exponential backoff — `5s, 10s, 20s` before giving up.

### Run the Worker

```bash
# Default queue
php cli/worker.php

# Specific queue channel
php cli/worker.php --queue=migrations
```

The worker supports graceful shutdown via `SIGTERM`/`SIGINT` signals.

---

## REST API Reference

All endpoints require authentication (Session cookie, Bearer token, or Basic auth with API key pair).

### Authentication

```
POST /api/auth/login       { username, password }
POST /api/auth/logout
GET  /api/auth/me
GET  /api/auth/keys
POST /api/auth/keys        { name }
DELETE /api/auth/keys/{id}
```

### Buckets

```
GET    /api/buckets
POST   /api/buckets        { name, provider_id, visibility }
DELETE /api/buckets/{id}
```

### Objects

```
GET    /api/objects?bucket_id=&search=&prefix=
POST   /api/objects        multipart/form-data: bucket_id, file, [key], [prefix]
POST   /api/objects/delete { bucket_id, key }
POST   /api/objects/copy   { bucket_id, from_key, to_key }
GET    /api/objects/stream?bucket={name}&key={key}   (or ?bucket_id=&key=)
GET    /{bucket}/{key}                                  (S3-style direct access)
GET    /object/{bucket}/{key}                           (alias)
```

### Storage Providers

```
GET    /api/providers
POST   /api/providers      { name, driver, endpoint, region, access_key, secret_key, bucket }
POST   /api/providers/validate
DELETE /api/providers/{id}
```

### Migrations

```
GET  /api/migrations
POST /api/migrations       { source_provider_id, target_provider_id, rules: {} }
GET  /api/migrations/{id}/logs
POST /api/migrations/{id}/cancel
```

### Metrics

```
GET /api/metrics           → { summary, queue, providers, recent_logs }
```

---

## PHP SDK

```php
use StoragePlatform\SDK\Storage;

// Access any provider by driver name or display name
$cloud = Storage::driver('mycloud');
$r2    = Storage::driver('r2');
$s3    = Storage::driver('s3');

// All providers share the same interface
$cloud->put('my-bucket', 'docs/report.pdf', '/tmp/report.pdf');
$r2->put('my-bucket', 'docs/report.pdf', '/tmp/report.pdf');

// Get public URL (S3-style: /{bucket}/{key})
$url = $cloud->url('docs/report.pdf');
// → http://storage.localhost/my-bucket/docs/report.pdf

// Generate signed time-limited URL (for private buckets)
$url = $cloud->temporaryUrl('docs/report.pdf', 3600);
// → http://storage.localhost/my-bucket/docs/report.pdf?expires=...&signature=...

// List objects
$keys = $cloud->listObjects('my-bucket', 'docs/');

// Copy within same provider
$cloud->copy('my-bucket', 'docs/old.pdf', 'docs/new.pdf');

// Check health
$health = $cloud->health(); // ['status' => 'healthy', 'error' => null]
```

---

## CLI Tools

### `cli/worker.php` — Background Queue Worker

```bash
php cli/worker.php [--queue=migrations]
```

Runs indefinitely, processing queued migration jobs with exponential backoff retry. Gracefully shuts down on `SIGTERM`/`SIGINT`.

### `cli/test_providers.php` — Diagnostics

```bash
php cli/test_providers.php
```

Verifies:
1. SQLite database connection and schema
2. All configured provider health checks  
3. MyCloud write/read round-trip
4. SDK API verification

---

## Configuration

### Environment Variables

| Variable | Required | Description |
|---|---|---|
| `APP_URL` | Yes | Base URL for generating public object URLs (e.g. `http://storage.localhost`) |
| `SIGNED_URL_SECRET` | Yes | HMAC-SHA256 secret for signing private bucket URLs. Generate with `openssl rand -hex 32` |
| `STORAGE_DRIVER` | No | Default storage driver: `local`, `r2`, `s3`, `b2`, `minio` (default: `local`) |
| `STORAGE_LOGGING` | No | Enable SDK logging: `true` or `false` |

### Database Auto-Init

On first request, `Database::getConnection()`:
1. Creates `database/database.sqlite` if missing
2. Executes `database/schema.sql` to create all tables
3. Seeds the default `admin` user
4. Seeds the `Local Filesystem` provider with `APP_URL` as its base URL
5. Creates the default `uploads` bucket (public)

### Provider Config (from UI)

Provider credentials are stored in the `storage_providers` table. The `url` field in each provider's options JSON should match your `APP_URL` for correct public URL generation. The database auto-migration updates this when `APP_URL` changes.

---

## Roadmap

### Phase 2 — In Progress

- [x] S3-style `/{bucket}/{key}` URL routing for direct public/private object access
- [x] Configurable HMAC-signed URLs for private buckets (`SIGNED_URL_SECRET`)
- [x] Automatic provider URL migration on `APP_URL` change
- [ ] Versioning (keep multiple object versions per key)  
- [ ] Object lifecycle policies (auto-delete after N days)  
- [ ] CORS configuration per bucket  
- [ ] CDN integration (CloudFront, Cloudflare)  
- [ ] Webhook events on object create/delete  
- [ ] Object Lock / WORM compliance  
- [ ] JWT authentication  
- [ ] Role-based access control (RBAC)  

### Phase 3 — Future

- [ ] Multi-node replication  
- [ ] Redis / RabbitMQ queue drivers  
- [ ] Automatic provider health monitoring + alerts  
- [ ] Storage analytics and cost estimation  
- [ ] Extract each module into standalone Composer package  

---

*Built with PHP 8.1+ · SQLite · No Docker Required · SOLID Architecture*
