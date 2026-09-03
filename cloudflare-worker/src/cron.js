export async function handleCron(env) {
  try {
    const prefix = 'client:';
    // 1. List all KV keys with prefix client:
    const listResponse = await env.GADS_KV.list({ prefix });

    // 4. Use Promise.allSettled() for parallel execution
    const clientPromises = listResponse.keys.map(async (key) => {
      const clientDataStr = await env.GADS_KV.get(key.name);
      if (!clientDataStr) return null;

      try {
        const clientData = JSON.parse(clientDataStr);
        if (clientData.status !== 'active') return null;

        // 2. Fetch {client_url}/wp-cron.php with 5s timeout
        const siteUrl = key.name.substring(prefix.length);
        const timestamp = Date.now();
        const cronUrl = `${siteUrl}/wp-cron.php?doing_wp_cron=${timestamp}`;

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);

        try {
          const response = await fetch(cronUrl, { signal: controller.signal });
          clearTimeout(timeoutId);
          return { siteUrl, status: response.status, ok: response.ok };
        } catch (fetchError) {
          clearTimeout(timeoutId);
          throw fetchError;
        }
      } catch (e) {
        throw new Error(`Failed to process client ${key.name}: ${e.message}`);
      }
    });

    const results = await Promise.allSettled(clientPromises);

    // 3. Log results
    const summary = {
      total: results.length,
      success: results.filter(r => r.status === 'fulfilled' && r.value !== null).length,
      failed: results.filter(r => r.status === 'rejected' || (r.status === 'fulfilled' && r.value && r.value.ok === false)).length,
      skipped: results.filter(r => r.status === 'fulfilled' && r.value === null).length,
      details: results.filter(r => r.value !== null).map(r => r.status === 'fulfilled' ? r.value : { error: r.reason?.message })
    };

    console.log('Cron heartbeat summary:', JSON.stringify(summary));

  } catch (error) {
    console.error('Cron heartbeat fatal error:', error);
  }
}
