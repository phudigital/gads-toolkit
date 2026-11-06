import { getClientIndex } from './clients.js';

const BATCH_SIZE = 20;

async function pingSite(siteUrl) {
  const cronUrl = `${siteUrl}/wp-cron.php?doing_wp_cron=${Date.now()}`;
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 5000);
  try {
    const response = await fetch(cronUrl, { signal: controller.signal });
    return { siteUrl, status: response.status, ok: response.ok };
  } finally {
    clearTimeout(timeoutId);
  }
}

export async function handleCron(env) {
  try {
    // One KV read per run (index rebuilt only when missing/expired).
    const clients = (await getClientIndex(env)).filter((c) => c && c.status === 'active' && c.url);

    // Small batches stay under the per-invocation concurrent subrequest limit.
    const results = [];
    for (let i = 0; i < clients.length; i += BATCH_SIZE) {
      const batch = clients.slice(i, i + BATCH_SIZE);
      results.push(...await Promise.allSettled(batch.map((c) => pingSite(c.url))));
    }

    const summary = {
      total: results.length,
      success: results.filter((r) => r.status === 'fulfilled' && r.value.ok).length,
      failed: results.filter((r) => r.status === 'rejected' || !r.value.ok).length,
      details: results.map((r) => (r.status === 'fulfilled' ? r.value : { error: r.reason?.message })),
    };

    console.log('Cron heartbeat summary:', JSON.stringify(summary));
  } catch (error) {
    console.error('Cron heartbeat fatal error:', error);
  }
}
