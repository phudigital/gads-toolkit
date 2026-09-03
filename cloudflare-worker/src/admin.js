export async function handleAdminRequest(request, env, path) {
  const method = request.method;

  // Serve Dashboard HTML
  if (path === '' || path === '/') {
    if (method === 'GET') {
      return new Response(getDashboardHTML(), {
        headers: { 'Content-Type': 'text/html;charset=UTF-8' }
      });
    }
  }

  // API Route: Login
  if (path === '/api/login' && method === 'POST') {
    try {
      const body = await request.json();
      if (body.token === env.ADMIN_TOKEN) {
        return new Response(JSON.stringify({ success: true }), {
          headers: { 'Content-Type': 'application/json' }
        });
      }
    } catch (e) {
      // Ignore JSON parse errors
    }
    return new Response(JSON.stringify({ success: false, error: 'Unauthorized' }), {
      status: 401,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  // --- Authentication Check for all other API routes ---
  const authHeader = request.headers.get('Authorization');
  if (!authHeader || authHeader !== `Bearer ${env.ADMIN_TOKEN}`) {
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

      const licenseData = {
        domain: domain || '',
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

function getDashboardHTML() {
  return `<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GAds Toolkit - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .logout-btn {
            position: absolute;
            bottom: 30px;
            left: 20px;
            right: 20px;
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }

        .logout-btn:hover {
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
                    <label>Admin Token</label>
                    <input type="password" id="admin-token" required placeholder="Nhập token...">
                </div>
                <button type="submit" class="btn">Đăng nhập</button>
            </form>
        </div>
    </div>

    <!-- Dashboard Layout -->
    <div id="dashboard-screen" class="hidden">

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">GAds Toolkit</div>
            <ul class="nav-list">
                <li class="nav-item">
                    <a class="nav-link active" data-page="overview">
                        <span class="nav-icon">🏠</span> Tổng quan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-page="licenses">
                        <span class="nav-icon">🔑</span> License Keys
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-page="clients">
                        <span class="nav-icon">🌐</span> Sites kết nối
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-page="config">
                        <span class="nav-icon">⚙️</span> Cấu hình
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-page="logs">
                        <span class="nav-icon">📋</span> Activity Log
                    </a>
                </li>
            </ul>
            <button class="logout-btn" onclick="logout()">Đăng xuất</button>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>

            <!-- Overview Page -->
            <div id="page-overview" class="page-section">
                <h1>Tổng quan</h1>

                <div class="stats-grid">
                    <div class="card stat-card">
                        <div class="stat-icon">🌐</div>
                        <div class="stat-info">
                            <h3>Tổng số Sites</h3>
                            <p id="stat-total-clients">...</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-icon">🔑</div>
                        <div class="stat-info">
                            <h3>License Active</h3>
                            <p id="stat-active-licenses">...</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-icon">⚡</div>
                        <div class="stat-info">
                            <h3>API Version</h3>
                            <p id="stat-api-version">...</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-icon">📈</div>
                        <div class="stat-info">
                            <h3>Requests (Hôm nay)</h3>
                            <p id="stat-requests-today">...</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>Hoạt động gần đây</h2>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Hành động</th>
                                    <th>Client IP/URL</th>
                                    <th>Kết quả</th>
                                </tr>
                            </thead>
                            <tbody id="overview-logs-tbody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Licenses Page -->
            <div id="page-licenses" class="page-section hidden">
                <div class="page-header">
                    <h1>License Keys</h1>
                    <button class="btn" style="width:auto" onclick="openLicenseModal()">+ Thêm License</button>
                </div>

                <div class="card">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Domain</th>
                                    <th>Label</th>
                                    <th>Ngày hết hạn</th>
                                    <th>Trạng thái</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="licenses-tbody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Clients Page -->
            <div id="page-clients" class="page-section hidden">
                <h1>Sites kết nối</h1>

                <div class="card">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Site URL</th>
                                    <th>IP</th>
                                    <th>Ngày đăng ký</th>
                                    <th>Lần đồng bộ cuối</th>
                                    <th>Trạng thái</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="clients-tbody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Config Page -->
            <div id="page-config" class="page-section hidden">
                <h1>Cấu hình hệ thống</h1>

                <div class="card" style="max-width: 600px;">
                    <form id="config-form">
                        <div class="input-group">
                            <label>API Version</label>
                            <input type="text" id="cfg-api-version" placeholder="v25">
                        </div>
                        <div class="input-group">
                            <label>OAuth Redirect URI</label>
                            <input type="url" id="cfg-oauth-redirect" placeholder="https://...">
                        </div>
                        <div class="input-group">
                            <label>Rate Limit (requests / hour / IP)</label>
                            <input type="number" id="cfg-rate-limit" placeholder="100">
                        </div>
                        <div class="input-group">
                            <label>Legacy API Key (Master fallback)</label>
                            <input type="text" id="cfg-legacy-key" placeholder="Nhập key...">
                        </div>
                        <div class="input-group">
                            <label>Allowed Origins</label>
                            <div class="tags-container" id="cfg-origins-container">
                                <input type="text" class="tags-input" id="cfg-origins-input" placeholder="Thêm domain và nhấn Enter...">
                            </div>
                        </div>
                        <button type="submit" class="btn">Lưu cấu hình</button>
                    </form>
                </div>
            </div>

            <!-- Logs Page -->
            <div id="page-logs" class="page-section hidden">
                <div class="page-header">
                    <h1>Activity Log</h1>
                    <button class="btn btn-outline" style="width:auto" onclick="loadLogs()">Làm mới</button>
                </div>

                <div class="card">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Hành động</th>
                                    <th>Client</th>
                                    <th>Kết quả</th>
                                    <th>Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody id="logs-tbody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
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
                    <label>Domain áp dụng (để trống nếu ko giới hạn)</label>
                    <input type="text" id="lic-domain" placeholder="example.com">
                </div>
                <div class="input-group">
                    <label>Nhãn (Label)</label>
                    <input type="text" id="lic-label" placeholder="Khách hàng A...">
                </div>
                <div class="input-group">
                    <label>Ngày hết hạn (để trống nếu vĩnh viễn)</label>
                    <input type="date" id="lic-expiry">
                </div>
                <div class="input-group flex items-center" style="gap: 12px; margin-bottom: 24px;">
                    <label style="margin:0;">Kích hoạt</label>
                    <label class="switch">
                        <input type="checkbox" id="lic-active" checked>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Hidden field to track if editing -->
                <input type="hidden" id="lic-is-edit" value="false">

                <div class="flex" style="gap: 12px;">
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

            try {
                const res = await fetch(API_BASE + '/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token })
                });

                if (res.ok) {
                    localStorage.setItem('adminToken', token);
                    initDashboard();
                } else {
                    showToast('Token không hợp lệ', 'error');
                }
            } catch (err) {
                showToast('Lỗi kết nối', 'error');
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
                const res = await fetch(API_BASE + '/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token })
                });
                return res.ok;
            } catch {
                return false;
            }
        }

        // --- Navigation ---
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                e.currentTarget.classList.add('active');

                const pageId = e.currentTarget.getAttribute('data-page');

                document.querySelectorAll('.page-section').forEach(p => p.classList.add('hidden'));
                document.getElementById('page-' + pageId).classList.remove('hidden');

                if (window.innerWidth <= 768) toggleSidebar();

                loadPageData(pageId);
            });
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

        function renderOverviewLogs(logs) {
            const tbody = document.getElementById('overview-logs-tbody');
            tbody.innerHTML = '';
            logs.forEach(log => {
                const tr = document.createElement('tr');
                const badgeClass = log.success ? 'badge-success' : 'badge-danger';
                const resultText = log.success ? 'Thành công' : 'Thất bại';

                tr.innerHTML = \`
                    <td>\${formatLogDate(log.time)}</td>
                    <td>\${log.action || '-'}</td>
                    <td>\${truncateStr(log.client_url || log.ip, 20)}</td>
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
                    const statusClass = lic.active ? 'status-active' : 'status-inactive';

                    tr.innerHTML = \`
                        <td title="\${lic.key}">\${truncateStr(lic.key, 12)}</td>
                        <td>\${lic.domain || '<span style="color:#a3aed1">Mọi domain</span>'}</td>
                        <td>\${lic.label || '-'}</td>
                        <td>\${expiry}</td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" \${lic.active ? 'checked' : ''} onchange="toggleLicenseStatus('\${lic.key}', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <button class="action-btn" onclick='editLicense(\${JSON.stringify(lic).replace(/'/g, "&#39;")})'>✏️</button>
                            <button class="action-btn delete" onclick="deleteLicense('\${lic.key}')">🗑️</button>
                        </td>
                    \`;
                    tbody.appendChild(tr);
                });

                if (licenses.length === 0) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Chưa có license nào</td></tr>';
            } catch(e) {}
        }

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

        // -- Logs --
        async function loadLogs() {
            try {
                const logs = await apiCall('/logs');
                const tbody = document.getElementById('logs-tbody');
                tbody.innerHTML = '';

                logs.forEach(log => {
                    const tr = document.createElement('tr');
                    const badgeClass = log.success ? 'badge-success' : 'badge-danger';
                    const resultText = log.success ? 'Thành công' : 'Thất bại';

                    let detailStr = '';
                    if (log.error) detailStr = log.error;
                    else if (log.message) detailStr = log.message;

                    tr.innerHTML = \`
                        <td>\${formatLogDate(log.time)}</td>
                        <td>\${log.action || '-'}</td>
                        <td>
                            \${log.client_url || '-'}<br>
                            <small style="color:var(--text-muted)">IP: \${log.ip || '-'}</small>
                        </td>
                        <td><span class="badge \${badgeClass}">\${resultText}</span></td>
                        <td><span style="font-size:12px;color:var(--text-muted)">\${truncateStr(detailStr, 40)}</span></td>
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
