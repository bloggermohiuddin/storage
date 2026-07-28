-- SQLite database schema for Self-Hosted Object Storage Platform (R2 & S3 Compatible)

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    account_id TEXT UNIQUE NOT NULL DEFAULT 'local',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- R2 / S3 Access Keys (Credentials for SDK & S3 API)
CREATE TABLE IF NOT EXISTS access_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    account_id TEXT NOT NULL DEFAULT 'local',
    name TEXT NOT NULL,
    access_key TEXT UNIQUE NOT NULL,
    secret_key TEXT NOT NULL,
    default_bucket TEXT DEFAULT 'uploads',
    permissions TEXT DEFAULT 'rw', -- 'rw', 'ro', 'wo', 'admin'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- API Keys (legacy / HTTP bearer compatibility)
CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    access_key TEXT UNIQUE NOT NULL,
    secret_key TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Storage Providers config (Local, S3, R2, B2, MinIO, FTP, SFTP, etc.)
CREATE TABLE IF NOT EXISTS storage_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    driver TEXT NOT NULL, -- 'local', 'mycloud', 's3', 'r2', 'b2', 'minio', 'ftp', 'sftp', 'memory'
    endpoint TEXT,
    region TEXT DEFAULT 'us-east-1',
    access_key TEXT,
    secret_key TEXT,
    bucket TEXT,
    options TEXT, -- JSON config overrides
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Buckets (scoped under a specific provider)
CREATE TABLE IF NOT EXISTS buckets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id INTEGER NOT NULL,
    name TEXT UNIQUE NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private', -- 'public', 'private'
    versioning INTEGER DEFAULT 0, -- 0 = suspended, 1 = enabled
    quota_bytes INTEGER DEFAULT 0, -- 0 = unlimited
    quota_objects INTEGER DEFAULT 0, -- 0 = unlimited
    lifecycle_rules TEXT, -- JSON rules
    cors_rules TEXT, -- JSON rules
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(provider_id) REFERENCES storage_providers(id) ON DELETE CASCADE
);

-- Objects table (scoped under buckets)
CREATE TABLE IF NOT EXISTS objects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    size INTEGER NOT NULL,
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    hash_sha256 TEXT NOT NULL,
    hash_md5 TEXT,
    etag TEXT,
    storage_path TEXT,
    metadata TEXT, -- JSON custom metadata
    version_id TEXT,
    is_latest INTEGER DEFAULT 1,
    is_deleted INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
    UNIQUE(bucket_id, key, version_id)
);

-- Object Versions (for S3 versioning)
CREATE TABLE IF NOT EXISTS object_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id INTEGER NOT NULL,
    bucket_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    version_id TEXT NOT NULL,
    size INTEGER NOT NULL,
    storage_path TEXT,
    hash_sha256 TEXT,
    etag TEXT,
    is_delete_marker INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(object_id) REFERENCES objects(id) ON DELETE CASCADE,
    FOREIGN KEY(bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
);

-- Object Metadata (Key-Value)
CREATE TABLE IF NOT EXISTS object_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id INTEGER NOT NULL,
    meta_key TEXT NOT NULL,
    meta_value TEXT,
    FOREIGN KEY(object_id) REFERENCES objects(id) ON DELETE CASCADE
);

-- Object Tags
CREATE TABLE IF NOT EXISTS object_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id INTEGER NOT NULL,
    tag_key TEXT NOT NULL,
    tag_value TEXT,
    FOREIGN KEY(object_id) REFERENCES objects(id) ON DELETE CASCADE
);

-- S3 / R2 Multipart Uploads
CREATE TABLE IF NOT EXISTS multipart_uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    upload_id TEXT UNIQUE NOT NULL,
    bucket_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    mime_type TEXT DEFAULT 'application/octet-stream',
    metadata TEXT,
    status TEXT DEFAULT 'in_progress', -- 'in_progress', 'completed', 'aborted'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
);

-- Upload Parts for Multipart
CREATE TABLE IF NOT EXISTS upload_parts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    upload_id TEXT NOT NULL,
    part_number INTEGER NOT NULL,
    etag TEXT NOT NULL,
    size INTEGER NOT NULL,
    storage_path TEXT NOT NULL,
    hash_md5 TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(upload_id) REFERENCES multipart_uploads(upload_id) ON DELETE CASCADE,
    UNIQUE(upload_id, part_number)
);

-- Signed Temporary URLs
CREATE TABLE IF NOT EXISTS signed_urls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE NOT NULL,
    bucket_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Lifecycle Rules
CREATE TABLE IF NOT EXISTS lifecycle_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_id INTEGER NOT NULL,
    rule_name TEXT NOT NULL,
    prefix TEXT DEFAULT '',
    status TEXT DEFAULT 'enabled', -- 'enabled', 'disabled'
    expiration_days INTEGER DEFAULT 0,
    noncurrent_version_expiration_days INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
);

-- Storage Quotas
CREATE TABLE IF NOT EXISTS storage_quotas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_id INTEGER UNIQUE NOT NULL,
    max_bytes INTEGER DEFAULT 0,
    max_objects INTEGER DEFAULT 0,
    FOREIGN KEY(bucket_id) REFERENCES buckets(id) ON DELETE CASCADE
);

-- Background Migration Jobs
CREATE TABLE IF NOT EXISTS migration_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_provider_id INTEGER NOT NULL,
    target_provider_id INTEGER NOT NULL,
    rules TEXT, -- JSON filters: prefix, regex, extensions, size thresholds, overwrite flags
    status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'processing', 'completed', 'failed', 'paused', 'cancelled'
    total_objects INTEGER DEFAULT 0,
    processed_objects INTEGER DEFAULT 0,
    failed_objects INTEGER DEFAULT 0,
    total_bytes INTEGER DEFAULT 0,
    bytes_transferred INTEGER DEFAULT 0,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_provider_id) REFERENCES storage_providers(id),
    FOREIGN KEY(target_provider_id) REFERENCES storage_providers(id)
);

-- Migration logs
CREATE TABLE IF NOT EXISTS migration_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    object_key TEXT NOT NULL,
    status TEXT NOT NULL, -- 'success', 'failed', 'skipped'
    error_message TEXT,
    bytes INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(job_id) REFERENCES migration_jobs(id) ON DELETE CASCADE
);

-- SQLite Queue Jobs
CREATE TABLE IF NOT EXISTS queue_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    reserved_at INTEGER,
    available_at INTEGER,
    created_at INTEGER
);

-- Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    ip_address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- System Statistics & Metrics
CREATE TABLE IF NOT EXISTS statistics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    metric_key TEXT UNIQUE NOT NULL,
    metric_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Global System Settings
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

