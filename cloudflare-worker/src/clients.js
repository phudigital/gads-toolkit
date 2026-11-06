/**
 * Compact index of registered sites for the heartbeat cron.
 *
 * The cron used to run KV.list + one get per site every 10 minutes. It now reads
 * one key, `clients:index`. The index is rebuilt from `client:*` when missing
 * (after a register/delete invalidates it) or after its 24h TTL.
 */

import { kvListByPrefix } from './utils.js';

const INDEX_KEY = 'clients:index';
const INDEX_TTL_SECONDS = 24 * 60 * 60;
const PREFIX = 'client:';

/** @returns {Promise<Array<{url: string, status: string}>>} */
export async function getClientIndex(env) {
  const raw = await env.GADS_KV.get(INDEX_KEY);
  if (raw) {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) return parsed;
    } catch { /* rebuild below */ }
  }

  const keys = await kvListByPrefix(env.GADS_KV, PREFIX);
  const clients = [];
  for (const key of keys) {
    const value = await env.GADS_KV.get(key.name);
    if (!value) continue;
    try {
      const data = JSON.parse(value);
      clients.push({ url: key.name.slice(PREFIX.length), status: data.status || '' });
    } catch { /* skip malformed client */ }
  }
  await env.GADS_KV.put(INDEX_KEY, JSON.stringify(clients), { expirationTtl: INDEX_TTL_SECONDS });
  return clients;
}

/** Force a rebuild on the next cron run. Never breaks the caller. */
export async function invalidateClientIndex(env) {
  try {
    await env.GADS_KV.delete(INDEX_KEY);
  } catch (e) {
    console.error('Failed to invalidate client index:', e.message);
  }
}
