import { errorResponse } from './utils.js';

// One public DNS hostname per license. www and bare-host are the only aliases.
export function normalizeLicenseDomain(value) {
  if (typeof value !== 'string' || !value.trim() || value.length > 300) return null;
  try {
    const url = new URL(value.includes('://') ? value.trim() : `https://${value.trim()}`);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.port ||
        url.search || url.hash || !['', '/'].includes(url.pathname)) return null;
    const host = url.hostname.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
    if (!/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/.test(host) ||
        /(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/.test(host)) return null;
    return host;
  } catch { return null; }
}

export function normalizeLicenseSite(value) {
  if (typeof value !== 'string' || value.length > 2048 || /%|\\/.test(value)) return null;
  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password || url.port || url.search || url.hash ||
        !normalizeLicenseDomain(url.origin) || /%|\\/.test(url.pathname)) return null;
    url.pathname = `${url.pathname.replace(/\/+$/, '')}/`;
    return url.href;
  } catch { return null; }
}

async function digest(value) {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('');
}
async function verifyProof(token, challenge, value) {
  if (typeof value !== 'string' || !/^[a-f0-9]{64}$/.test(value)) return false;
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(token), { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
  const signature = Uint8Array.from(value.match(/../g), pair => parseInt(pair, 16));
  return crypto.subtle.verify('HMAC', key, signature, new TextEncoder().encode(challenge));
}

// Bound both response size and wall time; never follow redirects or forward secrets.
async function fetchJson(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 3000);
  try {
    const response = await fetch(url, { redirect: 'manual', signal: controller.signal, headers: { Accept: url.startsWith('https://cloudflare-dns.com/') ? 'application/dns-json' : 'application/json' } });
    if (response.status !== 200 || !response.body) throw new Error('Invalid verification response');
    const reader = response.body.getReader();
    let size = 0;
    const chunks = [];
    try {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > 16384) throw new Error('Verification response too large');
        chunks.push(value);
      }
    } finally { await reader.cancel(); }
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder().decode(bytes));
  } finally { clearTimeout(timer); }
}

function publicAddress(value, type) {
  if (type === 1) {
    const p = value.split('.').map(Number);
    if (p.length !== 4 || p.some(n => !Number.isInteger(n) || n < 0 || n > 255)) return false;
    const [a, b, c] = p;
    return !(a === 0 || a === 10 || a === 127 || a >= 224 || (a === 100 && b >= 64 && b <= 127) ||
      (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) ||
      (a === 192 && (b === 168 || b === 0 || (b === 2 && c === 0))) ||
      (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) || (a === 203 && b === 0 && c === 113));
  }
  // Only global-unicast IPv6. Reject mapped, ULA, link-local, loopback, multicast and transition ranges.
  try {
    const address = new URL(`https://[${value}]/`).hostname.slice(1, -1);
    return /^[23][0-9a-f]{3}:/i.test(address) && !/^(?:2001:(?::|0:|db8:|2:|10:|20:)|2002:|3fff:)/i.test(address);
  } catch { return false; }
}
async function verifyPublicDns(host) {
  let addresses = 0;
  for (const type of ['A', 'AAAA']) {
    const data = await fetchJson(`https://cloudflare-dns.com/dns-query?name=${encodeURIComponent(host)}&type=${type}`);
    if (data.Status !== 0 || (data.Answer !== undefined && !Array.isArray(data.Answer))) throw new Error('Invalid DNS response');
    for (const answer of data.Answer || []) {
      if (answer.type !== 1 && answer.type !== 28) continue;
      if (typeof answer.data !== 'string' || !publicAddress(answer.data, answer.type)) throw new Error('Non-public address');
      addresses++;
    }
  }
  if (!addresses) throw new Error('No public address');
}

export async function verifyLicenseDomain(request, env, license, apiKey) {
  const domain = normalizeLicenseDomain(license.domain);
  const site = normalizeLicenseSite(request.headers.get('X-GAds-Site'));
  const token = request.headers.get('X-GAds-Site-Token') || '';
  if (!domain) return errorResponse('License chưa được gán domain hợp lệ. Vui lòng liên hệ Phú Digital.', 403);
  if (!site || normalizeLicenseDomain(new URL(site).origin) !== domain) {
    return errorResponse('License Key không được cấp cho domain website này.', 403);
  }
  if (!/^[a-f0-9]{64}$/.test(token)) return errorResponse('Thiếu mã xác minh cài đặt. Vui lòng cập nhật GAds Toolkit.', 403);
  // Domain, key, installation token and site path all participate: edits invalidate ownership cache.
  const cacheKey = `license-proof:${await digest(JSON.stringify([apiKey, domain, site, token]))}`;
  const cached = await env.GADS_KV.get(cacheKey);
  const now = Date.now();
  const until = Number(cached);
  if (Number.isFinite(until) && until > now && until <= now + 300000) return null;
  try {
    await verifyPublicDns(new URL(site).hostname);
    const challenge = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    const callback = new URL(site);
    callback.searchParams.set('rest_route', '/gads-toolkit/v1/license-proof');
    callback.searchParams.set('challenge', challenge);
    const result = await fetchJson(callback.href);
    if (!result || normalizeLicenseSite(result.site_url) !== site || !await verifyProof(token, challenge, result.proof)) {
      return errorResponse('Không xác minh được cài đặt GAds Toolkit trên domain được cấp phép.', 403);
    }
    await env.GADS_KV.put(cacheKey, String(now + 300000), { expirationTtl: 300 });
    return null;
  } catch {
    return errorResponse('Không thể xác minh domain qua HTTPS. Kiểm tra DNS, SSL và endpoint REST của website rồi thử lại.', 503);
  }
}
