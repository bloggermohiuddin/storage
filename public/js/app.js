/* ============================================================
   Object Storage Platform — Admin SPA Controller
   Version 2.0 — Full hash routing, rich credential inspector,
   multi-tab snippets, multi-file upload, polished UX
   ============================================================ */

'use strict';

let currentTab   = 'dashboard';
let debounceTimer = null;
let _credCache   = null; // cached last loaded credentials

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '').trim() || 'dashboard';
    switchTab(hash);

    window.addEventListener('hashchange', () => {
        const h = window.location.hash.replace('#', '').trim() || 'dashboard';
        switchTab(h);
    });

    // Auto-refresh polling
    setInterval(() => {
        if (currentTab === 'migrations') loadMigrations();
        else if (currentTab === 'dashboard') loadDashboardMetrics();
    }, 5000);
});

// ── Tab Router ────────────────────────────────────────────────
function switchTab(tab) {
    const validTabs = ['dashboard','buckets','objects','providers','migrations','keys'];
    if (!validTabs.includes(tab)) tab = 'dashboard';

    currentTab = tab;
    history.replaceState(null, '', '#' + tab);

    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');

    const navEl = document.getElementById('nav-' + tab);
    const tabEl = document.getElementById('tab-' + tab);
    if (navEl) navEl.classList.add('active');
    if (tabEl) tabEl.style.display = 'block';

    const loaders = {
        dashboard:  loadDashboardMetrics,
        buckets:    loadBuckets,
        objects:    loadObjectBrowserInit,
        providers:  loadProviders,
        migrations: loadMigrations,
        keys:       loadKeys,
    };
    if (loaders[tab]) loaders[tab]();
}

// ── Snippet Tab Switcher ──────────────────────────────────────
function switchSnippet(name, btn) {
    document.querySelectorAll('.snippet-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.snippet-tab').forEach(b => b.classList.remove('active'));
    const pane = document.getElementById('snippet-' + name);
    if (pane) pane.classList.add('active');
    if (btn)  btn.classList.add('active');
}

function copySnippet(paneId, btn) {
    const pane = document.getElementById(paneId);
    const pre  = pane ? pane.querySelector('pre') : null;
    if (!pre) return;
    navigator.clipboard.writeText(pre.innerText.trim()).then(() => {
        btn.textContent = '✓ Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2200);
    });
}

