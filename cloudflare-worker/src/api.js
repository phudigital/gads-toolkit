import { verifyApiKey, checkRateLimit } from './auth.js';
import {
  jsonResponse,
  errorResponse,
  logActivity,
  normalizeAdsCustomerId,
  normalizeIpForGoogleAds,
  formatGoogleAdsError,
} from './utils.js';
import { APP_VERSION } from './version.js';

export async function handleApiRequest(request, env) {
  const url = new URL(request.url);
  const action = url.searchParams.get('action');
  const hasApiKey = Boolean(request.headers.get('X-API-Key') || url.searchParams.get('api_key'));

  if (action !== 'health' || hasApiKey) {
    const authError = await verifyApiKey(request, env);
    if (authError) return authError;

    const rateLimitError = await checkRateLimit(request, env);
    if (rateLimitError) return rateLimitError;
  }

  if (request.method === 'GET') {
    if (action === 'health') {
      return jsonResponse({
        success: true,
        status: 'healthy',
        version: APP_VERSION,
        timestamp: Date.now()
      });
    }

    if (action === 'get_credentials') {
      return jsonResponse({
        success: true,
        data: {
          client_id: env.GADS_CLIENT_ID,
          oauth_redirect_uri: await env.GADS_KV.get('config:oauth_redirect'),
          api_version: await env.GADS_KV.get('config:api_version') || 'v25'
        }
      });
    }
  }

  if (request.method === 'POST') {
    let body = {};
    try {
      body = await request.json();
    } catch (e) {
      return errorResponse('Invalid JSON body', 400);
    }

    if (action === 'exchange_code') {
      const { code } = body;
      if (!code) {
        return errorResponse('Missing code', 400);
      }

      const redirectUri = await env.GADS_KV.get('config:oauth_redirect');

      const tokenResponse = await fetch('https://oauth2.googleapis.com/token', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
          client_id: env.GADS_CLIENT_ID,
          client_secret: env.GADS_CLIENT_SECRET,
          code,
          grant_type: 'authorization_code',
          redirect_uri: redirectUri
        })
      });

      const tokenData = await tokenResponse.json();

      if (!tokenResponse.ok) {
        return errorResponse(tokenData.error_description || 'Failed to exchange code', tokenResponse.status);
      }

      return jsonResponse({ success: true, data: tokenData });
    }

    if (action === 'sync_ips') {
      const { customer_id, manager_id, refresh_token, ips } = body;

      if (!customer_id || !refresh_token || !Array.isArray(ips)) {
        return errorResponse('Missing required parameters (customer_id, refresh_token, ips)', 400);
      }

      const customerId = normalizeAdsCustomerId(customer_id);
      const managerId = manager_id ? normalizeAdsCustomerId(manager_id) : null;
      if (!customerId) {
        return errorResponse('Customer ID không hợp lệ. Hãy nhập đủ 10 chữ số, có thể có dấu gạch ngang.', 400);
      }
      if (manager_id && !managerId) {
        return errorResponse('Manager ID không hợp lệ. Hãy nhập đủ 10 chữ số, có thể có dấu gạch ngang.', 400);
      }

      // a. Get access token from refresh token
      const tokenResponse = await fetch('https://oauth2.googleapis.com/token', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
          client_id: env.GADS_CLIENT_ID,
          client_secret: env.GADS_CLIENT_SECRET,
          refresh_token,
          grant_type: 'refresh_token'
        })
      });

      if (!tokenResponse.ok) {
        return errorResponse('Failed to refresh access token', 401);
      }
      const tokenData = await tokenResponse.json();
      const accessToken = tokenData.access_token;

      // b. Read API version
      const apiVersion = await env.GADS_KV.get('config:api_version') || 'v25';

      // c. Validate IPs (IPv4, IPv6, or Google Ads-compatible x.x.x.* wildcard)
      const validIps = [...new Set(ips.map(normalizeIpForGoogleAds).filter(Boolean))];

      if (validIps.length === 0) {
        return jsonResponse({ success: true, message: 'No valid IPs to sync' });
      }

      // d. Build operations array
      const operations = validIps.map(ipAddress => ({
        create: {
          ip_block: { ip_address: ipAddress }
        }
      }));

      // e. POST to Google Ads API
      const adsUrl = `https://googleads.googleapis.com/${apiVersion}/customers/${customerId}/customerNegativeCriteria:mutate`;
      const adsHeaders = {
        'Authorization': `Bearer ${accessToken}`,
        'developer-token': env.GADS_DEVELOPER_TOKEN,
        'Content-Type': 'application/json'
      };

      // f. Add login-customer-id if manager_id provided
      if (managerId) {
        adsHeaders['login-customer-id'] = managerId;
      }

      const adsResponse = await fetch(adsUrl, {
        method: 'POST',
        headers: adsHeaders,
        body: JSON.stringify({
          operations,
          partialFailure: true,
          validateOnly: false
        })
      });

      const adsResult = await adsResponse.json();

      if (!adsResponse.ok) {
        const errorMessage = formatGoogleAdsError(adsResult);
        await logActivity(
          env,
          'sync_ips_failed',
          customerId,
          'error',
          errorMessage
        );
        return errorResponse(errorMessage, adsResponse.status);
      }

      await logActivity(env, 'sync_ips_success', customerId, 'success', `${validIps.length} IPs synced`);

      // g. Return success/error result
      return jsonResponse({
        success: true,
        data: {
          ...adsResult,
          message: `Đã đồng bộ thành công ${validIps.length} IP.`
        }
      });
    }

    if (action === 'register_site') {
      const { site_url } = body;

      if (!site_url) {
        return errorResponse('Missing site_url', 400);
      }

      let parsedUrl;
      try {
        parsedUrl = new URL(site_url);
      } catch (e) {
        return errorResponse('Invalid site_url', 400);
      }

      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      const clientData = {
        registered_at: Date.now(),
        ip: clientIp,
        status: 'active'
      };

      await env.GADS_KV.put(`client:${parsedUrl.origin}`, JSON.stringify(clientData));

      return jsonResponse({ success: true, message: 'Site registered successfully' });
    }
  }

  return errorResponse('Invalid action. Available actions: health, get_credentials, exchange_code, sync_ips, register_site', 400);
}
