<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — MyCloud Storage</title>
    <meta name="description" content="Sign in to manage your self-hosted S3-compatible object storage platform.">
    <link rel="stylesheet" href="/css/app.css">
    <style>
        /* Login-page specific overrides */
        body {
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
            background-image:
                radial-gradient(ellipse 80% 70% at 15% 10%, rgba(59,130,246,0.13) 0%, transparent 55%),
                radial-gradient(ellipse 60% 60% at 88% 8%,  rgba(99,102,241,0.11) 0%, transparent 50%),
                radial-gradient(ellipse 50% 40% at 50% 100%,rgba(6,182,212,0.07) 0%, transparent 50%);
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* Animated gradient logo ring */
        .login-logo-ring {
            width: 64px; height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, #3B82F6 0%, #6366F1 50%, #06B6D4 100%);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 0.5rem;
            box-shadow:
                0 0 0 8px rgba(59,130,246,0.1),
                0 0 0 16px rgba(59,130,246,0.05),
                0 16px 40px rgba(59,130,246,0.35);
            animation: logoPulse 3s ease-in-out infinite;
        }
        @keyframes logoPulse {
            0%,100% { box-shadow: 0 0 0 8px rgba(59,130,246,0.1), 0 0 0 16px rgba(59,130,246,0.05), 0 16px 40px rgba(59,130,246,0.35); }
            50%      { box-shadow: 0 0 0 10px rgba(99,102,241,0.15), 0 0 0 20px rgba(99,102,241,0.07), 0 16px 50px rgba(99,102,241,0.45); }
        }

        .login-card {
            padding: 2.25rem;
        }

        .login-title {
            font-size: 1.45rem;
            font-weight: 800;
            text-align: center;
            letter-spacing: -0.03em;
            background: linear-gradient(130deg, #fff 40%, #93C5FD 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.35rem;
        }
        .login-sub {
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        .login-submit {
            width: 100%;
            padding: 0.85rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            border-radius: var(--radius-md);
        }

        .login-error {
            display: none;
            padding: 0.8rem 1rem;
            background: rgba(244,63,94,0.12);
            border: 1px solid rgba(244,63,94,0.3);
            border-radius: var(--radius-sm);
            color: #F87171;
            font-size: 0.85rem;
            margin-bottom: 1.1rem;
            display: none;
            align-items: center;
            gap: 0.6rem;
        }

        /* Server info badge at bottom */
        .server-info-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.78rem;
            color: var(--text-muted);
            padding: 0.7rem 1rem;
        }
        .server-info-bar .dot {
            width: 6px; height: 6px;
            background: var(--emerald);
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }
        .server-url {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--cyan);
        }

        /* Input password toggle */
        .pass-wrap { position: relative; }
        .pass-wrap .form-input { padding-right: 2.75rem; }
        .pass-toggle {
            position: absolute;
            right: 0.75rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted);
            cursor: pointer; font-size: 1rem;
            padding: 0.2rem;
            transition: color var(--transition);
        }
        .pass-toggle:hover { color: var(--text-primary); }
    </style>
</head>
<body>

    <div class="login-wrapper">
        <!-- Logo -->
        <div style="text-align:center;">
            <div class="login-logo-ring">☁</div>
        </div>

        <!-- Login Card -->
        <div class="glass login-card">
            <h1 class="login-title">MyCloud Storage</h1>
            <p class="login-sub">Self-hosted · S3 Compatible · Cloudflare R2 Format</p>

            <!-- Error banner -->
            <div id="loginError" class="login-error" role="alert">
                <svg fill="none" viewBox="0 0 24 24" width="16" height="16" style="flex-shrink:0;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span id="loginErrorText">Login failed.</span>
            </div>

            <form id="loginForm" onsubmit="handleLogin(event)" novalidate>
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username"
                        class="form-input" placeholder="admin"
                        autocomplete="username" autofocus required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="pass-wrap">
                        <input type="password" id="password" name="password"
                            class="form-input" placeholder="••••••••"
                            autocomplete="current-password" required>
                        <button type="button" class="pass-toggle" onclick="togglePassword(this)" title="Show / hide password" aria-label="Toggle password visibility">👁️</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary login-submit" id="loginBtn">
                    <svg fill="none" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    Sign In to Dashboard
                </button>
            </form>
        </div>

        <!-- Server info bar -->
        <div class="glass server-info-bar">
            <span class="dot"></span>
            <span>Server endpoint:</span>
            <span class="server-url" id="detected-url">detecting…</span>
        </div>

        <!-- Default creds hint (development only) -->
        <div style="text-align:center;font-size:0.75rem;color:var(--text-muted);padding:0.25rem;">
            Default: <code style="color:var(--cyan);">admin</code> / <code style="color:var(--cyan);">adminpassword</code>
        </div>
    </div>

    <script>
        // Auto-detect and display the server URL
        fetch('/api/server-info')
            .then(r => r.json())
            .then(d => {
                const el = document.getElementById('detected-url');
                if (el) el.textContent = d.base_url || window.location.origin;
            })
            .catch(() => {
                const el = document.getElementById('detected-url');
                if (el) el.textContent = window.location.origin;
            });

        function togglePassword(btn) {
            const inp = document.getElementById('password');
            inp.type = inp.type === 'password' ? 'text' : 'password';
            btn.textContent = inp.type === 'password' ? '👁️' : '🙈';
        }

        async function handleLogin(e) {
            e.preventDefault();
            const errDiv  = document.getElementById('loginError');
            const errText = document.getElementById('loginErrorText');
            const btn     = document.getElementById('loginBtn');

            errDiv.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span style="opacity:0.7;">Signing in…</span>';

            try {
                const res  = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                    }),
                });
                const data = await res.json();

                if (res.ok && data.success) {
                    btn.innerHTML = '✓ Success — redirecting…';
                    // Preserve any #hash from the URL (e.g. ?redirect=#keys)
                    const params = new URLSearchParams(window.location.search);
                    const redirect = params.get('redirect') || '';
                    window.location.href = '/' + (redirect ? '#' + redirect.replace('#','') : '');
                } else {
                    errText.textContent = data.error || 'Invalid credentials.';
                    errDiv.style.display = 'flex';
                    btn.disabled = false;
                    btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg> Sign In to Dashboard';
                }
            } catch (err) {
                errText.textContent = 'Network error — is the server running?';
                errDiv.style.display = 'flex';
                btn.disabled = false;
                btn.innerHTML = 'Sign In to Dashboard';
            }
        }
    </script>
</body>
</html>