// ─────────────────────────────────────────────────────────────
// TAB 1 · DASHBOARD
// ─────────────────────────────────────────────────────────────
async function loadDashboardMetrics() {
    try {
        const res  = await fetch('/api/metrics');
        if (!res.ok) return;
        const data = await res.json();

        const s = data.summary || {};
        setText('dash-buckets-count',   (s.buckets   || 0).toLocaleString());
        setText('dash-objects-count',   (s.objects   || 0).toLocaleString());
        setText('dash-storage-size',    formatBytes(s.total_bytes || 0));
        setText('dash-providers-count', (s.providers || 0).toLocaleString());

        // Queue pills
        const q = data.queue || {};
        const qEl = document.getElementById('dash-queue-status');
        if (qEl) {
            qEl.innerHTML = [
                q.pending_jobs  ? `<span class="badge badge-amber"><span class="dot"></span>${q.pending_jobs} Pending</span>` : '',
                q.processing    ? `<span class="badge badge-blue">${q.processing} Processing</span>` : '',
                q.completed     ? `<span class="badge badge-green">${q.completed} Completed</span>` : '',
                q.failed        ? `<span class="badge badge-rose">${q.failed} Failed</span>` : '',
            ].filter(Boolean).join('') || '<span class="badge badge-gray">Idle — no queued jobs</span>';
        }

        // Provider health table
        const providers = data.providers || [];
        const tbody = document.querySelector('#dash-health-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!providers.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem;">No providers configured yet</td></tr>';
            return;
        }
        providers.forEach(p => {
            const isOk = p.health_status === 'healthy';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${escHtml(p.name)}</strong></td>
                <td><span class="badge badge-blue">${escHtml((p.driver||'').toUpperCase())}</span></td>
                <td>${parseInt(p.object_count||0).toLocaleString()}</td>
                <td>${formatBytes(parseInt(p.total_bytes||0))}</td>
                <td><span class="badge ${isOk ? 'badge-green' : 'badge-rose'}"><span class="dot"></span>${isOk ? 'ONLINE' : 'OFFLINE'}</span></td>
            `;
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error('[Dashboard]', err);
    }
}

// ─────────────────────────────────────────────────────────────
// TAB 2 · BUCKETS
// ─────────────────────────────────────────────────────────────
async function loadBuckets() {
    try {
        const res  = await fetch('/api/buckets');
        const data = await res.json();
        const buckets = data.buckets || [];
        const tbody = document.querySelector('#buckets-table tbody');
        tbody.innerHTML = '';

        if (!buckets.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2.5rem;">No buckets yet — create your first bucket to get started</td></tr>';
            return;
        }

        buckets.forEach(b => {
            const vis = b.visibility === 'public'
                ? '<span class="badge badge-amber">PUBLIC</span>'
                : '<span class="badge badge-green">PRIVATE</span>';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong style="color:var(--text-primary);">${escHtml(b.name)}</strong></td>
                <td>${escHtml(b.provider_name)} <span class="badge badge-blue">${escHtml((b.provider_driver||'').toUpperCase())}</span></td>
                <td>${vis}</td>
                <td>${parseInt(b.object_count||0).toLocaleString()}</td>
                <td>${formatBytes(parseInt(b.total_size||0))}</td>
                <td style="color:var(--text-muted);font-size:0.8rem;">${escHtml(b.created_at)}</td>
                <td>
                    <div style="display:flex;gap:0.4rem;">
                        <button class="btn btn-secondary btn-xs" onclick="browseBucketObjects('${b.id}')">Browse</button>
                        <button class="btn btn-danger btn-xs" onclick="deleteBucket('${b.id}','${escHtml(b.name)}')">Delete</button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (err) {
        console.error('[Buckets]', err);
    }
}

function openCreateBucketModal() {
    fetch('/api/providers').then(r => r.json()).then(data => {
        const sel = document.getElementById('mb-provider');
        sel.innerHTML = '';
        (data.providers || []).forEach(p => {
            const o = document.createElement('option');
            o.value = p.id;
            o.textContent = `${p.name} (${p.driver.toUpperCase()})`;
            sel.appendChild(o);
        });
        document.getElementById('modal-create-bucket').style.display = 'flex';
    });
}

async function handleCreateBucket(e) {
    e.preventDefault();
    const body = {
        name:        document.getElementById('mb-name').value,
        provider_id: document.getElementById('mb-provider').value,
        visibility:  document.getElementById('mb-visibility').value,
    };
    const res  = await fetch('/api/buckets', { method:'POST', headers:jsonHeaders(), body:JSON.stringify(body) });
    const data = await res.json();
    if (res.ok && data.success) { closeModals(); loadBuckets(); }
    else alert(data.error || 'Failed to create bucket.');
}

async function deleteBucket(id, name) {
    if (!confirm(`Delete bucket "${name}"?\n\nAll objects inside will be permanently removed.`)) return;
    const res = await fetch('/api/buckets/' + id, { method:'DELETE' });
    if (res.ok) loadBuckets();
}

// ─────────────────────────────────────────────────────────────
// TAB 3 · OBJECT BROWSER
// ─────────────────────────────────────────────────────────────
async function loadObjectBrowserInit() {
    const res  = await fetch('/api/buckets');
    const data = await res.json();
    const buckets = data.buckets || [];
    const sel = document.getElementById('obj-bucket-select');
    sel.innerHTML = '';
    buckets.forEach(b => {
        const o = document.createElement('option');
        o.value = b.id;
        o.textContent = `${b.name} (${b.provider_name})`;
        sel.appendChild(o);
    });
    if (buckets.length) loadObjects();
}

function browseBucketObjects(bucketId) {
    switchTab('objects');
    setTimeout(() => {
        document.getElementById('obj-bucket-select').value = bucketId;
        loadObjects();
    }, 80);
}

function debounceLoadObjects() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadObjects, 300);
}

async function loadObjects() {
    const bucketId = document.getElementById('obj-bucket-select').value;
    const search   = document.getElementById('obj-search-input').value;
    if (!bucketId) return;

    const res  = await fetch(`/api/objects?bucket_id=${bucketId}&search=${encodeURIComponent(search)}`);
    const data = await res.json();
    const objects = data.objects || [];

    const tbody = document.querySelector('#objects-table tbody');
    tbody.innerHTML = '';

    if (!objects.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2.5rem;">No objects found in this bucket</td></tr>';
        return;
    }

    objects.forEach(obj => {
        const hash = (obj.hash_sha256 || '').substring(0, 12);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong style="color:var(--text-primary);font-size:0.875rem;">${escHtml(obj.key)}</strong></td>
            <td style="white-space:nowrap;">${formatBytes(parseInt(obj.size||0))}</td>
            <td><span class="badge badge-blue" style="font-size:0.68rem;">${escHtml(obj.mime_type||'—')}</span></td>
            <td style="font-family:var(--font-mono);font-size:0.75rem;color:var(--text-muted);">${hash}…</td>
            <td style="color:var(--text-muted);font-size:0.8rem;">${escHtml(obj.created_at)}</td>
            <td>
                <div style="display:flex;gap:0.4rem;">
                    <a href="${obj.url}" target="_blank" class="btn btn-secondary btn-xs">View</a>
                    <button class="btn btn-danger btn-xs" onclick="deleteObject('${bucketId}','${escHtml(obj.key)}')">Delete</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// Upload
function triggerFileInput() { document.getElementById('file-input').click(); }

function handleDragOver(e) {
    e.preventDefault();
    document.getElementById('upload-dropzone').classList.add('dragover');
}
function handleDragLeave(e) {
    e.preventDefault();
    document.getElementById('upload-dropzone').classList.remove('dragover');
}
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('upload-dropzone').classList.remove('dragover');
    const files = [...e.dataTransfer.files];
    if (files.length) uploadFiles(files);
}
function handleFilesSelected(e) {
    const files = [...e.target.files];
    if (files.length) uploadFiles(files);
}

async function uploadFiles(files) {
    const bucketId = document.getElementById('obj-bucket-select').value;
    if (!bucketId) { alert('Select a bucket first.'); return; }

    const wrap  = document.getElementById('upload-progress-wrap');
    const label = document.getElementById('upload-progress-label');
    const pct   = document.getElementById('upload-progress-pct');
    const bar   = document.getElementById('upload-progress-bar');

    wrap.style.display = 'block';

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        label.textContent = `Uploading ${i+1}/${files.length}: ${file.name}`;
        const progress = Math.round(((i+1) / files.length) * 100);
        pct.textContent = progress + '%';
        bar.style.width = progress + '%';

        const form = new FormData();
        form.append('bucket_id', bucketId);
        form.append('file', file);
        await fetch('/api/objects', { method:'POST', body:form });
    }

    setTimeout(() => {
        wrap.style.display = 'none';
        bar.style.width = '0%';
        loadObjects();
    }, 800);
}

async function deleteObject(bucketId, key) {
    if (!confirm(`Delete "${key}"?\nThis cannot be undone.`)) return;
    const res = await fetch('/api/objects/delete', {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ bucket_id: bucketId, key }),
    });
    if (res.ok) loadObjects();
}

// ─────────────────────────────────────────────────────────────
// TAB 4 · STORAGE PROVIDERS
// ─────────────────────────────────────────────────────────────
async function loadProviders() {
    const res  = await fetch('/api/providers');
    const data = await res.json();
    const providers = data.providers || [];
    const tbody = document.querySelector('#providers-table tbody');
    tbody.innerHTML = '';

    if (!providers.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2.5rem;">No providers configured — add one to get started</td></tr>';
        return;
    }

    providers.forEach(p => {
        const isOk  = p.health_status === 'healthy';
        const badge = isOk
            ? '<span class="badge badge-green"><span class="dot"></span>Online</span>'
            : '<span class="badge badge-rose">Offline</span>';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong style="color:var(--text-primary);">${escHtml(p.name)}</strong></td>
            <td><span class="badge badge-blue">${escHtml((p.driver||'').toUpperCase())}</span></td>
            <td style="font-family:var(--font-mono);font-size:0.8rem;color:var(--text-muted);">${escHtml(p.endpoint || p.region || '(Local)')}</td>
            <td style="font-family:var(--font-mono);font-size:0.8rem;">${escHtml(p.bucket || '—')}</td>
            <td>${badge}</td>
            <td><button class="btn btn-danger btn-xs" onclick="deleteProvider('${p.id}')">Remove</button></td>
        `;
        tbody.appendChild(tr);
    });
}

function openCreateProviderModal() {
    document.getElementById('modal-create-provider').style.display = 'flex';
    toggleProviderFields();
}

function toggleProviderFields() {
    const driver = document.getElementById('mp-driver').value;
    const isLocal = driver === 'local' || driver === 'mycloud';
    ['group-endpoint','group-access-key','group-secret-key','group-region','group-bucket'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = isLocal ? 'none' : '';
    });
    if (isLocal) {
        document.getElementById('group-bucket').style.display = '';
    }
}

