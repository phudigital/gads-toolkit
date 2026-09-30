import { hashAdminToken, verifyAdminToken, verifyAdminTokenValue } from './auth.js';
import { logActivity } from './utils.js';
import { normalizeLicenseDomain } from './license-domain.js';
import { APP_VERSION } from './version.js';

const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

async function validateTurnstile(request, env, token) {
  if (!env.TURNSTILE_SECRET_KEY) return true;
  if (typeof token !== 'string' || !token) return false;

  try {
    const response = await fetch(TURNSTILE_VERIFY_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        secret: env.TURNSTILE_SECRET_KEY,
        response: token,
        remoteip: request.headers.get('CF-Connecting-IP') || undefined,
        idempotency_key: crypto.randomUUID(),
      }),
    });
    const result = await response.json();
    const expectedHostname = new URL(request.url).hostname;
    if (!response.ok || !result.success || result.action !== 'admin_login' || result.hostname !== expectedHostname) {
      console.warn('Admin Turnstile rejected', JSON.stringify({ codes: result['error-codes'] || [], hostname: result.hostname, action: result.action }));
    }
    return response.ok && result.success === true
      && result.action === 'admin_login'
      && result.hostname === expectedHostname;
  } catch (error) {
    console.error('Turnstile verification failed:', error.message);
    return false;
  }
}

function isValidAdminToken(token) {
  return typeof token === 'string' && token.length >= 12 && token.length <= 256;
}

