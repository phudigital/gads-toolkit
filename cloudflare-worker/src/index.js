/**
 * GAds Toolkit Central Service — Cloudflare Worker Entry Point
 *
 * Routes:
 *   /api?action=...    → Public API (sync_ips, exchange_code, etc.)
 *   /oauth             → OAuth redirect handler
 *   /admin             → Admin Dashboard
 *   /admin/api/...     → Admin API endpoints
 *
 * Environment Bindings:
 *   GADS_KV            → KV Namespace for all dynamic config
 *   GADS_CLIENT_ID     → Google OAuth Client ID (secret)
 *   GADS_CLIENT_SECRET → Google OAuth Client Secret (secret)
 *   GADS_DEVELOPER_TOKEN → Google Ads Developer Token (secret)
 *   ADMIN_TOKEN        → Admin Dashboard login token (secret)
 *
 * @version 4.1.3
 */

import { handleApiRequest } from './api.js';
import { handleOAuthRedirect } from './oauth.js';
import { handleAdminRequest } from './admin.js';
import { handleCron } from './cron.js';
import { corsResponse, errorResponse, jsonResponse } from './utils.js';
import { APP_VERSION } from './version.js';

export default {
  /**
   * HTTP Request Handler
   */
  async fetch(request, env, ctx) {
    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return corsResponse();
    }

    const url = new URL(request.url);
    const path = url.pathname;

    try {
      // ── Route: /api ──
      if (path === '/api' || path === '/api/') {
        return await handleApiRequest(request, env);
      }

      // ── Route: /oauth ──
      if (path === '/oauth' || path === '/oauth/') {
        return await handleOAuthRedirect(request, env);
      }

      // ── Route: /admin/* ──
      if (path === '/admin' || path.startsWith('/admin/') || path.startsWith('/admin?')) {
        const adminPath = path.replace(/^\/admin/, '') || '/';
        return await handleAdminRequest(request, env, adminPath);
      }

      // ── Route: / (root) ──
      if (path === '/' || path === '') {
        return jsonResponse({
          service: 'GAds Toolkit Central Service',
          version: APP_VERSION,
          status: 'running',
          docs: {
            api: '/api?action=health',
            admin: '/admin',
          },
        });
      }

      // ── 404 ──
      return errorResponse('Not found. Available routes: /api, /oauth, /admin', 404);

    } catch (err) {
      console.error('Unhandled error:', err.message, err.stack);
      return errorResponse(`Internal server error: ${err.message}`, 500);
    }
  },

  /**
   * Cron Trigger Handler (Scheduled)
   * Runs on the schedule defined in wrangler.toml
   */
  async scheduled(event, env, ctx) {
    ctx.waitUntil(handleCron(env));
  },
};
