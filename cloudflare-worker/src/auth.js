/**
 * GAds Toolkit Central Service — Authentication Middleware
 *
 * Handles both:
 * 1. API Key verification (for plugin clients)
 * 2. Admin token verification (for Dashboard)
 */

import { errorResponse, kvListByPrefix } from './utils.js';
import { verifyLicenseDomain } from './license-domain.js';

const LICENSE_ERROR_MESSAGE = 'Khóa API không hợp lệ. Vui lòng gia hạn hoặc mua giấy phép mới tại https://gads.pdl.vn';

/**
 * Verify API key from request headers or query params.
 * Checks against:
 *   1. Legacy/master key (KV: config:legacy_api_key)
 *   2. Licensed keys (KV: license:{key})
 *
 * @param {Request} request
 * @param {Object} env
 * @param {boolean} requireLicense Reject legacy/master-only keys for paid activation.
 * @returns {Response|null} - Returns error Response if invalid, null if valid
 */
export async function verifyApiKey(request, env, requireLicense = false) {
  // Extract API key from header or query param
  const url = new URL(request.url);
  let apiKey = request.headers.get('X-API-Key') || '';

  if (!apiKey) {
    apiKey = url.searchParams.get('api_key') || '';
  }

  if (!apiKey) {
    return errorResponse(LICENSE_ERROR_MESSAGE, 401);
  }

  // 1. Check legacy/master key
  const legacyKey = await env.GADS_KV.get('config:legacy_api_key');
  if (!requireLicense && legacyKey && apiKey === legacyKey) {
    return checkRateLimit(request, env); // Legacy transport compatibility; never paid activation.
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
    if (!license || typeof license !== 'object' || license.active !== true) {
      return errorResponse(LICENSE_ERROR_MESSAGE, 403);
    }

    // Check expiration
    if (license.expires_at) {
      const expiry = new Date(license.expires_at);
      if (!Number.isFinite(expiry.getTime()) || expiry <= new Date()) {
        return errorResponse(LICENSE_ERROR_MESSAGE, 403);
      }
    }

    // Rate-limit before DNS/callback work to bound verification abuse with a copied key.
    const rateLimitError = await checkRateLimit(request, env);
    if (rateLimitError) return rateLimitError;
    return verifyLicenseDomain(request, env, license, apiKey);
  }

  // 3. Invalid key
  console.warn('Invalid API key attempt');
  return errorResponse(LICENSE_ERROR_MESSAGE, 401);
}

/**
 * Verify admin authentication token.
 *
 * @param {Request} request
 * @param {Object} env
 * @returns {Promise<boolean>} True if authenticated
 */
export async function verifyAdminToken(request, env) {
  const authHeader = request.headers.get('Authorization') || '';
  if (!authHeader.startsWith('Bearer ')) {
    return false;
  }

  return verifyAdminTokenValue(authHeader.slice(7), env);
}

/**
 * Verify the effective dashboard token. The Worker secret acts as the initial
 * recovery token; subsequent rotations are stored as a one-way hash in KV.
 * @param {unknown} token
 * @param {Object} env
 * @returns {Promise<boolean>}
 */
export async function verifyAdminTokenValue(token, env) {
  if (typeof token !== 'string' || !token) return false;

  const storedHash = await env.GADS_KV.get('config:admin_token_hash');
  if (storedHash) {
    return timingSafeEqual(await hashAdminToken(token), storedHash);
  }

  return typeof env.ADMIN_TOKEN === 'string' && timingSafeEqual(token, env.ADMIN_TOKEN);
}

/**
 * Hash a high-entropy Admin Token before storing it in KV.
 * @param {string} token
 * @returns {Promise<string>}
 */
export async function hashAdminToken(token) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function timingSafeEqual(left, right) {
  const leftBytes = new TextEncoder().encode(left);
  const rightBytes = new TextEncoder().encode(right);
  if (leftBytes.byteLength !== rightBytes.byteLength) return false;
  return crypto.subtle.timingSafeEqual(leftBytes, rightBytes);
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