async function validateCurrentProviderConfig() {
    const body = {
        driver:   document.getElementById('mp-driver').value,
        endpoint: document.getElementById('mp-endpoint').value,
        region:   document.getElementById('mp-region').value,
        key:      document.getElementById('mp-access-key').value,
        secret:   document.getElementById('mp-secret-key').value,
        bucket:   document.getElementById('mp-bucket').value,
    };
    const res  = await fetch('/api/providers/validate', { method:'POST', headers:jsonHeaders(), body:JSON.stringify(body) });
    const data = await res.json();
    if (res.ok && data.success) alert('✅ Connection validated! Provider credentials are working correctly.');
    else alert('❌ Validation failed: ' + (data.error || 'Check your credentials.'));
}

async function handleCreateProvider(e) {
    e.preventDefault();
    const body = {
        name:       document.getElementById('mp-name').value,
        driver:     document.getElementById('mp-driver').value,
        endpoint:   document.getElementById('mp-endpoint').value,
        region:     document.getElementById('mp-region').value,
        access_key: document.getElementById('mp-access-key').value,
        secret_key: document.getElementById('mp-secret-key').value,
        bucket:     document.getElementById('mp-bucket').value,
    };
    const res = await fetch('/api/providers', { method:'POST', headers:jsonHeaders(), body:JSON.stringify(body) });
    if (res.ok) { closeModals(); loadProviders(); }
    else { const d = await res.json(); alert(d.error || 'Failed to save provider.'); }
}

