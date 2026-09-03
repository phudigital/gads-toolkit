/**
 * GAds Toolkit Central Service — Utility Functions
 *
 * Response helpers, logging, and common utilities.
 */

/**
 * Return a JSON success response
 * @param {Object} data - Response payload
 * @param {number} status - HTTP status code
 * @param {Object} extraHeaders - Additional headers
 * @returns {Response}
 */
export function jsonResponse(data, status = 200, extraHeaders = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-API-Key',
      ...extraHeaders,
    },
  });
}

/**
 * Return a JSON error response
 * @param {string} message - Error message
 * @param {number} status - HTTP status code
 * @returns {Response}
 */
export function errorResponse(message, status = 400) {
  return jsonResponse({ success: false, error: message }, status);
}

/**
 * Return an HTML response
 * @param {string} html - HTML content
 * @param {number} status - HTTP status code
 * @returns {Response}
 */
export function htmlResponse(html, status = 200) {
  return new Response(html, {
    status,
    headers: { 'Content-Type': 'text/html;charset=UTF-8' },
  });
}

/**
 * Handle CORS preflight requests
 * @returns {Response}
 */
export function corsResponse() {
  return new Response(null, {
    status: 204,
    headers: {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-API-Key',
      'Access-Control-Max-Age': '86400',
    },
  });
}

/**
 * Log an activity entry to KV (rolling buffer, max 200 entries)
 * @param {Object} env - Worker environment bindings
 * @param {string} action - Action name (e.g., 'sync_ips')
 * @param {string} client - Client identifier
 * @param {string} result - 'success' or 'error'
 * @param {string} detail - Additional detail
 */
export async function logActivity(env, action, client, result, detail) {
  try {
    const entry = {
      time: new Date().toISOString(),
      action,
      client: client || 'system',
      result,
      detail: detail || '',
    };

    const raw = await env.GADS_KV.get('logs:recent');
    let logs = [];
    if (raw) {
      try {
        logs = JSON.parse(raw);
      } catch {
        logs = [];
      }
    }

    // Prepend new entry, keep max 200
    logs.unshift(entry);
    if (logs.length > 200) {
      logs = logs.slice(0, 200);
    }

    await env.GADS_KV.put('logs:recent', JSON.stringify(logs));
  } catch (e) {
    // Logging should never break the main flow
    console.error('Failed to log activity:', e.message);
  }
}

/**
 * Parse JSON body from request safely
 * @param {Request} request
 * @returns {Object|null}
 */
export async function parseJsonBody(request) {
  try {
    const text = await request.text();
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
}

/**
 * Validate an IP address (IPv4, IPv6, or wildcard x.x.x.*)
 * @param {string} ip
 * @returns {boolean}
 */
export function isValidIp(ip) {
  if (!ip) return false;
  const trimmed = ip.trim();

  // IPv4 wildcard: x.x.x.*
  if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\*$/.test(trimmed)) {
    return true;
  }

  // IPv4: basic validation
  if (/^(\d{1,3}\.){3}\d{1,3}$/.test(trimmed)) {
    return trimmed.split('.').every((n) => parseInt(n) >= 0 && parseInt(n) <= 255);
  }

  // IPv6: basic validation (contains at least 2 colons)
  if (trimmed.includes(':') && /^[0-9a-fA-F:]+$/.test(trimmed)) {
    return true;
  }

  return false;
}

/**
 * Get all KV keys with a given prefix
 * @param {KVNamespace} kv - KV namespace
 * @param {string} prefix - Key prefix
 * @returns {Array<{name: string, metadata: any}>}
 */
export async function kvListByPrefix(kv, prefix) {
  const result = [];
  let cursor = undefined;

  do {
    const list = await kv.list({ prefix, cursor });
    result.push(...list.keys);
    cursor = list.list_complete ? undefined : list.cursor;
  } while (cursor);

  return result;
}