export async function handleAdminRequest(request, env, path) {
  const method = request.method;

  // Serve Dashboard HTML
  if (path === '' || path === '/') {
    if (method === 'GET') {
      return new Response(getDashboardHTML({
        turnstileSiteKey: env.TURNSTILE_SITE_KEY || '',
        turnstileEnabled: Boolean(env.TURNSTILE_SECRET_KEY),
      }), {
        headers: { 'Content-Type': 'text/html;charset=UTF-8' }
      });
    }
  }

  // API Route: Login
  if (path === '/api/login' && method === 'POST') {
    try {
      const body = await request.json();
      const isVerified = await validateTurnstile(request, env, body.turnstile_token);
      if (!isVerified) {
        return new Response(JSON.stringify({ success: false, code: 'TURNSTILE_FAILED', error: 'Xác minh bảo mật chưa hoàn tất hoặc đã hết hạn. Vui lòng xác minh lại.' }), {
          status: 403, headers: { 'Content-Type': 'application/json' }
        });
      }
      if (await verifyAdminTokenValue(body.token, env)) {
        return new Response(JSON.stringify({ success: true }), {
          headers: { 'Content-Type': 'application/json' }
        });
      }
    } catch (e) {
      // Ignore JSON parse errors
    }
    return new Response(JSON.stringify({ success: false, error: 'Mật khẩu Admin không đúng.' }), {
      status: 401,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  // --- Authentication Check for all other API routes ---
  if (!await verifyAdminToken(request, env)) {
    return new Response(JSON.stringify({ success: false, error: 'Unauthorized' }), {
      status: 401,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  const url = new URL(request.url);

  // Helper to respond with JSON
  const jsonResponse = (data, status = 200) => new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' }
  });

  try {
    // API Route: Rotate Admin Token
    if (path === '/api/security/admin-token' && method === 'PUT') {
      const body = await request.json();
      const { current_token, new_token } = body;

      if (!await verifyAdminTokenValue(current_token, env)) {
        return jsonResponse({ error: 'Mật khẩu Admin hiện tại không hợp lệ.' }, 401);
      }
      if (!isValidAdminToken(new_token)) {
        return jsonResponse({ error: 'Mật khẩu Admin mới phải có từ 12 đến 256 ký tự.' }, 400);
      }
      if (await verifyAdminTokenValue(new_token, env)) {
        return jsonResponse({ error: 'Mật khẩu Admin mới phải khác mật khẩu hiện tại.' }, 400);
      }

      await env.GADS_KV.put('config:admin_token_hash', await hashAdminToken(new_token));
      await logActivity(
        env,
        'admin_token_rotated',
        request.headers.get('CF-Connecting-IP') || 'unknown',
        'success',
        'Admin password was changed'
      );
      return jsonResponse({ success: true });
    }

    // API Route: Stats
    if (path === '/api/stats' && method === 'GET') {
      const licensesList = await env.GADS_KV.list({ prefix: 'license:' });
      let activeLicenses = 0;
      let totalLicenses = licensesList.keys.length;

      for (let key of licensesList.keys) {
        const valStr = await env.GADS_KV.get(key.name);
        if (valStr) {
          try {
            const val = JSON.parse(valStr);
            if (val.active) activeLicenses++;
          } catch(e) {}
        }
      }

      const clientsList = await env.GADS_KV.list({ prefix: 'client:' });
      const totalClients = clientsList.keys.length;

      const apiVersion = await env.GADS_KV.get('config:api_version') || 'v25';
      const logsStr = await env.GADS_KV.get('logs:recent') || '[]';
      const logs = JSON.parse(logsStr);

      const today = new Date().toISOString().split('T')[0];
      const requestsToday = logs.filter(l => l.time && l.time.startsWith(today)).length;

      return jsonResponse({
        totalLicenses,
        activeLicenses,
        totalClients,
        apiVersion,
        requestsToday
      });
    }

    // API Route: Licenses (GET)
    if (path === '/api/licenses' && method === 'GET') {
      const list = await env.GADS_KV.list({ prefix: 'license:' });
      const licenses = [];
      for (let k of list.keys) {
        const valStr = await env.GADS_KV.get(k.name);
        if (valStr) {
          try {
            const val = JSON.parse(valStr);
            licenses.push({ key: k.name.replace('license:', ''), ...val });
          } catch(e){}
        }
      }
      // Sort by created_at desc
      licenses.sort((a, b) => (b.created_at || 0) - (a.created_at || 0));
      return jsonResponse(licenses);
    }

    // API Route: Licenses (POST - Create)
    if (path === '/api/licenses' && method === 'POST') {
      const body = await request.json();
      const { key, domain, label, expires_at, active } = body;

      if (!key) return jsonResponse({ error: 'Key is required' }, 400);
      const licenseDomain = normalizeLicenseDomain(domain);
      if (!licenseDomain) return jsonResponse({ error: 'Nhập một domain công khai hợp lệ, ví dụ abc.com; không dùng wildcard hoặc đường dẫn.' }, 400);

      const licenseData = {
        domain: licenseDomain,
        label: label || '',
        expires_at: expires_at || null,
        active: active !== undefined ? active : true,
        created_at: Date.now()
      };

      await env.GADS_KV.put(`license:${key}`, JSON.stringify(licenseData));
      return jsonResponse({ success: true, key, ...licenseData });
    }

    // API Route: Licenses (PUT - Update) / (DELETE - Delete)
    if (path.startsWith('/api/licenses/') && (method === 'PUT' || method === 'DELETE')) {
      const key = path.replace('/api/licenses/', '');

      if (method === 'DELETE') {
        await env.GADS_KV.delete(`license:${key}`);
        return jsonResponse({ success: true });
      }

      // PUT Update
      const body = await request.json();
      const existingStr = await env.GADS_KV.get(`license:${key}`);
      if (!existingStr) return jsonResponse({ error: 'License not found' }, 404);

      const existing = JSON.parse(existingStr);
      const updated = { ...existing, ...body };
      const licenseDomain = normalizeLicenseDomain(updated.domain);
      if (!licenseDomain) return jsonResponse({ error: 'License cần một domain công khai hợp lệ.' }, 400);
      updated.domain = licenseDomain;
      // Prevent created_at override if not needed, but body could have it.
      // We just merge.

      await env.GADS_KV.put(`license:${key}`, JSON.stringify(updated));
      return jsonResponse({ success: true, key, ...updated });
    }

    // API Route: Clients (GET)
    if (path === '/api/clients' && method === 'GET') {
      const list = await env.GADS_KV.list({ prefix: 'client:' });
      const clients = [];
      for (let k of list.keys) {
        const valStr = await env.GADS_KV.get(k.name);
        if (valStr) {
          try {
            const val = JSON.parse(valStr);
            clients.push({ url: k.name.replace('client:', ''), ...val });
          } catch(e) {}
        }
      }
      clients.sort((a, b) => (b.registered_at || 0) - (a.registered_at || 0));
      return jsonResponse(clients);
    }

    // API Route: Clients (DELETE)
    if (path === '/api/clients' && method === 'DELETE') {
      const body = await request.json();
      if (!body.url) return jsonResponse({ error: 'URL is required' }, 400);
      await env.GADS_KV.delete(`client:${body.url}`);
      return jsonResponse({ success: true });
    }

    // API Route: Config (GET)
    if (path === '/api/config' && method === 'GET') {
      const api_version = await env.GADS_KV.get('config:api_version') || 'v25';
      const rate_limit = await env.GADS_KV.get('config:rate_limit') || '100';
      const allowed_origins_str = await env.GADS_KV.get('config:allowed_origins') || '[]';
      let allowed_origins = [];
      try { allowed_origins = JSON.parse(allowed_origins_str); } catch(e){}

      const oauth_redirect = await env.GADS_KV.get('config:oauth_redirect') || '';
      const legacy_api_key = await env.GADS_KV.get('config:legacy_api_key') || '';

      return jsonResponse({
        api_version,
        rate_limit,
        allowed_origins,
        oauth_redirect,
        legacy_api_key
      });
    }

    // API Route: Config (PUT)
    if (path === '/api/config' && method === 'PUT') {
      const body = await request.json();

      if (body.api_version !== undefined) await env.GADS_KV.put('config:api_version', String(body.api_version));
      if (body.rate_limit !== undefined) await env.GADS_KV.put('config:rate_limit', String(body.rate_limit));
      if (body.allowed_origins !== undefined) await env.GADS_KV.put('config:allowed_origins', JSON.stringify(body.allowed_origins));
      if (body.oauth_redirect !== undefined) await env.GADS_KV.put('config:oauth_redirect', String(body.oauth_redirect));
      if (body.legacy_api_key !== undefined) await env.GADS_KV.put('config:legacy_api_key', String(body.legacy_api_key));

      return jsonResponse({ success: true });
    }

    // API Route: Logs (GET)
    if (path === '/api/logs' && method === 'GET') {
      const logsStr = await env.GADS_KV.get('logs:recent') || '[]';
      let logs = [];
      try { logs = JSON.parse(logsStr); } catch(e){}
      return jsonResponse(logs);
    }

  } catch (error) {
    return jsonResponse({ error: 'Internal Server Error', details: error.message }, 500);
  }

  // Not Found
  return jsonResponse({ error: 'Not Found' }, 404);
}

function getDashboardHTML({ turnstileSiteKey, turnstileEnabled }) {
  const turnstileScript = turnstileEnabled && turnstileSiteKey
    ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
    : '';
  const turnstileWidget = turnstileEnabled && turnstileSiteKey
    ? `<div id="turnstile-widget" class="cf-turnstile" data-sitekey="${turnstileSiteKey}" data-action="admin_login" data-size="flexible" data-callback="onTurnstileSuccess" data-expired-callback="onTurnstileExpired" data-error-callback="onTurnstileError" data-timeout-callback="onTurnstileExpired"></div>`
    : '';

  return `<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GAds Toolkit - Admin Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="/favicon-admin.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    ${turnstileScript}
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #764ba2;
            --sidebar-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-color: #f4f7fe;
            --card-bg: #ffffff;
            --text-main: #2b3674;
            --text-muted: #a3aed1;
            --border-color: #e2e8f0;
            --success: #05cd99;
            --danger: #ee5d50;
            --warning: #ffce20;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            overflow-x: hidden;
        }

        /* Utility Classes */
        .hidden { display: none !important; }
        .flex { display: flex; }
        .grid { display: grid; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }

        /* Typography */
        h1, h2, h3 { color: var(--text-main); font-weight: 700; }
        h1 { font-size: 24px; margin-bottom: 24px; }
        h2 { font-size: 20px; margin-bottom: 16px; }

        /* Login Screen */
        #login-screen {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--bg-color);
        }

        .login-card {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .login-card h2 {
            margin-bottom: 8px;
        }

        .login-card p {
            color: var(--text-muted);
            margin-bottom: 24px;
            font-size: 14px;
        }

        .input-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        .input-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            outline: none;
            transition: all 0.3s;
            font-size: 15px;
        }

        .input-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .btn {
            background: var(--sidebar-gradient);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s;
            width: 100%;
            display: inline-block;
            text-align: center;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
        }
        .btn-danger:hover {
            box-shadow: 0 5px 15px rgba(238,93,80,0.4);
        }

        /* Dashboard Layout */
        #dashboard-screen {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-gradient);
            color: white;
            padding: 30px 20px;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 100;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 40px;
            letter-spacing: 1px;
        }

        .nav-list {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 10px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s;
            font-weight: 500;
            cursor: pointer;
        }

        .nav-link:hover, .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }

        .nav-icon {
            margin-right: 12px;
            font-size: 20px;
        }

        .sidebar-version {
            display: block;
            margin-top: 6px;
            color: rgba(255,255,255,0.72);
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0;
        }

        .sidebar-actions {
            position: absolute;
            bottom: 30px;
            left: 20px;
            right: 20px;
            display: grid;
            gap: 8px;
        }

        .sidebar-action-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }

        .sidebar-action-btn:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 40px;
            transition: margin-left 0.3s;
        }

        .page-section {
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.02);
            margin-bottom: 24px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            display: flex;
            align-items: center;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(102,126,234,0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 16px;
        }

        .stat-info h3 {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 4px;
            font-weight: 500;
        }

        .stat-info p {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            font-size: 14px;
            font-weight: 500;
        }

        tbody tr {
            transition: background 0.3s;
        }

        tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Badges & Status */
        .badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success { background: rgba(5,205,153,0.1); color: var(--success); }
        .badge-danger { background: rgba(238,93,80,0.1); color: var(--danger); }
        .badge-warning { background: rgba(255,206,32,0.1); color: #d9a800; }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .status-active { background: var(--success); }
        .status-inactive { background: var(--danger); }
        .status-warning { background: var(--warning); }

        /* Action Buttons */
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: var(--text-muted);
            margin-right: 8px;
            transition: color 0.3s;
        }
        .action-btn:hover { color: var(--primary); }
        .action-btn.delete:hover { color: var(--danger); }

        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--success); }
        input:checked + .slider:before { transform: translateX(20px); }

        /* Modal */
        .modal {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background: white;
            width: 100%;
            max-width: 500px;
            border-radius: 20px;
            padding: 30px;
            transform: translateY(-20px);
            transition: transform 0.3s;
        }
        .modal.show .modal-content {
            transform: translateY(0);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .flex-input {
            display: flex;
            gap: 10px;
        }
        .flex-input input { flex: 1; }
        .flex-input button { width: auto; padding: 0 16px; }

        /* Tags Input */
        .tags-container {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            background: white;
        }
        .tag {
            background: rgba(102,126,234,0.1);
            color: var(--primary-dark);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tag span { cursor: pointer; font-weight: bold; }
        .tags-input {
            border: none;
            outline: none;
            flex: 1;
            min-width: 120px;
            font-size: 14px;
            padding: 4px;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-main);
            cursor: pointer;
            margin-bottom: 20px;
        }

        /* Header for pages */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .menu-toggle { display: block; }
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--text-main);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s;
            z-index: 2000;
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast.error { background: var(--danger); }
        .toast.success { background: var(--success); }

        /* Prototype contract: keep the Worker dashboard aligned with landing-page/admin.html. */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --bg-color: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --sidebar-width: 256px;
        }

        body { background: #f8fafc; color: #1e293b; }
        #dashboard-screen { display: flex; min-height: 100vh; height: 100vh; overflow: hidden; }
        #dashboard-screen .sidebar {
            width: 256px; background: #fff; color: #475569; padding: 0; position: fixed;
            height: 100vh; left: 0; top: 0; border-right: 1px solid #e2e8f0;
            z-index: 20; display: flex; flex-direction: column; transform: none;
        }
        #dashboard-screen .sidebar-header {
            height: 64px; display: flex; align-items: center; padding: 0 24px;
            border-bottom: 1px solid #f1f5f9; font-size: 20px; text-align: left;
            margin: 0; letter-spacing: 0; color: #4f46e5;
        }
        #dashboard-screen .sidebar-header .brand-mark { margin-right: 8px; color: #6366f1; }
        #dashboard-screen .sidebar-header .brand-name {
            background: linear-gradient(to right, #4f46e5, #9333ea);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        #dashboard-screen .sidebar-version { display: inline; margin: 0 0 0 8px; color: #94a3b8; font-size: 11px; }
        #dashboard-screen .nav-list { flex: 1; overflow-y: auto; padding: 24px 16px; list-style: none; margin: 0; }
        #dashboard-screen .nav-label {
            color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; margin: 0 8px 12px;
        }
        #dashboard-screen .nav-item { margin: 0 0 4px; }
        #dashboard-screen .nav-link {
            display: flex; align-items: center; gap: 12px; padding: 10px 12px;
            color: #475569; border-radius: 8px; font-size: 14px; font-weight: 500;
            text-decoration: none; cursor: pointer; transition: background .2s, color .2s;
        }
        #dashboard-screen .nav-link:hover { color: #4f46e5; background: #f8fafc; }
        #dashboard-screen .nav-link.active { color: #4f46e5; background: #eef2ff; }
        #dashboard-screen .nav-icon { width: 20px; margin: 0; font-size: 14px; text-align: center; color: #94a3b8; }
        #dashboard-screen .nav-link.active .nav-icon { color: #4f46e5; }
        #dashboard-screen .sidebar-actions {
            position: static; padding: 16px; border-top: 1px solid #f1f5f9; display: grid; gap: 4px;
        }
        #dashboard-screen .sidebar-action-btn {
            background: transparent; color: #475569; border: 0; padding: 10px 12px; border-radius: 8px;
            text-align: left; font-size: 14px; font-weight: 500;
        }
        #dashboard-screen .sidebar-action-btn:hover { background: #f8fafc; color: #4f46e5; }
        #dashboard-screen .sidebar-action-btn:last-child { color: #dc2626; }
        #dashboard-screen .sidebar-action-btn:last-child:hover { background: #fef2f2; color: #dc2626; }
        #dashboard-screen .main-content { flex: 1; margin-left: 256px; padding: 0; min-width: 0; overflow: hidden; }
        #dashboard-screen .topbar {
            height: 64px; background: rgba(255,255,255,.85); backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0; display: flex; align-items: center;
            justify-content: space-between; padding: 0 32px; position: sticky; top: 0; z-index: 10;
        }
        #dashboard-screen .topbar h1 { margin: 0; font-size: 20px; line-height: 1.2; color: #1e293b; }
        #dashboard-screen .topbar-meta { display: flex; align-items: center; gap: 20px; color: #64748b; font-size: 13px; font-weight: 500; }
        #dashboard-screen .system-status { display: inline-flex; align-items: center; gap: 8px; background: #f1f5f9; padding: 6px 12px; border-radius: 999px; }
        #dashboard-screen .system-status .status-online { width: 8px; height: 8px; border-radius: 50%; background: #10b981; }
        #dashboard-screen .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(to top right, #6366f1, #a855f7); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; border: 2px solid #fff; box-shadow: 0 0 0 2px #f1f5f9; }
        #dashboard-screen .content-area { height: calc(100vh - 64px); overflow-y: auto; padding: 32px; }
        #dashboard-screen .page-section { max-width: 1152px; margin: 0 auto; animation: fadeIn .4s cubic-bezier(.4,0,.2,1); }
        #dashboard-screen .page-section.hidden { display: none !important; }
        #dashboard-screen .page-section h1 { display: none; }
        #dashboard-screen .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; margin-bottom: 32px; }
        #dashboard-screen .card { background: #fff; border: 1px solid #f1f5f9; border-radius: 16px; padding: 24px; box-shadow: 0 1px 2px rgba(15,23,42,.04); margin-bottom: 24px; }
        #dashboard-screen .stat-card { display: block; position: relative; overflow: hidden; min-height: 156px; }
        #dashboard-screen .stat-card::after { content: ''; position: absolute; width: 96px; height: 96px; right: -16px; top: -16px; border-radius: 50%; background: #eef2ff; opacity: .7; }
        #dashboard-screen .stat-card:nth-child(2)::after { background: #ecfdf5; }
        #dashboard-screen .stat-card:nth-child(3)::after { background: #faf5ff; }
        #dashboard-screen .stat-card:nth-child(4)::after { background: #fff7ed; }
        #dashboard-screen .stat-icon { width: 48px; height: 48px; border-radius: 12px; background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 0 0 16px; position: relative; z-index: 1; }
        #dashboard-screen .stat-card:nth-child(2) .stat-icon { background: #d1fae5; color: #059669; }
        #dashboard-screen .stat-card:nth-child(3) .stat-icon { background: #ede9fe; color: #7c3aed; }
        #dashboard-screen .stat-card:nth-child(4) .stat-icon { background: #ffedd5; color: #ea580c; }
        #dashboard-screen .stat-info { position: relative; z-index: 1; }
        #dashboard-screen .stat-info h3 { margin: 0 0 4px; font-size: 14px; color: #64748b; font-weight: 500; }
        #dashboard-screen .stat-info p { margin: 0; font-size: 30px; line-height: 1.1; color: #1e293b; font-weight: 700; }
        #dashboard-screen .card > h2 { margin: 0; font-size: 18px; color: #1e293b; }
        #dashboard-screen .card > h2 + .table-container { margin-top: 20px; }
        #dashboard-screen .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        #dashboard-screen .page-header h1 { display: block; margin: 0; font-size: 24px; }
        #dashboard-screen .license-search { position: relative; width: min(100%, 384px); }
        #dashboard-screen .license-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; }
        #dashboard-screen .license-search input { width: 100%; padding: 10px 16px 10px 38px; border: 1px solid #e2e8f0; border-radius: 12px; outline: none; font-size: 13px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        #dashboard-screen .license-search input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.12); }
        #dashboard-screen .table-container { overflow-x: auto; }
        #dashboard-screen table { width: 100%; min-width: 700px; border-collapse: collapse; }
        #dashboard-screen th, #dashboard-screen td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; text-align: left; font-size: 14px; }
        #dashboard-screen th { background: #f8fafc; color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        #dashboard-screen td { color: #475569; font-weight: 500; }
        #dashboard-screen tbody tr:hover { background: #f8fafc; }
        #dashboard-screen .btn { width: auto; background: #4f46e5; color: #fff; border: 0; border-radius: 12px; padding: 10px 20px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 10px rgba(79,70,229,.2); }
        #dashboard-screen .btn:hover { background: #4338ca; box-shadow: 0 6px 14px rgba(79,70,229,.25); }
        #dashboard-screen .btn-outline { background: #fff; color: #475569; border: 1px solid #e2e8f0; box-shadow: none; }
        #dashboard-screen .btn-outline:hover { background: #f8fafc; color: #4f46e5; }
        #dashboard-screen .input-group input, #dashboard-screen .input-group select { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 16px; }
        #dashboard-screen .badge { border-radius: 6px; padding: 4px 10px; }
        #dashboard-screen .switch { width: 40px; height: 20px; }
        #dashboard-screen .slider:before { width: 16px; height: 16px; left: 2px; bottom: 2px; }
        #dashboard-screen input:checked + .slider:before { transform: translateX(20px); }
        #dashboard-screen .action-btn { padding: 8px; margin-right: 4px; border-radius: 8px; font-size: 14px; }
        #dashboard-screen .action-btn:hover { background: #eef2ff; }
        #dashboard-screen .action-btn.delete:hover { background: #fef2f2; }
        #dashboard-screen #licenses-tbody .action-btn { opacity: 0; transition: opacity .2s, background .2s, color .2s; }
        #dashboard-screen #licenses-tbody tr:hover .action-btn, #dashboard-screen #licenses-tbody .action-btn:focus { opacity: 1; }
        #dashboard-screen .license-key { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: #1e293b; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 8px; }
        #dashboard-screen .key-cell { display: inline-flex; align-items: center; gap: 6px; }
        #dashboard-screen .expiry-note { display: block; margin-top: 4px; color: #94a3b8; font-size: 11px; font-weight: 400; }
        #dashboard-screen .label-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 6px; color: #1d4ed8; background: #eff6ff; border: 1px solid #dbeafe; font-size: 12px; font-weight: 600; }
        #dashboard-screen .copy-key { color: #94a3b8; background: transparent; border: 0; cursor: pointer; padding: 4px; }
        #dashboard-screen .copy-key:hover { color: #4f46e5; }
        #dashboard-screen .config-card, #dashboard-screen .security-card { max-width: 768px; margin: 0 auto; padding: 0; overflow: hidden; }
        #dashboard-screen .config-card .card-heading { padding: 24px 32px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
        #dashboard-screen .config-card .card-body { padding: 32px; }
        #dashboard-screen .security-card .card-heading { padding: 24px 32px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
        #dashboard-screen .security-card .card-body { padding: 32px; }
        #dashboard-screen .config-card .card-heading h2 { margin: 0; font-size: 20px; }
        #dashboard-screen .config-card .card-heading p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
        #dashboard-screen .config-card form { display: grid; gap: 24px; }
        #dashboard-screen .config-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 24px; }
        #dashboard-screen .config-card .input-group { margin: 0; }
        #dashboard-screen .config-card .input-group label { margin-bottom: 6px; font-weight: 600; color: #334155; }
        #dashboard-screen .config-card .form-actions { padding-top: 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; }
        #dashboard-screen .menu-toggle { display: none; }
        #license-modal { background: rgba(15,23,42,.6); backdrop-filter: blur(4px); }
        #license-modal .modal-content { max-width: 448px; padding: 0; border-radius: 16px; overflow: hidden; transform: scale(.95); }
        #license-modal.show .modal-content { transform: scale(1); }
        #license-modal .modal-header { margin: 0; padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #f1f5f9; }
        #license-modal .modal-header h2 { margin: 0; font-size: 18px; }
        #license-modal #license-form { padding: 24px; }
        #license-modal .input-group { margin-bottom: 20px; }
        #license-modal .input-group input { padding: 10px 16px; border-radius: 12px; }
        #license-modal .flex-input button { padding: 0 16px; white-space: nowrap; }
        #license-modal .modal-actions { padding-top: 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; }
        @media (max-width: 900px) { #dashboard-screen .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media (max-width: 768px) {
            #dashboard-screen .sidebar { transform: translateX(-100%); }
            #dashboard-screen .sidebar.open { transform: translateX(0); box-shadow: 8px 0 24px rgba(15,23,42,.12); }
            #dashboard-screen .main-content { margin-left: 0; }
            #dashboard-screen .topbar { padding: 0 16px; }
            #dashboard-screen .topbar .menu-toggle { display: inline-flex; margin: 0 12px 0 0; background: #f1f5f9; border: 0; border-radius: 8px; padding: 8px; color: #475569; }
            #dashboard-screen .content-area { padding: 20px 16px; }
            #dashboard-screen .topbar-meta .system-status, #dashboard-screen .topbar-meta .version-label { display: none; }
        }
        @media (max-width: 560px) { #dashboard-screen .stats-grid { grid-template-columns: 1fr; gap: 16px; } #dashboard-screen .page-header { align-items: flex-start; flex-direction: column; gap: 12px; } }
    </style>
</head>
<body>

    <!-- Toast Notification -->
    <div id="toast" class="toast">Message</div>

    <!-- Login Screen -->
    <div id="login-screen">
        <div class="login-card">
            <h2>GAds Toolkit</h2>
            <p>Admin Dashboard</p>
            <form id="login-form">
                <div class="input-group">
                    <label>Mật khẩu Admin</label>
                    <input type="password" id="admin-token" required placeholder="Nhập mật khẩu...">
                </div>
                ${turnstileWidget}
                <p id="login-status" role="status" aria-live="polite">${turnstileEnabled ? 'Đang chờ xác minh bảo mật…' : ''}</p>
                <button id="login-submit" type="submit" class="btn" ${turnstileEnabled ? 'disabled' : ''}>Đăng nhập</button>
            </form>
        </div>
    </div>

    <!-- Dashboard Layout -->
    <div id="dashboard-screen" class="hidden">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header"><i class="fas fa-rocket brand-mark"></i><span class="brand-name">GAds Toolkit</span><span class="sidebar-version">v${APP_VERSION}</span></div>
            <ul class="nav-list">
                <li class="nav-label">Menu chính</li>
                <li class="nav-item"><a class="nav-link active" data-page="overview"><i class="fas fa-chart-pie nav-icon"></i><span>Tổng quan</span></a></li>
                <li class="nav-item"><a class="nav-link" data-page="licenses"><i class="fas fa-key nav-icon"></i><span>License Keys</span></a></li>
                <li class="nav-item"><a class="nav-link" data-page="clients"><i class="fas fa-server nav-icon"></i><span>Sites kết nối</span></a></li>
                <li class="nav-item"><a class="nav-link" data-page="config"><i class="fas fa-sliders-h nav-icon"></i><span>Cấu hình</span></a></li>
                <li class="nav-item"><a class="nav-link" data-page="logs"><i class="fas fa-list-ul nav-icon"></i><span>Activity Log</span></a></li>
            </ul>
            <div class="sidebar-actions">
                <button type="button" class="sidebar-action-btn" onclick="navigateToPage('security')"><i class="fas fa-shield-alt nav-icon"></i> Đổi mật khẩu</button>
                <button type="button" class="sidebar-action-btn" onclick="logout()"><i class="fas fa-sign-out-alt nav-icon"></i> Đăng xuất</button>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="flex items-center">
                    <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Mở menu"><i class="fas fa-bars"></i></button>
                    <h1 id="page-title">Tổng quan</h1>
                </div>
                <div class="topbar-meta">
                    <div class="system-status"><span class="status-online"></span> System Online</div>
                    <span class="version-label">v${APP_VERSION}</span>
                    <div class="user-avatar" aria-label="Admin">A</div>
                </div>
            </header>

            <div class="content-area">
                <div id="page-overview" class="page-section">
                    <div class="stats-grid">
                        <div class="card stat-card"><div class="stat-icon"><i class="fas fa-server"></i></div><div class="stat-info"><p id="stat-total-clients">...</p><h3>Tổng số Sites</h3></div></div>
                        <div class="card stat-card"><div class="stat-icon"><i class="fas fa-key"></i></div><div class="stat-info"><p id="stat-active-licenses">...</p><h3>License Đang Active</h3></div></div>
                        <div class="card stat-card"><div class="stat-icon"><i class="fas fa-code-branch"></i></div><div class="stat-info"><p id="stat-api-version">...</p><h3>API Version hiện tại</h3></div></div>
                        <div class="card stat-card"><div class="stat-icon"><i class="fas fa-bolt"></i></div><div class="stat-info"><p id="stat-requests-today">...</p><h3>Requests API</h3></div></div>
                    </div>
                    <div class="card">
                        <h2>Hoạt động gần đây</h2>
                        <div class="table-container"><table><thead><tr><th>Thời gian</th><th>Hành động</th><th>Client IP/URL</th><th>Kết quả</th></tr></thead><tbody id="overview-logs-tbody"></tbody></table></div>
                    </div>
                </div>

                <div id="page-licenses" class="page-section hidden">
                    <div class="page-header">
                        <div class="license-search"><i class="fas fa-search"></i><input id="license-search" type="search" placeholder="Tìm kiếm license..." aria-label="Tìm kiếm license"></div>
                        <button class="btn" onclick="openLicenseModal()"><i class="fas fa-plus"></i> Thêm License</button>
                    </div>
                    <div class="card"><div class="table-container"><table><thead><tr><th>Mã Key</th><th>Domain</th><th>Nhãn (Label)</th><th>Trạng thái</th><th style="text-align:right">Hành động</th></tr></thead><tbody id="licenses-tbody"></tbody></table></div></div>
                </div>

                <div id="page-clients" class="page-section hidden"><div class="page-header"><h1>Sites kết nối</h1></div><div class="card"><div class="table-container"><table><thead><tr><th>Site URL</th><th>IP</th><th>Ngày đăng ký</th><th>Lần đồng bộ cuối</th><th>Trạng thái</th><th>Hành động</th></tr></thead><tbody id="clients-tbody"></tbody></table></div></div></div>

                <div id="page-config" class="page-section hidden"><div class="card config-card"><div class="card-heading"><h2>Cài đặt hệ thống</h2><p>Cấu hình các tham số môi trường và bảo mật cho API.</p></div><div class="card-body"><form id="config-form"><div class="config-grid"><div class="input-group"><label>API Version</label><input type="text" id="cfg-api-version" placeholder="v25"></div><div class="input-group"><label>Rate Limit</label><input type="number" id="cfg-rate-limit" placeholder="100"></div></div><div class="input-group"><label>OAuth Redirect URI</label><input type="url" id="cfg-oauth-redirect" placeholder="https://..."></div><div class="input-group"><label>Legacy API Key (Master fallback)</label><input type="text" id="cfg-legacy-key" placeholder="Nhập key..."></div><div class="input-group"><label>Allowed Origins</label><div class="tags-container" id="cfg-origins-container"><input type="text" class="tags-input" id="cfg-origins-input" placeholder="Thêm domain và nhấn Enter..."></div></div><div class="form-actions"><button type="submit" class="btn">Lưu thay đổi</button></div></form></div></div></div>

                <div id="page-security" class="page-section hidden"><div class="card security-card"><div class="card-heading"><h2>Đổi mật khẩu Admin</h2><p>Cập nhật thông tin đăng nhập quản trị.</p></div><div class="card-body"><form id="admin-token-form"><div class="input-group"><label>Mật khẩu Admin hiện tại</label><input type="password" id="current-admin-token" required autocomplete="current-password"></div><div class="input-group"><label>Mật khẩu Admin mới</label><div class="flex-input"><input type="password" id="new-admin-token" required minlength="12" autocomplete="new-password"><button type="button" class="btn btn-outline" onclick="generateAdminToken()">Tạo mật khẩu mạnh</button></div></div><div class="input-group"><label>Xác nhận mật khẩu Admin mới</label><input type="password" id="confirm-admin-token" required minlength="12" autocomplete="new-password"></div><div class="form-actions"><button type="submit" class="btn">Cập nhật mật khẩu Admin</button></div></form></div></div></div>

                <div id="page-logs" class="page-section hidden"><div class="page-header"><h1>Activity Log</h1><button class="btn btn-outline" onclick="loadLogs()">Làm mới</button></div><div class="card"><div class="table-container"><table><thead><tr><th>Thời gian</th><th>Hành động</th><th>Client</th><th>Kết quả</th><th>Chi tiết</th></tr></thead><tbody id="logs-tbody"></tbody></table></div></div></div>
            </div>
        </main>
    </div>

    <!-- License Modal -->
    <div id="license-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Thêm License</h2>
                <button class="close-modal" onclick="closeLicenseModal()">×</button>
            </div>
            <form id="license-form">
                <div class="input-group">
                    <label>API Key</label>
                    <div class="flex-input">
                        <input type="text" id="lic-key" required>
                        <button type="button" class="btn btn-outline" onclick="generateAndSetKey()">Tạo ngẫu nhiên</button>
                    </div>
                </div>
                <div class="input-group">
                    <label>Domain áp dụng (bắt buộc)</label>
                    <input type="text" id="lic-domain" placeholder="example.com" required>
                </div>
                <div class="input-group">
                    <label>Nhãn (Label)</label>
                    <input type="text" id="lic-label" placeholder="Khách hàng A...">
                </div>
                <div class="input-group">
                    <label>Ngày hết hạn <span style="font-weight:400;color:#64748b">(để trống nếu vĩnh viễn)</span></label>
                    <input type="date" id="lic-expiry">
                </div>
                <div class="input-group flex items-center" style="gap: 12px; margin-bottom: 24px;">
                    <div><label style="margin:0;">Kích hoạt ngay</label><small style="display:block;color:#64748b;margin-top:4px">Cho phép sử dụng API ngay lập tức</small></div>
                    <label class="switch">
                        <input type="checkbox" id="lic-active" checked>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Hidden field to track if editing -->
                <input type="hidden" id="lic-is-edit" value="false">

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeLicenseModal()">Hủy</button>
                    <button type="submit" class="btn">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- Utilities ---
        const API_BASE = '/admin/api';
        let currentEditingKey = null;
        let allowedOriginsList = [];

        function generateApiKey() {
            return Array.from(crypto.getRandomValues(new Uint8Array(16)))
                .map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function generateAdminToken() {
            const token = Array.from(crypto.getRandomValues(new Uint8Array(32)))
                .map(b => b.toString(16).padStart(2, '0')).join('');
            document.getElementById('new-admin-token').value = token;
            document.getElementById('confirm-admin-token').value = token;
        }

        const turnstileRequired = ${JSON.stringify(turnstileEnabled)};
        let loginPending = false;
        function setLoginVerification(ready, message) {
            document.getElementById('login-submit').disabled = loginPending || (turnstileRequired && !ready);
            document.getElementById('login-status').textContent = message;
        }
        function onTurnstileSuccess() { setLoginVerification(true, 'Xác minh thành công. Anh có thể đăng nhập.'); }
        function onTurnstileExpired() { setLoginVerification(false, 'Xác minh đã hết hạn. Vui lòng xác minh lại.'); }
        function onTurnstileError(code) {
            setLoginVerification(false, 'Không thể xác minh bảo mật (mã ' + code + '). Vui lòng tải lại trang.');
        }
        function getTurnstileToken() {
            const widget = document.getElementById('turnstile-widget');
            if (!widget || !window.turnstile) return '';
            return window.turnstile.getResponse('#turnstile-widget') || '';
        }

        function resetTurnstile() {
            const widget = document.getElementById('turnstile-widget');
            setLoginVerification(!turnstileRequired, turnstileRequired ? 'Đang chờ xác minh bảo mật…' : '');
            if (widget && window.turnstile) window.turnstile.reset('#turnstile-widget');
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast ' + type + ' show';
            setTimeout(() => { toast.classList.remove('show'); }, 3000);
        }

        function formatDate(ts) {
            if (!ts) return '-';
            return new Date(ts).toLocaleString('vi-VN');
        }

        function formatLogDate(isoStr) {
            if (!isoStr) return '-';
            try {
                const d = new Date(isoStr);
                return d.toLocaleTimeString('vi-VN') + ' ' + d.toLocaleDateString('vi-VN');
            } catch(e) { return isoStr; }
        }

        function truncateStr(str, max = 15) {
            if (!str) return '';
            return str.length > max ? str.substring(0, max) + '...' : str;
        }

        // --- Fetch Wrapper ---
        async function apiCall(endpoint, options = {}) {
            const token = localStorage.getItem('adminToken');

            const headers = {
                'Content-Type': 'application/json',
                ...options.headers
            };

            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }

            try {
                const res = await fetch(API_BASE + endpoint, { ...options, headers });

                if (res.status === 401) {
                    logout(false);
                    throw new Error('Unauthorized');
                }

                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'API Error');
                return data;
            } catch (err) {
                if (err.message !== 'Unauthorized') {
                    showToast(err.message, 'error');
                }
                throw err;
            }
        }

        // --- Authentication ---
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const token = document.getElementById('admin-token').value;
            if (loginPending) return;
            const turnstile_token = getTurnstileToken();
            if (turnstileRequired && !turnstile_token) {
                setLoginVerification(false, 'Vui lòng chờ xác minh bảo mật hoàn tất.');
                return;
            }
            loginPending = true;
            document.getElementById('login-submit').disabled = true;
            try {
                const res = await fetch(API_BASE + '/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token, turnstile_token })
                });

                if (res.ok) {
                    localStorage.setItem('adminToken', token);
                    initDashboard();
                } else {
                    resetTurnstile();
                    const result = await res.json().catch(() => ({}));
                    showToast(result.error || 'Đăng nhập thất bại. Vui lòng thử lại.', 'error');
                }
            } catch (err) {
                resetTurnstile();
                showToast('Lỗi kết nối', 'error');
            } finally {
                loginPending = false;
                document.getElementById('login-submit').disabled = turnstileRequired && !getTurnstileToken();
            }
        });

        function logout(showMessage = true) {
            localStorage.removeItem('adminToken');
            document.getElementById('dashboard-screen').classList.add('hidden');
            document.getElementById('login-screen').classList.remove('hidden');
            if (showMessage) showToast('Đã đăng xuất');
        }

        async function checkAuth() {
            const token = localStorage.getItem('adminToken');
            if (!token) return false;

            try {
                const res = await fetch(API_BASE + '/stats', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                return res.ok;
            } catch {
                return false;
            }
        }

        // --- Navigation ---
        const pageTitles = {
            overview: 'Tổng quan',
            licenses: 'Quản lý License Keys',
            clients: 'Sites kết nối',
            config: 'Cài đặt hệ thống',
            security: 'Đổi mật khẩu',
            logs: 'Activity Log'
        };

        function navigateToPage(pageId) {
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.toggle('active', link.getAttribute('data-page') === pageId);
            });

            document.querySelectorAll('.page-section').forEach(page => page.classList.add('hidden'));
            document.getElementById('page-' + pageId).classList.remove('hidden');
            document.getElementById('page-title').textContent = pageTitles[pageId] || pageId;

            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
            }

            loadPageData(pageId);
        }

        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => navigateToPage(link.getAttribute('data-page')));
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        // --- Data Loading ---
        function loadPageData(pageId) {
            if (pageId === 'overview') loadOverview();
            else if (pageId === 'licenses') loadLicenses();
            else if (pageId === 'clients') loadClients();
            else if (pageId === 'config') loadConfig();
            else if (pageId === 'security') loadSecurity();
            else if (pageId === 'logs') loadLogs();
        }

        async function initDashboard() {
            const isAuthenticated = await checkAuth();
            if (isAuthenticated) {
                document.getElementById('login-screen').classList.add('hidden');
                document.getElementById('dashboard-screen').classList.remove('hidden');
                loadPageData('overview');
            } else {
                logout(false);
            }
        }

        // -- Overview --
        async function loadOverview() {
            try {
                const stats = await apiCall('/stats');
                document.getElementById('stat-total-clients').textContent = stats.totalClients;
                document.getElementById('stat-active-licenses').textContent = stats.activeLicenses + ' / ' + stats.totalLicenses;
                document.getElementById('stat-api-version').textContent = stats.apiVersion;
                document.getElementById('stat-requests-today').textContent = stats.requestsToday;

                const logs = await apiCall('/logs');
                renderOverviewLogs(logs.slice(0, 20));
            } catch(e) {}
        }

        function getLogPresentation(log) {
            const succeeded = log.result
                ? log.result === 'success'
                : Boolean(log.success);

            return {
                succeeded,
                client: log.client || log.client_url || log.ip || '-',
                detail: log.detail || log.error || log.message || '',
            };
        }

        function renderOverviewLogs(logs) {
            const tbody = document.getElementById('overview-logs-tbody');
            tbody.innerHTML = '';
            logs.forEach(log => {
                const tr = document.createElement('tr');
                const presentation = getLogPresentation(log);
                const badgeClass = presentation.succeeded ? 'badge-success' : 'badge-danger';
                const resultText = presentation.succeeded ? 'Thành công' : 'Thất bại';

                tr.innerHTML = \`
                    <td>\${formatLogDate(log.time)}</td>
                    <td>\${log.action || '-'}</td>
                    <td>\${truncateStr(presentation.client, 20)}</td>
                    <td><span class="badge \${badgeClass}">\${resultText}</span></td>
                \`;
                tbody.appendChild(tr);
            });
            if (logs.length === 0) tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Không có dữ liệu</td></tr>';
        }

        // -- Licenses --
        async function loadLicenses() {
            try {
                const licenses = await apiCall('/licenses');
                const tbody = document.getElementById('licenses-tbody');
                tbody.innerHTML = '';

                licenses.forEach(lic => {
                    const tr = document.createElement('tr');
                    const expiry = lic.expires_at ? new Date(lic.expires_at).toLocaleDateString('vi-VN') : 'Vĩnh viễn';
                    const keyJson = JSON.stringify(lic).replace(/'/g, '&#39;');
                    tr.innerHTML = \`
                        <td title="\${lic.key}"><div class="key-cell"><span class="license-key">\${truncateStr(lic.key, 18)}</span><button class="copy-key" title="Sao chép key" aria-label="Sao chép key" onclick="copyKey('\${lic.key}')"><i class="far fa-copy"></i></button></div></td>
                        <td class="font-medium">\${lic.domain || '<span style="color:#94a3b8">Mọi domain</span>'}<small class="expiry-note">\${expiry}</small></td>
                        <td><span class="label-badge">\${lic.label || '-'}</span></td>
                        <td><label class="switch"><input type="checkbox" \${lic.active ? 'checked' : ''} onchange="toggleLicenseStatus('\${lic.key}', this.checked)"><span class="slider"></span></label></td>
                        <td style="text-align:right"><button class="action-btn" title="Sửa" aria-label="Sửa license" onclick='editLicense(\${keyJson})'><i class="fas fa-edit"></i></button><button class="action-btn delete" title="Xóa" aria-label="Xóa license" onclick="deleteLicense('\${lic.key}')"><i class="fas fa-trash-alt"></i></button></td>
                    \`;
                    tbody.appendChild(tr);
                });

                if (licenses.length === 0) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Chưa có license nào</td></tr>';
                window.gadsLicenses = licenses;
            } catch(e) {}
        }

        async function copyKey(key) {
            try {
                if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(key);
                else {
                    const textarea = document.createElement('textarea');
                    textarea.value = key; textarea.style.position = 'fixed'; textarea.style.opacity = '0';
                    document.body.appendChild(textarea); textarea.focus(); textarea.select();
                    document.execCommand('copy'); textarea.remove();
                }
                showToast('Đã sao chép license key');
            } catch (error) { showToast('Không thể sao chép license key', 'error'); }
        }

        function filterLicenses(value) {
            const query = value.trim().toLowerCase();
            document.querySelectorAll('#licenses-tbody tr').forEach(row => {
                row.hidden = query && !row.textContent.toLowerCase().includes(query);
            });
        }

        document.getElementById('license-search').addEventListener('input', event => filterLicenses(event.target.value));

        function openLicenseModal() {
            document.getElementById('license-modal').classList.add('show');
            document.getElementById('modal-title').textContent = 'Thêm License';
            document.getElementById('license-form').reset();
            document.getElementById('lic-key').readOnly = false;
            document.getElementById('lic-is-edit').value = 'false';
            currentEditingKey = null;
        }

        function closeLicenseModal() {
            document.getElementById('license-modal').classList.remove('show');
        }

        function generateAndSetKey() {
            if(document.getElementById('lic-is-edit').value === 'true') return;
            document.getElementById('lic-key').value = generateApiKey();
        }

        function editLicense(lic) {
            document.getElementById('license-modal').classList.add('show');
            document.getElementById('modal-title').textContent = 'Sửa License';

            document.getElementById('lic-key').value = lic.key;
            document.getElementById('lic-key').readOnly = true;
            document.getElementById('lic-domain').value = lic.domain || '';
            document.getElementById('lic-label').value = lic.label || '';

            if (lic.expires_at) {
                const d = new Date(lic.expires_at);
                document.getElementById('lic-expiry').value = d.toISOString().split('T')[0];
            } else {
                document.getElementById('lic-expiry').value = '';
            }

            document.getElementById('lic-active').checked = lic.active;

            document.getElementById('lic-is-edit').value = 'true';
            currentEditingKey = lic.key;
        }

        document.getElementById('license-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const isEdit = document.getElementById('lic-is-edit').value === 'true';
            const key = document.getElementById('lic-key').value;

            const expiryVal = document.getElementById('lic-expiry').value;
            const expires_at = expiryVal ? new Date(expiryVal).getTime() : null;

            const payload = {
                key,
                domain: document.getElementById('lic-domain').value,
                label: document.getElementById('lic-label').value,
                expires_at,
                active: document.getElementById('lic-active').checked
            };

            try {
                if (isEdit) {
                    await apiCall(\`/licenses/\${key}\`, { method: 'PUT', body: JSON.stringify(payload) });
                    showToast('Đã cập nhật license');
                } else {
                    await apiCall('/licenses', { method: 'POST', body: JSON.stringify(payload) });
                    showToast('Đã tạo license mới');
                }
                closeLicenseModal();
                loadLicenses();
            } catch(e) {}
        });

        async function toggleLicenseStatus(key, active) {
            try {
                await apiCall(\`/licenses/\${key}\`, { method: 'PUT', body: JSON.stringify({ active }) });
                showToast('Đã cập nhật trạng thái');
            } catch(e) {
                loadLicenses(); // revert on fail
            }
        }

        async function deleteLicense(key) {
            if (!confirm('Bạn có chắc chắn muốn xóa license này?')) return;
            try {
                await apiCall(\`/licenses/\${key}\`, { method: 'DELETE' });
                showToast('Đã xóa license');
                loadLicenses();
            } catch(e) {}
        }

        // -- Clients --
        async function loadClients() {
            try {
                const clients = await apiCall('/clients');
                const tbody = document.getElementById('clients-tbody');
                tbody.innerHTML = '';

                clients.forEach(client => {
                    const tr = document.createElement('tr');

                    let statusDot = 'status-inactive';
                    let statusText = 'Inactive';

                    if (client.status === 'active') { statusDot = 'status-active'; statusText = 'Active'; }
                    else if (client.status === 'warning') { statusDot = 'status-warning'; statusText = 'Warning'; }

                    tr.innerHTML = \`
                        <td>\${client.url}</td>
                        <td>\${client.ip || '-'}</td>
                        <td>\${formatDate(client.registered_at)}</td>
                        <td>\${formatDate(client.last_sync)}</td>
                        <td><span class="status-dot \${statusDot}"></span> \${statusText}</td>
                        <td>
                            <button class="action-btn delete" onclick="deleteClient('\${client.url}')">🗑️</button>
                        </td>
                    \`;
                    tbody.appendChild(tr);
                });

                if (clients.length === 0) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Chưa có client nào</td></tr>';
            } catch(e) {}
        }

        async function deleteClient(url) {
            if (!confirm('Bạn có chắc chắn muốn xóa client này?')) return;
            try {
                await apiCall('/clients', { method: 'DELETE', body: JSON.stringify({ url }) });
                showToast('Đã xóa client');
                loadClients();
            } catch(e) {}
        }

        // -- Config --
        async function loadConfig() {
            try {
                const config = await apiCall('/config');
                document.getElementById('cfg-api-version').value = config.api_version || '';
                document.getElementById('cfg-oauth-redirect').value = config.oauth_redirect || '';
                document.getElementById('cfg-rate-limit').value = config.rate_limit || '';
                document.getElementById('cfg-legacy-key').value = config.legacy_api_key || '';

                allowedOriginsList = config.allowed_origins || [];
                renderOriginsTags();
            } catch(e) {}
        }

        function renderOriginsTags() {
            const container = document.getElementById('cfg-origins-container');
            // Remove existing tags
            container.querySelectorAll('.tag').forEach(el => el.remove());

            const input = document.getElementById('cfg-origins-input');

            allowedOriginsList.forEach((origin, index) => {
                const tag = document.createElement('div');
                tag.className = 'tag';
                tag.innerHTML = \`\${origin} <span onclick="removeOrigin(\${index})">×</span>\`;
                container.insertBefore(tag, input);
            });
        }

        document.getElementById('cfg-origins-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = this.value.trim();
                if (val && !allowedOriginsList.includes(val)) {
                    allowedOriginsList.push(val);
                    renderOriginsTags();
                }
                this.value = '';
            }
        });

        function removeOrigin(index) {
            allowedOriginsList.splice(index, 1);
            renderOriginsTags();
        }

        document.getElementById('config-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const payload = {
                api_version: document.getElementById('cfg-api-version').value,
                oauth_redirect: document.getElementById('cfg-oauth-redirect').value,
                rate_limit: parseInt(document.getElementById('cfg-rate-limit').value, 10),
                legacy_api_key: document.getElementById('cfg-legacy-key').value,
                allowed_origins: allowedOriginsList
            };

            try {
                await apiCall('/config', { method: 'PUT', body: JSON.stringify(payload) });
                showToast('Đã lưu cấu hình');
            } catch(e) {}
        });

        // -- Security --
        function loadSecurity() {
            document.getElementById('admin-token-form').reset();
        }

        document.getElementById('admin-token-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const current_token = document.getElementById('current-admin-token').value;
            const new_token = document.getElementById('new-admin-token').value;
            const confirmation = document.getElementById('confirm-admin-token').value;

            if (new_token !== confirmation) {
                showToast('Xác nhận mật khẩu Admin mới chưa khớp', 'error');
                return;
            }

            try {
                await apiCall('/security/admin-token', {
                    method: 'PUT',
                    body: JSON.stringify({ current_token, new_token })
                });
                localStorage.setItem('adminToken', new_token);
                document.getElementById('admin-token-form').reset();
                showToast('Đã cập nhật mật khẩu Admin');
            } catch(e) {}
        });

        // -- Logs --
        async function loadLogs() {
            try {
                const logs = await apiCall('/logs');
                const tbody = document.getElementById('logs-tbody');
                tbody.innerHTML = '';

                logs.forEach(log => {
                    const tr = document.createElement('tr');
                    const presentation = getLogPresentation(log);
                    const badgeClass = presentation.succeeded ? 'badge-success' : 'badge-danger';
                    const resultText = presentation.succeeded ? 'Thành công' : 'Thất bại';

                    tr.innerHTML = \`
                        <td>\${formatLogDate(log.time)}</td>
                        <td>\${log.action || '-'}</td>
                        <td>
                            \${presentation.client}
                        </td>
                        <td><span class="badge \${badgeClass}">\${resultText}</span></td>
                        <td><span style="font-size:12px;color:var(--text-muted)">\${truncateStr(presentation.detail, 40)}</span></td>
                    \`;
                    tbody.appendChild(tr);
                });

                if (logs.length === 0) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Không có log nào</td></tr>';
            } catch(e) {}
        }

        // Init
        document.addEventListener('DOMContentLoaded', initDashboard);

    </script>
</body>
</html>`;
}