async function deleteProvider(id) {
    if (!confirm('Remove this provider configuration?')) return;
    const res = await fetch('/api/providers/' + id, { method:'DELETE' });
    if (res.ok) loadProviders();
    else { const d = await res.json(); alert(d.error || 'Failed to remove.'); }
}

// ─────────────────────────────────────────────────────────────
// TAB 5 · MIGRATIONS
// ─────────────────────────────────────────────────────────────
async function loadMigrations() {
    const res  = await fetch('/api/migrations');
    const data = await res.json();
    const migrations = data.migrations || [];
    const tbody = document.querySelector('#migrations-table tbody');
    tbody.innerHTML = '';

    if (!migrations.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2.5rem;">No migration jobs yet</td></tr>';
        return;
    }

    migrations.forEach(m => {
        const badgeMap = {
            pending:    'badge-amber',
            processing: 'badge-blue',
            completed:  'badge-green',
            failed:     'badge-rose',
            cancelled:  'badge-gray',
        };
        const bc = badgeMap[m.status] || 'badge-gray';
        const pct = m.progress_percent || 0;
        const canAbort = m.status === 'pending' || m.status === 'processing';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:var(--text-muted);font-size:0.8rem;">#${m.id}</td>
            <td><strong>${escHtml(m.source_provider_name)}</strong></td>
            <td><strong>${escHtml(m.target_provider_name)}</strong></td>
            <td style="min-width:160px;">
                <div style="font-size:0.78rem;font-weight:600;margin-bottom:0.35rem;">${m.processed_objects||0} / ${m.total_objects||0} objects (${pct}%)</div>
                <div class="progress-bg"><div class="progress-fill" style="width:${pct}%"></div></div>
            </td>
            <td style="white-space:nowrap;">${formatBytes(parseInt(m.bytes_transferred||0))}</td>
            <td><span class="badge ${bc}">${escHtml((m.status||'').toUpperCase())}</span></td>
            <td>${canAbort
                ? `<button class="btn btn-danger btn-xs" onclick="cancelMigration('${m.id}')">Abort</button>`
                : `<span style="color:var(--text-muted);font-size:0.78rem;">Done</span>`}
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function openCreateMigrationModal() {
    fetch('/api/providers').then(r => r.json()).then(data => {
        const src = document.getElementById('mm-source');
        const tgt = document.getElementById('mm-target');
        src.innerHTML = ''; tgt.innerHTML = '';
        (data.providers || []).forEach(p => {
            const html = `<option value="${p.id}">${escHtml(p.name)} (${p.driver.toUpperCase()})</option>`;
            src.insertAdjacentHTML('beforeend', html);
            tgt.insertAdjacentHTML('beforeend', html);
        });
        document.getElementById('modal-create-migration').style.display = 'flex';
    });
}

async function handleCreateMigration(e) {
    e.preventDefault();
    const body = {
        source_provider_id: document.getElementById('mm-source').value,
        target_provider_id: document.getElementById('mm-target').value,
        rules: {
            prefix:    document.getElementById('mm-prefix').value,
            overwrite: document.getElementById('mm-overwrite').value === '1',
            dry_run:   document.getElementById('mm-dryrun').value === '1',
        },
    };
    const res = await fetch('/api/migrations', { method:'POST', headers:jsonHeaders(), body:JSON.stringify(body) });
    if (res.ok) { closeModals(); loadMigrations(); }
    else { const d = await res.json(); alert(d.error || 'Failed to dispatch migration.'); }
}

async function cancelMigration(id) {
    if (!confirm('Abort this active migration? Progress already made will be preserved.')) return;
    const res = await fetch(`/api/migrations/${id}/cancel`, { method:'POST' });
    if (res.ok) loadMigrations();
}

// ─────────────────────────────────────────────────────────────
// TAB 6 · R2 CREDENTIALS & SDK
// ─────────────────────────────────────────────────────────────
async function loadKeys() {
    try {
        // Load access keys table
        const keysRes  = await fetch('/api/auth/keys');
        const keysData = await keysRes.json();
        renderKeysTable(keysData.keys || []);

        // Load R2 credentials + snippets
        const credRes  = await fetch('/api/credentials');
        if (!credRes.ok) return;
        const credData = await credRes.json();
        _credCache = credData;

        renderCredentials(credData.credentials || {});
        renderSnippets(credData.credentials || {});
    } catch (err) {
        console.error('[Keys]', err);
    }
}

function renderCredentials(creds) {
    const container = document.getElementById('r2-credentials-box');
    if (!container) return;

    const fields = [
        { key:'ACCOUNT_ID',     label:'Account ID',     cls:'',       icon:'🪪' },
        { key:'ACCESS_KEY',     label:'Access Key ID',  cls:'',       icon:'🔑' },
        { key:'SECRET_KEY',     label:'Secret Key',     cls:'secret', icon:'🔒' },
        { key:'DEFAULT_BUCKET', label:'Default Bucket', cls:'bucket', icon:'🗄️' },
        { key:'ENDPOINT',       label:'Endpoint URL',   cls:'url',    icon:'🌐' },
        { key:'PUBLIC_URL',     label:'Public URL',     cls:'url',    icon:'🔗' },
    ];

    container.innerHTML = '';
    fields.forEach(f => {
        const val = creds[f.key] || '—';
        const safeId = 'cred-' + f.key.toLowerCase();
        const div = document.createElement('div');
        div.className = 'cred-item';
        div.innerHTML = `
            <div class="cred-body">
                <div class="cred-key">${f.icon} ${escHtml(f.label)}</div>
                <input type="text" id="${safeId}" class="cred-val ${f.cls}"
                    value="${escHtml(val)}" readonly
                    style="background:none;border:none;padding:0;width:100%;color:inherit;font-family:var(--font-mono);font-size:0.82rem;cursor:text;outline:none;">
            </div>
            <button class="cred-copy-btn" onclick="copyCred('${safeId}', this)" title="Copy ${f.label}">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </button>
        `;
        container.appendChild(div);
    });
}

function copyCred(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>';
        }, 2000);
    });
}

