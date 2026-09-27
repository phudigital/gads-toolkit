import { jsonResponse, errorResponse, normalizeAdsCustomerId, formatGoogleAdsError } from './utils.js';

// Called only after the API's license and rate-limit checks.
export async function handleIpManager(action, body, env) {
  const customerId = normalizeAdsCustomerId(body?.customer_id);
  const managerId = body?.manager_id ? normalizeAdsCustomerId(body.manager_id) : null;
  if (!customerId || typeof body?.refresh_token !== 'string' || !body.refresh_token.trim()) {
    return errorResponse('Thiếu Customer ID hợp lệ hoặc chưa kết nối Google Ads.', 400);
  }
  if (body.manager_id && !managerId) return errorResponse('Manager ID không hợp lệ.', 400);

  let resourceNames = [];
  if (action === 'remove_ips') {
    if (!Array.isArray(body.resource_names) || !body.resource_names.length || body.resource_names.length > 500) {
      return errorResponse('Cần chọn từ 1 đến 500 IP để xóa.', 400);
    }
    const pattern = new RegExp(`^customers/${customerId}/customerNegativeCriteria/[0-9]+$`);
    if (body.resource_names.some(name => typeof name !== 'string' || !pattern.test(name))) {
      return errorResponse('Danh sách IP không thuộc Customer ID đã chọn hoặc sai định dạng.', 400);
    }
    resourceNames = [...new Set(body.resource_names)];
  }

  if (!env.GADS_CLIENT_ID || !env.GADS_CLIENT_SECRET || !env.GADS_DEVELOPER_TOKEN) {
    return errorResponse('Central Service chưa có đủ cấu hình Google Ads.', 503);
  }

  try {
    const tokenResponse = await fetch('https://oauth2.googleapis.com/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        client_id: env.GADS_CLIENT_ID,
        client_secret: env.GADS_CLIENT_SECRET,
        refresh_token: body.refresh_token,
        grant_type: 'refresh_token',
      }),
      signal: AbortSignal.timeout(10000),
    });
    const token = await tokenResponse.json();
    if (!tokenResponse.ok || !token.access_token) {
      return errorResponse('Không thể làm mới kết nối Google Ads. Vui lòng kết nối lại tài khoản.', 401);
    }
    const apiVersion = await env.GADS_KV.get('config:api_version') || 'v25';
    const base = `https://googleads.googleapis.com/${apiVersion}/customers/${customerId}`;
    const headers = {
      Authorization: `Bearer ${token.access_token}`,
      'developer-token': env.GADS_DEVELOPER_TOKEN,
      'Content-Type': 'application/json',
    };
    if (managerId) headers['login-customer-id'] = managerId;

    // Search all batches, and restrict deletion to IP_BLOCK criteria from this account.
    const response = await fetch(`${base}/googleAds:searchStream`, {
      method: 'POST', headers,
      body: JSON.stringify({ query: "SELECT customer_negative_criterion.resource_name, customer_negative_criterion.ip_block.ip_address FROM customer_negative_criterion WHERE customer_negative_criterion.type = 'IP_BLOCK'" }),
      signal: AbortSignal.timeout(10000),
    });
    const batches = await response.json();
    if (!response.ok) return errorResponse(formatGoogleAdsError(Array.isArray(batches) ? batches[0] : batches), response.status);
    if (!Array.isArray(batches)) return errorResponse('Google Ads trả về danh sách IP không hợp lệ.', 502);
    const ips = [];
    for (const batch of batches) {
      if (batch.error || (batch.results !== undefined && !Array.isArray(batch.results))) {
        return errorResponse('Google Ads trả về danh sách IP không hợp lệ.', 502);
      }
      for (const row of batch.results || []) {
        const criterion = row.customerNegativeCriterion;
        if (!criterion?.resourceName || !criterion?.ipBlock?.ipAddress) {
          return errorResponse('Google Ads trả về dữ liệu IP không đầy đủ.', 502);
        }
        ips.push({ resource_name: criterion.resourceName, ip_address: criterion.ipBlock.ipAddress });
      }
    }
    if (action === 'list_ips') return jsonResponse({ success: true, data: { ips } });

    const existing = new Set(ips.map(ip => ip.resource_name));
    if (resourceNames.some(name => !existing.has(name))) {
      return errorResponse('IP đã thay đổi hoặc không còn trong danh sách. Hãy tải lại danh sách trước khi xóa.', 409);
    }
    const removed = await fetch(`${base}/customerNegativeCriteria:mutate`, {
      method: 'POST', headers,
      body: JSON.stringify({ operations: resourceNames.map(remove => ({ remove })), partialFailure: false }),
      signal: AbortSignal.timeout(10000),
    });
    const result = await removed.json();
    if (!removed.ok || result.partialFailureError) {
      return errorResponse(formatGoogleAdsError(result.partialFailureError ? { error: result.partialFailureError } : result), removed.ok ? 502 : removed.status);
    }
    if (!Array.isArray(result.results) || result.results.length !== resourceNames.length) {
      return errorResponse('Chưa xác nhận đủ IP đã xóa. Hãy tải lại danh sách để kiểm tra trước khi thử lại.', 502);
    }
    return jsonResponse({ success: true, data: { deleted: resourceNames.length } });
  } catch {
    // A timed-out mutation may have completed. Never retry it automatically.
    return errorResponse('Không xác nhận được phản hồi Google Ads. Hãy tải lại danh sách để kiểm tra trước khi thử lại.', 502);
  }
}
