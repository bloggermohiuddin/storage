<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Console — Object Storage Platform</title>
    <meta name="description" content="Self-hosted S3-compatible object storage admin console — manage buckets, objects, providers, migrations and R2 credentials.">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- SIDEBAR                                                  -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo">☁</div>
            <div>
                <div class="sidebar-title">MyCloud Storage</div>
                <div class="sidebar-tagline">Self-hosted · S3 Compatible</div>
            </div>
        </div>

        <div class="nav-section-label">Platform</div>
        <ul class="nav-list">
            <li class="nav-item active" id="nav-dashboard">
                <a href="#dashboard" onclick="switchTab('dashboard'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    Overview
                </a>
            </li>
            <li class="nav-item" id="nav-buckets">
                <a href="#buckets" onclick="switchTab('buckets'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    Buckets
                </a>
            </li>
            <li class="nav-item" id="nav-objects">
                <a href="#objects" onclick="switchTab('objects'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    Object Browser
                </a>
            </li>
        </ul>

        <div class="nav-section-label" style="margin-top:1rem;">Infrastructure</div>
        <ul class="nav-list">
            <li class="nav-item" id="nav-providers">
                <a href="#providers" onclick="switchTab('providers'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                    Storage Providers
                </a>
            </li>
            <li class="nav-item" id="nav-migrations">
                <a href="#migrations" onclick="switchTab('migrations'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    Migration Engine
                </a>
            </li>
        </ul>

        <div class="nav-section-label" style="margin-top:1rem;">Access</div>
        <ul class="nav-list">
            <li class="nav-item" id="nav-keys">
                <a href="#keys" onclick="switchTab('keys'); return false;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    R2 Credentials &amp; SDK
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar">A</div>
                <div class="sidebar-user-info">
                    <div class="sidebar-username">admin</div>
                    <a href="#" class="sidebar-logout" onclick="handleLogout(); return false;">Sign out</a>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MAIN CONTENT                                             -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <main class="main-content">

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 1 · OVERVIEW DASHBOARD                           -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-dashboard" class="tab-content">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">Platform Overview</h1>
                    <p class="page-subtitle">Real-time health and statistics for your self-hosted object storage</p>
                </div>
                <button class="btn btn-secondary btn-sm" onclick="loadDashboardMetrics()" id="refresh-btn">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refresh
                </button>
            </div>

            <!-- Metrics Grid -->
            <div class="metrics-grid">
                <div class="glass metric-card">
                    <div class="metric-icon blue">🗄️</div>
                    <div class="metric-label">Total Buckets</div>
                    <div class="metric-value" id="dash-buckets-count">—</div>
                </div>
                <div class="glass metric-card">
                    <div class="metric-icon cyan">📄</div>
                    <div class="metric-label">Objects Indexed</div>
                    <div class="metric-value" id="dash-objects-count">—</div>
                </div>
                <div class="glass metric-card">
                    <div class="metric-icon green">💾</div>
                    <div class="metric-label">Storage Used</div>
                    <div class="metric-value" id="dash-storage-size">—</div>
                </div>
                <div class="glass metric-card">
                    <div class="metric-icon purple">🔌</div>
                    <div class="metric-label">Active Providers</div>
                    <div class="metric-value" id="dash-providers-count">—</div>
                </div>
            </div>

            <!-- Queue Status -->
            <div class="queue-bar glass">
                <span class="queue-label">Background Queue</span>
                <div id="dash-queue-status" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                    <span class="badge badge-gray">Loading…</span>
                </div>
            </div>

            <!-- Provider Health Table -->
            <div class="glass" style="padding:1.5rem;">
                <div class="section-header">
                    <div class="section-title">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        Storage Provider Health
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="dash-health-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Driver</th>
                                <th>Objects</th>
                                <th>Total Size</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 2 · BUCKETS                                      -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-buckets" class="tab-content" style="display:none;">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">Buckets</h1>
                    <p class="page-subtitle">Manage storage buckets across all configured providers</p>
                </div>
                <button class="btn btn-primary" onclick="openCreateBucketModal()">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Create Bucket
                </button>
            </div>

            <div class="glass" style="padding:1.5rem;">
                <div class="table-wrap">
                    <table id="buckets-table">
                        <thead>
                            <tr>
                                <th>Bucket Name</th>
                                <th>Provider</th>
                                <th>Visibility</th>
                                <th>Objects</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 3 · OBJECT BROWSER                               -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-objects" class="tab-content" style="display:none;">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">Object Browser</h1>
                    <p class="page-subtitle">Browse, upload, download and manage files within buckets</p>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="glass" style="padding:1.1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
                <div style="flex:1;min-width:180px;">
                    <label class="form-label">Bucket</label>
                    <select id="obj-bucket-select" class="form-select" onchange="loadObjects()"></select>
                </div>
                <div style="flex:2;min-width:240px;">
                    <label class="form-label">Search Key Prefix</label>
                    <input type="text" id="obj-search-input" class="form-input" placeholder="images/avatars/…" onkeyup="debounceLoadObjects()">
                </div>
                <button class="btn btn-secondary btn-sm" onclick="loadObjects()" style="margin-bottom:0;">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Search
                </button>
            </div>

            <!-- Dropzone -->
            <div class="dropzone" id="upload-dropzone" onclick="triggerFileInput()" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                <div class="dropzone-icon">
                    <svg width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                </div>
                <div class="dropzone-title">Drop files here to upload</div>
                <div class="dropzone-sub">or click to select from your computer</div>
                <input type="file" id="file-input" style="display:none;" multiple onchange="handleFilesSelected(event)">
            </div>

            <!-- Upload Progress -->
            <div id="upload-progress-wrap" style="display:none;margin-top:1rem;">
                <div class="glass" style="padding:1rem 1.25rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <span id="upload-progress-label" style="font-size:0.85rem;font-weight:600;">Uploading…</span>
                        <span id="upload-progress-pct" style="font-size:0.8rem;color:var(--text-muted);">0%</span>
                    </div>
                    <div class="progress-bg"><div class="progress-fill" id="upload-progress-bar" style="width:0%"></div></div>
                </div>
            </div>

            <!-- Object Table -->
            <div class="glass" style="padding:1.5rem;margin-top:1.25rem;">
                <div class="table-wrap">
                    <table id="objects-table">
                        <thead>
                            <tr>
                                <th>Object Key</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>SHA-256</th>
                                <th>Modified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2.5rem;">Select a bucket to browse objects</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 4 · STORAGE PROVIDERS                            -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-providers" class="tab-content" style="display:none;">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">Storage Providers</h1>
                    <p class="page-subtitle">Configure S3, R2, B2, MinIO, or local filesystem backends</p>
                </div>
                <button class="btn btn-primary" onclick="openCreateProviderModal()">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Provider
                </button>
            </div>

            <div class="glass" style="padding:1.5rem;">
                <div class="table-wrap">
                    <table id="providers-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Driver</th>
                                <th>Endpoint / Region</th>
                                <th>Default Bucket</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 5 · MIGRATION ENGINE                             -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-migrations" class="tab-content" style="display:none;">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">Migration Engine</h1>
                    <p class="page-subtitle">Migrate objects between any two providers — background, resumable, audited</p>
                </div>
                <button class="btn btn-primary" onclick="openCreateMigrationModal()">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    New Migration Job
                </button>
            </div>

            <div class="glass" style="padding:1.5rem;">
                <div class="section-header">
                    <div class="section-title">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Active &amp; Completed Jobs
                    </div>
                    <button class="btn btn-secondary btn-xs" onclick="loadMigrations()">↻ Refresh</button>
                </div>
                <div class="table-wrap">
                    <table id="migrations-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Source</th>
                                <th>Target</th>
                                <th>Progress</th>
                                <th>Transferred</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem;">No migration jobs yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ──────────────────────────────────────────────────── -->
        <!-- TAB 6 · R2 CREDENTIALS & SDK                         -->
        <!-- ──────────────────────────────────────────────────── -->
        <section id="tab-keys" class="tab-content" style="display:none;">
            <div class="top-bar">
                <div>
                    <h1 class="page-title">R2 Credentials &amp; SDK</h1>
                    <p class="page-subtitle">Drop-in replacement credentials for Cloudflare R2 — zero application code changes required</p>
                </div>
                <button class="btn btn-primary" onclick="generateApiKey()">
                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Generate New Key
                </button>
            </div>

            <!-- R2 Credentials Inspector -->
            <div class="glass" style="padding:1.5rem;margin-bottom:1.5rem;">
                <div class="section-header">
                    <div class="section-title">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Cloudflare R2 Compatible Credentials
                    </div>
                    <button class="btn btn-secondary btn-xs" onclick="loadKeys()">↻ Reload</button>
                </div>

                <div class="alert alert-info" style="margin-bottom:1.25rem;">
                    <svg fill="none" viewBox="0 0 24 24" width="18" height="18" style="flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor"/></svg>
                    <span>These credentials are identical in format to Cloudflare R2. Replace your R2 keys with these to point your app at this local server instead — no code changes needed.</span>
                </div>

                <!-- Individual Credential Cards -->
                <div class="cred-grid" id="r2-credentials-box">
                    <div class="cred-item" style="grid-column:1/-1;justify-content:center;padding:2rem;color:var(--text-muted);">Loading credentials…</div>
                </div>

                <!-- Export buttons -->
                <div style="display:flex;gap:0.65rem;flex-wrap:wrap;margin-top:0.5rem;">
                    <button class="btn btn-secondary btn-sm" onclick="copyAllEnv()" id="copy-env-btn">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        Copy .env Block
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="downloadEnvFile()">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download .env
                    </button>
                </div>
            </div>

            <!-- Code Snippet Inspector -->
            <div class="glass" style="padding:1.5rem;margin-bottom:1.5rem;">
                <div class="section-header">
                    <div class="section-title">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                        SDK Integration Snippets
                    </div>
                </div>

                <div class="snippet-panel">
                    <div class="snippet-header">
                        <button class="snippet-tab active" onclick="switchSnippet('dotenv', this)">📄 .env File</button>
                        <button class="snippet-tab" onclick="switchSnippet('php', this)">🐘 PHP SDK</button>
                        <button class="snippet-tab" onclick="switchSnippet('aws_php', this)">⚡ AWS SDK (PHP)</button>
                        <button class="snippet-tab" onclick="switchSnippet('javascript', this)">🟨 JavaScript S3</button>
                        <button class="snippet-tab" onclick="switchSnippet('python', this)">🐍 Python boto3</button>
                        <button class="snippet-tab" onclick="switchSnippet('laravel', this)">❤️ Laravel</button>
                    </div>
                    <div class="snippet-body">
                        <div class="snippet-pane active" id="snippet-dotenv">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-dotenv', this)">Copy</button>
                            <pre class="code-block" id="code-dotenv">Loading…</pre>
                        </div>
                        <div class="snippet-pane" id="snippet-php">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-php', this)">Copy</button>
                            <pre class="code-block" id="code-php">Loading…</pre>
                        </div>
                        <div class="snippet-pane" id="snippet-aws_php">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-aws_php', this)">Copy</button>
                            <pre class="code-block" id="code-aws_php">Loading…</pre>
                        </div>
                        <div class="snippet-pane" id="snippet-javascript">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-javascript', this)">Copy</button>
                            <pre class="code-block" id="code-javascript">Loading…</pre>
                        </div>
                        <div class="snippet-pane" id="snippet-python">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-python', this)">Copy</button>
                            <pre class="code-block" id="code-python">Loading…</pre>
                        </div>
                        <div class="snippet-pane" id="snippet-laravel">
                            <button class="snippet-copy-all" onclick="copySnippet('snippet-laravel', this)">Copy</button>
                            <pre class="code-block" id="code-laravel">Loading…</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programmatic API Keys Table -->
            <div class="glass" style="padding:1.5rem;">
                <div class="section-header">
                    <div class="section-title">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        Programmatic Access Keys
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="generateApiKey()">+ Generate</button>
                </div>
                <div class="table-wrap">
                    <table id="keys-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Access Key ID</th>
                                <th>Default Bucket</th>
                                <th>Permissions</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">No keys yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main><!-- /main-content -->

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MODAL: CREATE BUCKET                                     -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modal-create-bucket" style="display:none;">
        <div class="glass modal-card">
            <div class="modal-header">
                <h2 class="modal-title">Create Bucket</h2>
                <button class="modal-close" onclick="closeModals()">✕</button>
            </div>
            <form onsubmit="handleCreateBucket(event)">
                <div class="form-group">
                    <label class="form-label">Bucket Name</label>
                    <input type="text" id="mb-name" class="form-input" placeholder="my-assets" pattern="[a-z0-9\-]+" title="Lowercase letters, numbers, hyphens" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Storage Provider</label>
                    <select id="mb-provider" class="form-select" required></select>
                </div>
                <div class="form-group">
                    <label class="form-label">Visibility</label>
                    <select id="mb-visibility" class="form-select">
                        <option value="private">🔒 Private (Requires Signed URL)</option>
                        <option value="public">🌐 Public Read</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Bucket</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MODAL: ADD STORAGE PROVIDER                              -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modal-create-provider" style="display:none;">
        <div class="glass modal-card" style="max-width:600px;">
            <div class="modal-header">
                <h2 class="modal-title">Add Storage Provider</h2>
                <button class="modal-close" onclick="closeModals()">✕</button>
            </div>
            <form onsubmit="handleCreateProvider(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Display Name</label>
                        <input type="text" id="mp-name" class="form-input" placeholder="Cloudflare R2 Main" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Driver</label>
                        <select id="mp-driver" class="form-select" onchange="toggleProviderFields()" required>
                            <option value="r2">☁️ Cloudflare R2</option>
                            <option value="s3">🟠 Amazon S3</option>
                            <option value="b2">🔵 Backblaze B2</option>
                            <option value="minio">🟢 MinIO</option>
                            <option value="local">💻 Local Filesystem</option>
                            <option value="mycloud">🏠 MyCloud (Hashed Engine)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="group-endpoint">
                    <label class="form-label">Endpoint URL</label>
                    <input type="text" id="mp-endpoint" class="form-input mono" placeholder="https://<account_id>.r2.cloudflarestorage.com">
                </div>
                <div class="form-row">
                    <div class="form-group" id="group-access-key">
                        <label class="form-label">Access Key ID</label>
                        <input type="text" id="mp-access-key" class="form-input mono" autocomplete="off">
                    </div>
                    <div class="form-group" id="group-secret-key">
                        <label class="form-label">Secret Access Key</label>
                        <input type="password" id="mp-secret-key" class="form-input mono" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="group-region">
                        <label class="form-label">Region</label>
                        <input type="text" id="mp-region" class="form-input" placeholder="auto">
                    </div>
                    <div class="form-group" id="group-bucket">
                        <label class="form-label">Default Bucket</label>
                        <input type="text" id="mp-bucket" class="form-input" placeholder="my-bucket">
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.5rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="validateCurrentProviderConfig()">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Test Connection
                    </button>
                    <div style="display:flex;gap:0.65rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModals()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Provider</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MODAL: NEW MIGRATION JOB                                 -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modal-create-migration" style="display:none;">
        <div class="glass modal-card" style="max-width:580px;">
            <div class="modal-header">
                <h2 class="modal-title">New Migration Job</h2>
                <button class="modal-close" onclick="closeModals()">✕</button>
            </div>
            <form onsubmit="handleCreateMigration(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Source Provider (From)</label>
                        <select id="mm-source" class="form-select" required></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Provider (To)</label>
                        <select id="mm-target" class="form-select" required></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Key Prefix Filter <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                    <input type="text" id="mm-prefix" class="form-input" placeholder="images/ or patients/2024/">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Overwrite Existing?</label>
                        <select id="mm-overwrite" class="form-select">
                            <option value="1">Yes — overwrite</option>
                            <option value="0">No — skip existing</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode</label>
                        <select id="mm-dryrun" class="form-select">
                            <option value="0">Live (actual transfer)</option>
                            <option value="1">Dry Run (simulate only)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                        Start Migration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MODAL: KEY GENERATED SUCCESS                             -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modal-key-success" style="display:none;">
        <div class="glass modal-card" style="max-width:560px;">
            <div class="modal-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span class="badge badge-green" style="font-size:0.85rem;padding:0.35rem 0.8rem;"><span class="dot"></span> Key Generated</span>
                    <h2 class="modal-title" style="margin:0;">Save Your Secret Key</h2>
                </div>
                <button class="modal-close" onclick="closeModals()">✕</button>
            </div>

            <div class="alert alert-warn" style="margin-bottom:1.25rem;">
                <svg fill="none" viewBox="0 0 24 24" width="18" height="18" style="flex-shrink:0"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor"/></svg>
                <span>This is the <strong>only time</strong> the secret key is shown. Copy it now — it cannot be recovered.</span>
            </div>

            <div class="form-group">
                <label class="form-label">Access Key ID</label>
                <div class="input-row">
                    <input type="text" id="created-access-key" class="form-input mono" readonly>
                    <button class="btn btn-secondary" onclick="copyToClipboard('created-access-key', this)" style="flex-shrink:0;">Copy</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Secret Access Key</label>
                <div class="input-row">
                    <input type="text" id="created-secret-key" class="form-input mono" readonly>
                    <button class="btn btn-secondary" onclick="copyToClipboard('created-secret-key', this)" style="flex-shrink:0;">Copy</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModals()">Done — I've saved my key</button>
            </div>
        </div>
    </div>

    <script src="/js/app.js"></script>
</body>
</html>