function renderSnippets(c) {
    const ak = c.ACCESS_KEY     || 'local_access_key';
    const sk = c.SECRET_KEY     || 'local_secret_key';
    const ep = c.ENDPOINT       || 'http://localhost:8080';
    const bk = c.DEFAULT_BUCKET || 'uploads';
    const pu = c.PUBLIC_URL     || ep + '/' + bk;
    const id = c.ACCOUNT_ID     || 'local';

    setText('code-dotenv', [
        `# Object Storage — Local Development`,
        `# Paste this into your .env file`,
        ``,
        `STORAGE_ACCOUNT_ID=${id}`,
        `STORAGE_ACCESS_KEY=${ak}`,
        `STORAGE_SECRET_KEY=${sk}`,
        `STORAGE_BUCKET=${bk}`,
        `STORAGE_ENDPOINT=${ep}`,
        `STORAGE_PUBLIC_URL=${pu}`,
    ].join('\n'));

    setText('code-php', [
        `<?php`,
        `use StoragePlatform\\SDK\\Storage;`,
        ``,
        `// Connect to your self-hosted MyCloud storage`,
        `$storage = Storage::driver('mycloud')  // or 'r2', 's3', 'b2'`,
        `    ->bucket('${bk}');`,
        ``,
        `// 1. Upload a file`,
        `$key = $storage->put('avatars/user-42.jpg', '/tmp/photo.jpg');`,
        ``,
        `// 2. Get a permanent public URL`,
        `$url = $storage->url('avatars/user-42.jpg');`,
        ``,
        `// 3. Generate a time-limited signed URL (private)`,
        `$signedUrl = $storage->temporaryUrl('avatars/user-42.jpg', 3600);`,
        ``,
        `// 4. List objects with prefix`,
        `$files = $storage->list('avatars/');`,
        ``,
        `// 5. Delete`,
        `$storage->delete('avatars/user-42.jpg');`,
    ].join('\n'));

    setText('code-aws_php', [
        `<?php`,
        `use Aws\\S3\\S3Client;`,
        ``,
        `$s3 = new S3Client([`,
        `    'version'                 => 'latest',`,
        `    'region'                  => 'us-east-1',`,
        `    'endpoint'                => '${ep}',`,
        `    'use_path_style_endpoint' => true,`,
        `    'credentials' => [`,
        `        'key'    => '${ak}',`,
        `        'secret' => '${sk}',`,
        `    ],`,
        `]);`,
        ``,
        `// Upload`,
        `$s3->putObject([`,
        `    'Bucket'     => '${bk}',`,
        `    'Key'        => 'uploads/photo.jpg',`,
        `    'SourceFile' => '/tmp/photo.jpg',`,
        `]);`,
        ``,
        `// Presigned URL (1 hour)`,
        `$cmd = $s3->getCommand('GetObject', ['Bucket' => '${bk}', 'Key' => 'uploads/photo.jpg']);`,
        `$url = (string) $s3->createPresignedRequest($cmd, '+1 hour')->getUri();`,
    ].join('\n'));

    setText('code-javascript', [
        `import { S3Client, PutObjectCommand, GetObjectCommand } from "@aws-sdk/client-s3";`,
        `import { getSignedUrl } from "@aws-sdk/s3-request-presigner";`,
        ``,
        `const s3 = new S3Client({`,
        `  region: "us-east-1",`,
        `  endpoint: "${ep}",`,
        `  forcePathStyle: true,`,
        `  credentials: {`,
        `    accessKeyId:     "${ak}",`,
        `    secretAccessKey: "${sk}",`,
        `  },`,
        `});`,
        ``,
        `// Upload`,
        `await s3.send(new PutObjectCommand({`,
        `  Bucket: "${bk}",`,
        `  Key:    "uploads/photo.jpg",`,
        `  Body:   fileBuffer,`,
        `}));`,
        ``,
        `// Presigned download URL (15 min)`,
        `const url = await getSignedUrl(s3, new GetObjectCommand({`,
        `  Bucket: "${bk}",`,
        `  Key:    "uploads/photo.jpg",`,
        `}), { expiresIn: 900 });`,
    ].join('\n'));

    setText('code-python', [
        `import boto3`,
        ``,
        `s3 = boto3.client(`,
        `    "s3",`,
        `    region_name          = "us-east-1",`,
        `    endpoint_url         = "${ep}",`,
        `    aws_access_key_id    = "${ak}",`,
        `    aws_secret_access_key= "${sk}",`,
        `)`,
        ``,
        `# Upload`,
        `s3.upload_file("/tmp/photo.jpg", "${bk}", "uploads/photo.jpg")`,
        ``,
        `# Presigned URL (1 hour)`,
        `url = s3.generate_presigned_url(`,
        `    "get_object",`,
        `    Params     = {"Bucket": "${bk}", "Key": "uploads/photo.jpg"},`,
        `    ExpiresIn  = 3600,`,
        `)`,
        `print(url)`,
    ].join('\n'));

    setText('code-laravel', [
        `# config/filesystems.php`,
        `'disks' => [`,
        `    'mycloud' => [`,
        `        'driver'                  => 's3',`,
        `        'key'                     => env('STORAGE_ACCESS_KEY', '${ak}'),`,
        `        'secret'                  => env('STORAGE_SECRET_KEY', '${sk}'),`,
        `        'region'                  => env('AWS_DEFAULT_REGION', 'us-east-1'),`,
        `        'bucket'                  => env('STORAGE_BUCKET', '${bk}'),`,
        `        'url'                     => env('STORAGE_PUBLIC_URL', '${pu}'),`,
        `        'endpoint'                => env('STORAGE_ENDPOINT', '${ep}'),`,
        `        'use_path_style_endpoint' => true,`,
        `        'throw'                   => false,`,
        `    ],`,
        `],`,
        ``,
        `# Usage in your app`,
        `Storage::disk('mycloud')->put('avatars/user.jpg', $fileContents);`,
        `$url = Storage::disk('mycloud')->url('avatars/user.jpg');`,
        `$tmp = Storage::disk('mycloud')->temporaryUrl('avatars/user.jpg', now()->addHour());`,
    ].join('\n'));
}

