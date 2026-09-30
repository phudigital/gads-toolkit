import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { handleApiRequest } from '../src/api.js';
const request = (key) => new Request('https://service.test/api?action=validate_license', { headers: key ? { 'X-API-Key': key, 'X-GAds-Site': 'https://licensed.com/', 'X-GAds-Site-Token': 'a'.repeat(64) } : {} });
function env(license) {
  return { GADS_KV: { get: async key => key === 'config:legacy_api_key' ? 'master' : key === 'license:paid' ? license : null, put: async () => {} } };
}
for (const [label, key, record, status] of [
  ['missing', '', null, 401], ['unknown', 'unknown', null, 401], ['master alone', 'master', null, 401],
  ['inactive', 'paid', { active: false }, 403], ['nonboolean active', 'paid', { active: 'true' }, 403],
  ['expired', 'paid', { active: true, expires_at: '2020-01-01' }, 403],
  ['malformed expiry', 'paid', { active: true, expires_at: 'invalid' }, 403],
  ['valid timestamp expiry', 'paid', { active: true, domain: 'licensed.com', expires_at: 4070908800000 }, 200],
  ['expired timestamp', 'paid', { active: true, domain: 'licensed.com', expires_at: 1577836800000 }, 403],
  ['valid perpetual', 'paid', { active: true, domain: 'licensed.com' }, 200],
  ['valid dated', 'paid', { active: true, domain: 'licensed.com', expires_at: '2099-01-01' }, 200],
]) {
  test(label, async t => {
    t.mock.method(globalThis, 'fetch', async (url, options) => {
      assert.equal(options.redirect, 'manual');
      const u = new URL(url);
      if (u.hostname === 'cloudflare-dns.com') return new Response(JSON.stringify({ Status: 0, Answer: u.searchParams.get('type') === 'A' ? [{ type: 1, data: '93.184.216.34' }] : [] }));
      assert.equal(u.hostname, 'licensed.com');
      return new Response(JSON.stringify({ site_url: 'https://licensed.com/', proof: createHmac('sha256', 'a'.repeat(64)).update(u.searchParams.get('challenge')).digest('hex') }));
    });
    const response = await handleApiRequest(request(key), env(record ? JSON.stringify(record) : null));
    assert.equal(response.status, status);
    if (status === 200) {
      const data = (await response.json()).data;
      assert.equal(data.valid, true);
      if (typeof record.expires_at === 'number') assert.equal(data.expires_at, new Date(record.expires_at).toISOString());
    }
  });
}
test('malformed record stays locked', async () => {
  assert.equal((await handleApiRequest(request('paid'), env('{'))).status, 500);
});
test('public health is still public and does not establish a license', async () => {
  const r = await handleApiRequest(new Request('https://service.test/api?action=health'), env(null));
  assert.equal(r.status, 200);
  assert.equal((await r.json()).data, undefined);
});
