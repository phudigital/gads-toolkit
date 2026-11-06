/**
 * Per-isolate caches that keep hot paths off Workers KV writes.
 *
 * KV free tier allows only 1,000 writes/day. Rate counters and ownership-proof
 * caches used to write on almost every plugin request; they now live in isolate
 * memory (and the Cache API for proofs). Limits are best-effort by design.
 */

const CONFIG_TTL_MS = 60 * 1000;
const RATE_WINDOW_MS = 60 * 60 * 1000;
const MAX_ENTRIES = 5000;

const configCache = new Map();
const rateCounters = new Map();
const proofMemory = new Map();

function prune(map, now) {
  if (map.size < MAX_ENTRIES) return;
  for (const [key, entry] of map) {
    if (entry.until <= now) map.delete(key);
  }
  if (map.size >= MAX_ENTRIES) map.clear();
}

/** Read a `config:<name>` KV value, cached in memory for 60 seconds. */
export async function getConfig(env, name) {
  const now = Date.now();
  const cached = configCache.get(name);
  if (cached && cached.until > now) return cached.value;
  const value = await env.GADS_KV.get(`config:${name}`);
  configCache.set(name, { value, until: now + CONFIG_TTL_MS });
  return value;
}

/** Drop cached config in this isolate (other isolates refresh within 60s). */
export function clearConfigCache() {
  configCache.clear();
}

/**
 * Count one request for `id` in the current hour window.
 * @returns {boolean} true when the limit is already reached.
 */
export function isRateLimited(id, limit, now = Date.now()) {
  prune(rateCounters, now);
  const entry = rateCounters.get(id);
  if (!entry || entry.until <= now) {
    rateCounters.set(id, { count: 1, until: now + RATE_WINDOW_MS });
    return false;
  }
  if (entry.count >= limit) return true;
  entry.count++;
  return false;
}

function cacheRequest(origin, key) {
  return new Request(`${origin}/__cache/license-proof/${key}`);
}

function edgeCache() {
  try {
    return globalThis.caches && globalThis.caches.default ? globalThis.caches.default : null;
  } catch {
    return null;
  }
}

/** True when an ownership proof for `key` is still valid (memory, then Cache API). */
export async function hasProof(origin, key, now = Date.now()) {
  const memory = proofMemory.get(key);
  if (memory && memory.until > now) return true;
  const cache = edgeCache();
  if (!cache) return false;
  try {
    const hit = await cache.match(cacheRequest(origin, key));
    if (!hit) return false;
    const until = Number(await hit.text());
    if (!Number.isFinite(until) || until <= now) return false;
    proofMemory.set(key, { until });
    return true;
  } catch {
    return false;
  }
}

/** Remember a verified ownership proof for `ttlSeconds` without touching KV. */
export async function rememberProof(origin, key, ttlSeconds, now = Date.now()) {
  const until = now + ttlSeconds * 1000;
  prune(proofMemory, now);
  proofMemory.set(key, { until });
  const cache = edgeCache();
  if (!cache) return;
  try {
    await cache.put(cacheRequest(origin, key), new Response(String(until), {
      headers: { 'Cache-Control': `max-age=${ttlSeconds}` },
    }));
  } catch {
    // Memory cache still covers this isolate.
  }
}

/** Test helper: start from empty caches. */
export function resetRuntimeCaches() {
  configCache.clear();
  rateCounters.clear();
  proofMemory.clear();
}