function renderKeysTable(keys) {
    const tbody = document.querySelector('#keys-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!keys.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">No API keys generated yet</td></tr>';
        return;
    }

    keys.forEach(k => {
        const perms = k.permissions || 'read';
        const permBadge = perms === 'admin'
            ? '<span class="badge badge-purple">ADMIN</span>'
            : perms === 'write'
                ? '<span class="badge badge-blue">READ/WRITE</span>'
                : '<span class="badge badge-gray">READ ONLY</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong style="color:var(--text-primary);">${escHtml(k.name || 'Unnamed Key')}</strong></td>
            <td>
                <span style="font-family:var(--font-mono);font-size:0.78rem;color:var(--cyan);">${escHtml(k.access_key)}</span>
                <button class="btn btn-secondary btn-xs" onclick="clipText('${escHtml(k.access_key)}', this)" style="margin-left:0.4rem;">Copy</button>
            </td>
            <td style="font-family:var(--font-mono);font-size:0.8rem;">${escHtml(k.default_bucket || '—')}</td>
            <td>${permBadge}</td>
            <td style="color:var(--text-muted);font-size:0.8rem;">${escHtml(k.created_at)}</td>
            <td>
                <button class="btn btn-danger btn-xs" onclick="deleteKey('${k.id}')">Revoke</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

async function generateApiKey() {
    const name = prompt('Name for this API key:', 'Production Key ' + new Date().toLocaleDateString());
    if (!name) return;

    const res  = await fetch('/api/auth/keys', {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ name }),
    });
    const data = await res.json();
    if (res.ok && data.key) {
        document.getElementById('created-access-key').value = data.key.access_key;
        document.getElementById('created-secret-key').value = data.key.secret_key;
        document.getElementById('modal-key-success').style.display = 'flex';
        loadKeys();
    } else {
        alert(data.error || 'Failed to generate key.');
    }
}

async function deleteKey(id) {
    if (!confirm('Revoke this API key? Any app using it will lose access immediately.')) return;
    const res = await fetch('/api/auth/keys/' + id, { method:'DELETE' });
    if (res.ok) loadKeys();
}

// Env helpers
function copyAllEnv() {
    if (!_credCache) return;
    const c = _credCache.credentials || {};
    const text = [
        `STORAGE_ACCOUNT_ID=${c.ACCOUNT_ID||''}`,
        `STORAGE_ACCESS_KEY=${c.ACCESS_KEY||''}`,
        `STORAGE_SECRET_KEY=${c.SECRET_KEY||''}`,
        `STORAGE_BUCKET=${c.DEFAULT_BUCKET||''}`,
        `STORAGE_ENDPOINT=${c.ENDPOINT||''}`,
        `STORAGE_PUBLIC_URL=${c.PUBLIC_URL||''}`,
    ].join('\n');
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copy-env-btn');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = btn.innerHTML.replace('Copy .env Block', '✓ Copied!');
            setTimeout(() => { btn.innerHTML = orig; }, 2200);
        }
    });
}

