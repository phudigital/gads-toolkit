import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { normalizeLicenseDomain, normalizeLicenseSite } from '../src/license-domain.js';
import { handleApiRequest } from '../src/api.js';
const token = 'a'.repeat(64);
function environment(domain = 'licensed.com') {
  const store = new Map([['license:paid', JSON.stringify({ active: true, domain })]]);
  return { GADS_KV: { get: async key => store.get(key) ?? null, put: async (k, v) => store.set(k, v) }, store };
}
function request(site = 'https://licensed.com/', secret = token, action = 'validate_license', body) {
  return new Request(`https://service.com/api?action=${action}`, { method: body ? 'POST' : 'GET', headers: {
    'X-API-Key': 'paid', 'X-GAds-Site': site, 'X-GAds-Site-Token': secret, Origin: 'https://licensed.com',
  }, ...(body ? { body: JSON.stringify(body) } : {}) });
}
function mockOwnership(t, options = {}) {
  const calls = [];
  t.mock.method(globalThis, 'fetch', async (url, init) => {
    calls.push({ url, init });
    const u = new URL(url);
    assert.equal(init.redirect, 'manual');
    assert.equal(init.headers['X-API-Key'], undefined);
    assert.equal(init.headers['X-GAds-Site-Token'], undefined);
    if (u.hostname === 'cloudflare-dns.com') {
      assert.equal(init.headers.Accept, 'application/dns-json');
      return new Response(JSON.stringify({ Status: 0, Answer: u.searchParams.get('type') === 'A'
        ? [{ type: 1, data: options.ip || '93.184.216.34' }]
        : options.ipv6 ? [{ type: 28, data: options.ipv6 }] : [] }));
    }
    assert.ok(['licensed.com', 'www.licensed.com'].includes(u.hostname), 'Only approved host can be contacted');
    assert.equal(u.searchParams.get('rest_route'), '/gads-toolkit/v1/license-proof');
    if (options.timeout) throw new DOMException('Timeout', 'AbortError');
    if (options.redirect) return new Response(null, { status: 302, headers: { Location: 'https://evil.com' } });
    if (options.large) return new Response('x'.repeat(17000));
    const proof = createHmac('sha256', options.token || token).update(u.searchParams.get('challenge')).digest('hex');
    return new Response(JSON.stringify({ site_url: options.replySite || `${u.origin}${u.pathname}`, proof: options.replay || proof }));
  });
  return calls;
}
test('domain normalization is exact with www alias; no wildcard, path, private literal or local hosts', () => {
  assert.equal(normalizeLicenseDomain('https://WWW.Licensed.COM/'), 'licensed.com');
  for (const domain of ['', '*.licensed.com', 'licensed.com.evil.com/path', 'http://127.0.0.1', 'http://[::1]', 'localhost', 'x.local', 'licensed.com:8080', 'user@licensed.com', 'licensed.com?x']) assert.equal(normalizeLicenseDomain(domain), null, domain);
  assert.equal(normalizeLicenseSite('https://licensed.com/shop'), 'https://licensed.com/shop/');
  for (const site of ['http://licensed.com', 'https://licensed.com:8443', 'https://licensed.com/?next=evil', 'https://licensed.com/%2e%2e/']) assert.equal(normalizeLicenseSite(site), null);
});
test('copied key and spoofed Origin cannot authorize a different site', async t => {
  const calls = mockOwnership(t);
  for (const site of ['https://evil.com/', 'https://sub.licensed.com/', 'https://licensed.com.evil.com/', 'https://127.0.0.1/', 'http://licensed.com/']) {
    assert.equal((await handleApiRequest(request(site), environment())).status, 403);
  }
  assert.equal(calls.length, 0);
});
test('matching hostname without its installation token is rejected', async t => {
  const calls = mockOwnership(t, { token: 'b'.repeat(64) });
  assert.equal((await handleApiRequest(request(), environment())).status, 403);
  assert.equal(calls.length, 3);
});
test('correct installation verifies and caches for all protected API actions', async t => {
  const calls = mockOwnership(t); const env = environment();
  const response = await handleApiRequest(request(), env);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).data.site_url, 'https://licensed.com/');
  assert.equal(calls.length, 3);
  assert.equal((await handleApiRequest(request('https://licensed.com/', token, 'get_credentials'), env)).status, 200);
  assert.equal(calls.length, 3, 'Ownership cache avoids repeat callbacks');
  const record = JSON.parse(env.store.get('license:paid')); record.active = false;
  env.store.set('license:paid', JSON.stringify(record));
  assert.equal((await handleApiRequest(request(), env)).status, 403, 'Cache cannot bypass revocation');
});
test('www alias and subdirectory retain the correct callback path', async t => {
  const calls = mockOwnership(t);
  assert.equal((await handleApiRequest(request('https://www.licensed.com/shop/'), environment())).status, 200);
  assert.equal(new URL(calls[2].url).pathname, '/shop/');
});
for (const [name, options] of [
  ['redirect', { redirect: true }], ['timeout', { timeout: true }], ['oversized reply', { large: true }],
  ['loopback DNS', { ip: '127.0.0.1' }], ['private DNS', { ip: '10.0.0.1' }], ['metadata DNS', { ip: '169.254.169.254' }],
  ['private IPv6', { ipv6: 'fd00::1' }], ['loopback IPv6', { ipv6: '::1' }],
]) test(`${name} fails closed`, async t => {
  const calls = mockOwnership(t, options); const env = environment();
  assert.equal((await handleApiRequest(request(), env)).status, 503);
  assert.equal([...env.store.keys()].some(k => k.startsWith('license-proof:')), false);
  if (options.ip || options.ipv6) assert.ok(calls.every(c => new URL(c.url).hostname === 'cloudflare-dns.com'));
});
test('wrong canonical site and stale/replayed challenge proof cannot pass', async t => {
  mockOwnership(t, { replySite: 'https://evil.com/' });
  assert.equal((await handleApiRequest(request(), environment())).status, 403);
});
test('a previously captured HMAC does not answer a fresh challenge', async t => {
  mockOwnership(t, { replay: createHmac('sha256', token).update('0'.repeat(64)).digest('hex') });
  assert.equal((await handleApiRequest(request(), environment())).status, 403);
});
test('license without domain and missing site token are rejected without callback', async t => {
  const calls = mockOwnership(t);
  assert.equal((await handleApiRequest(request(), environment(''))).status, 403);
  assert.equal((await handleApiRequest(request('https://licensed.com/', ''), environment())).status, 403);
  assert.equal(calls.length, 0);
});
test('changing domain or token invalidates cached ownership', async t => {
  const calls = mockOwnership(t); const env = environment();
  assert.equal((await handleApiRequest(request(), env)).status, 200);
  assert.equal((await handleApiRequest(request('https://licensed.com/', 'b'.repeat(64)), env)).status, 403);
  env.store.set('license:paid', JSON.stringify({ active: true, domain: 'other.com' }));
  assert.equal((await handleApiRequest(request(), env)).status, 403);
  assert.equal(calls.length, 6);
});
test('registration cannot register another site after ownership verification', async t => {
  mockOwnership(t);
  assert.equal((await handleApiRequest(request('https://licensed.com/', token, 'register_site', { site_url: 'http://127.0.0.1' }), environment())).status, 403);
});
for (const action of ['list_ips', 'remove_ips', 'sync_ips', 'exchange_code', 'register_site', 'get_credentials']) test(`${action} requires domain verification before its business handler`, async t => {
  const calls = mockOwnership(t);
  const r = await handleApiRequest(request('https://evil.com/', token, action, { customer_id: '1234567890' }), environment());
  assert.equal(r.status, 403); assert.equal(calls.length, 0);
});

test('rate limit rejects before any ownership callback', async t => {
  const calls = mockOwnership(t); const env = environment();
  env.GADS_KV.get = async key => key.startsWith('rate:') ? '100' : env.store.get(key) ?? null;
  assert.equal((await handleApiRequest(request(), env)).status, 429);
  assert.equal(calls.length, 0);
});
test('expired ownership cache requires another fresh challenge', async t => {
  const calls = mockOwnership(t); const env = environment();
  assert.equal((await handleApiRequest(request(), env)).status, 200);
  for (const key of env.store.keys()) if (key.startsWith('license-proof:')) env.store.set(key, String(Date.now() - 1));
  assert.equal((await handleApiRequest(request(), env)).status, 200);
  assert.equal(calls.length, 6);
  assert.notEqual(new URL(calls[2].url).searchParams.get('challenge'), new URL(calls[5].url).searchParams.get('challenge'));
});
