import { verifyApiKey } from './auth.js';
import {
  jsonResponse,
  errorResponse,
  logActivity,
  normalizeAdsCustomerId,
  normalizeIpForGoogleAds,
  formatGoogleAdsError,
} from './utils.js';
import { APP_VERSION } from './version.js';
import { normalizeLicenseSite, normalizeLicenseDomain } from './license-domain.js';
import { handleIpManager } from './ip-manager.js';
import { getConfig } from './runtime-cache.js';
import { invalidateClientIndex } from './clients.js';

export async function handleApiRequest(request, env) {
  const url = new URL(request.url);
  const action = url.searchParams.get('action');
  const hasApiKey = Boolean(request.headers.get('X-API-Key') || url.searchParams.get('api_key'));

  if (action !== 'health' || hasApiKey) {
    const authError = await verifyApiKey(request, env, action === 'validate_license');
    if (authError) return authError;
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

    if (action === 'validate_license') {
      const apiKey = request.headers.get('X-API-Key') || url.searchParams.get('api_key');
      let license;
      try { license = JSON.parse(await env.GADS_KV.get(`license:${apiKey}`)); }
      catch { return errorResponse('Không thể xác thực giấy phép.', 503); }
      if (!license || license.active !== true ||
          normalizeLicenseDomain(license.domain) !== normalizeLicenseDomain(new URL(request.headers.get('X-GAds-Site')).origin) || (license.expires_at &&
          (!Number.isFinite(new Date(license.expires_at).getTime()) || new Date(license.expires_at).getTime() <= Date.now()))) {
        return errorResponse('Giấy phép không hợp lệ hoặc đã hết hạn.', 403);
      }
      return jsonResponse({ success: true, data: { valid: true, expires_at: license.expires_at ? new Date(license.expires_at).toISOString() : null, site_url: normalizeLicenseSite(request.headers.get('X-GAds-Site')) } });
    }

    if (action === 'get_credentials') {
      return jsonResponse({
        success: true,
        data: {
          client_id: env.GADS_CLIENT_ID,
          oauth_redirect_uri: await getConfig(env, 'oauth_redirect'),
          api_version: await getConfig(env, 'api_version') || 'v25'
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

    if (action === 'list_ips' || action === 'remove_ips') {
      return handleIpManager(action, body, env);
    }

    if (action === 'exchange_code') {
      const { code } = body;
      if (!code) {
        return errorResponse('Missing code', 400);
      }

      const redirectUri = await getConfig(env, 'oauth_redirect');

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
      const apiVersion = await getConfig(env, 'api_version') || 'v25';

      // c. Validate IPs (IPv4, IPv6, or Google Ads-compatible x.x.x.* wildcard)
      if (ips.length > 1000) {
        return errorResponse('Tối đa 1000 IP mỗi lần đồng bộ.', 400);
      }
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

      // Results align with operations; a failed operation returns an empty object.
      const results = Array.isArray(adsResult.results) ? adsResult.results : [];
      const added = validIps.filter((ip, index) => results[index] && Object.keys(results[index]).length > 0);

      if (adsResult.partialFailureError) {
        const partialError = formatGoogleAdsError({ error: adsResult.partialFailureError });
        if (added.length === 0) {
          await logActivity(env, 'sync_ips_failed', customerId, 'error', partialError);
          return errorResponse(partialError, 502);
        }
        console.log('sync_ips_partial', customerId, `${added.length}/${validIps.length}`);
        return jsonResponse({
          success: true,
          data: {
            added,
            partial_error: partialError,
            message: `Đã thêm ${added.length}/${validIps.length} IP; một số IP lỗi: ${partialError}`,
          },
        });
      }

      // Success goes to Workers Logs only; KV activity log keeps failures (saves KV writes).
      console.log('sync_ips_success', customerId, `${validIps.length} IPs synced`);

      return jsonResponse({
        success: true,
        data: {
          added: validIps,
          message: `Đã đồng bộ thành công ${validIps.length} IP.`
        }
      });
    }

    if (action === 'register_site') {
      const { site_url } = body;

      if (!site_url) {
        return errorResponse('Missing site_url', 400);
      }

      const verifiedSite = normalizeLicenseSite(request.headers.get('X-GAds-Site'));
      if (verifiedSite && normalizeLicenseSite(site_url) !== verifiedSite) {
        return errorResponse('site_url không khớp website đã xác minh.', 403);
      }

      let parsedUrl;
      try {
        parsedUrl = new URL(site_url);
      } catch (e) {
        return errorResponse('Invalid site_url', 400);
      }

      // Re-registering an active site is a no-op: avoid a KV write per settings save.
      const clientKey = `client:${parsedUrl.origin}`;
      try {
        const existing = JSON.parse(await env.GADS_KV.get(clientKey) || 'null');
        if (existing && existing.status === 'active') {
          return jsonResponse({ success: true, message: 'Site registered successfully' });
        }
      } catch { /* malformed record: overwrite below */ }

      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      const clientData = {
        registered_at: Date.now(),
        ip: clientIp,
        status: 'active'
      };

      await env.GADS_KV.put(clientKey, JSON.stringify(clientData));
      await invalidateClientIndex(env);

      return jsonResponse({ success: true, message: 'Site registered successfully' });
    }
  }

  return errorResponse('Invalid action. Available actions: health, validate_license, get_credentials, exchange_code, sync_ips, list_ips, remove_ips, register_site', 400);
}
