/**
 * GAds Toolkit Central Service — Authentication Middleware
 *
 * Handles both:
 * 1. API Key verification (for plugin clients)
 * 2. Admin token verification (for Dashboard)
 */

import { errorResponse, kvListByPrefix } from './utils.js';

/**
 * Verify API key from request headers or query params.
 * Checks against:
 *   1. Legacy/master key (KV: config:legacy_api_key)
 *   2. Licensed keys (KV: license:{key})
 *
 * @param {Request} request
 * @param {Object} env
 * @returns {Response|null} - Returns error Response if invalid, null if valid
 */
export async function verifyApiKey(request, env) {
  // Extract API key from header or query param
  const url = new URL(request.url);
  let apiKey = request.headers.get('X-API-Key') || '';

  if (!apiKey) {
    apiKey = url.searchParams.get('api_key') || '';
  }

  if (!apiKey) {
    return errorResponse('API key is required. Add X-API-Key header or ?api_key= parameter.', 401);
  }

  // 1. Check legacy/master key
  const legacyKey = await env.GADS_KV.get('config:legacy_api_key');
  if (legacyKey && apiKey === legacyKey) {
    return null; // Valid
  }

  // 2. Check licensed keys
  const licenseData = await env.GADS_KV.get(`license:${apiKey}`);
  if (licenseData) {
    let license;
    try {
      license = JSON.parse(licenseData);
    } catch {
      return errorResponse('Invalid license data. Contact support at phu@pdl.vn', 500);
    }

    // Check active status
    if (!license.active) {
      return errorResponse(
        'License key is inactive. Please contact https://phu.vn to renew your license.',
        403
      );
    }

    // Check expiration
    if (license.expires_at) {
      const expiry = new Date(license.expires_at);
      if (expiry < new Date()) {
        return errorResponse(
          `License key expired on ${license.expires_at}. Please visit https://phu.vn to extend your subscription.`,
          403
        );
      }
    }

    return null; // Valid
  }

  // 3. Invalid key
  console.warn(`Invalid API key attempt: ${apiKey.substring(0, 8)}... from IP: ${request.headers.get('CF-Connecting-IP')}`);
  return errorResponse('Invalid API key. Please verify your key or buy a new license at https://phu.vn', 401);
}

/**
 * Verify admin authentication token.
 *
 * @param {Request} request
 * @param {Object} env
 * @returns {boolean} - True if authenticated
 */
export function verifyAdminToken(request, env) {
  const authHeader = request.headers.get('Authorization') || '';
  if (!authHeader.startsWith('Bearer ')) {
    return false;
  }

  const token = authHeader.slice(7);
  return token === env.ADMIN_TOKEN;
}

/**
 * Check rate limit for an IP address.
 * Uses KV with auto-expiry TTL.
 *
 * @param {Request} request
 * @param {Object} env
 * @returns {Response|null} - Returns error Response if rate limited, null if OK
 */
export async function checkRateLimit(request, env) {
  const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
  const currentHour = new Date().toISOString().slice(0, 13); // e.g., "2026-09-03T13"
  const rateKey = `rate:${ip}:${currentHour}`;

  // Get rate limit config
  const limitStr = await env.GADS_KV.get('config:rate_limit');
  const limit = parseInt(limitStr) || 100;

  // Get current count
  const countStr = await env.GADS_KV.get(rateKey);
  const count = parseInt(countStr) || 0;

  if (count >= limit) {
    return errorResponse('Rate limit exceeded. Please try again later.', 429);
  }

  // Increment counter with 1-hour TTL
  await env.GADS_KV.put(rateKey, String(count + 1), { expirationTtl: 3600 });

  return null; // OK
}