function downloadEnvFile() {
    if (!_credCache) return;
    const c = _credCache.credentials || {};
    const lines = [
        `# Object Storage — Generated ${new Date().toISOString()}`,
        `STORAGE_ACCOUNT_ID=${c.ACCOUNT_ID||''}`,
        `STORAGE_ACCESS_KEY=${c.ACCESS_KEY||''}`,
        `STORAGE_SECRET_KEY=${c.SECRET_KEY||''}`,
        `STORAGE_BUCKET=${c.DEFAULT_BUCKET||''}`,
        `STORAGE_ENDPOINT=${c.ENDPOINT||''}`,
        `STORAGE_PUBLIC_URL=${c.PUBLIC_URL||''}`,
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/plain,' + encodeURIComponent(lines);
    a.download = '.env.storage';
    a.click();
}

// ─────────────────────────────────────────────────────────────
// CLIPBOARD / COPY HELPERS
// ─────────────────────────────────────────────────────────────
function copyToClipboard(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    navigator.clipboard.writeText(inp.value).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.color  = 'var(--green)';
        setTimeout(() => { btn.textContent = orig; btn.style.color = ''; }, 2000);
    });
}

function clipText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    });
}

// ─────────────────────────────────────────────────────────────
// GLOBAL HELPERS
// ─────────────────────────────────────────────────────────────
function closeModals() {
    document.querySelectorAll('.modal-backdrop').forEach(el => el.style.display = 'none');
}

async function handleLogout() {
    await fetch('/api/auth/logout', { method:'POST' });
    window.location.reload();
}

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function jsonHeaders() {
    return { 'Content-Type': 'application/json' };
}

function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
