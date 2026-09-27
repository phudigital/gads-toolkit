import test from 'node:test';
import assert from 'node:assert/strict';
import { handleApiRequest } from '../src/api.js';

const rn = 'customers/1234567890/customerNegativeCriteria/123';
const input = { customer_id: '123-456-7890', refresh_token: 'test-refresh' };
const batch = [{ results: [{ customerNegativeCriterion: { resourceName: rn, ipBlock: { ipAddress: '203.0.113.1' } } }] }];
function environment() {
  return { GADS_CLIENT_ID: 'test-client', GADS_CLIENT_SECRET: 'test-secret', GADS_DEVELOPER_TOKEN: 'test-developer', GADS_KV: {
    get: async key => key === 'config:legacy_api_key' ? 'test-key' : null,
    put: async () => {},
  } };
}
function request(action, body = input, key = 'test-key') {
  return new Request(`https://example.test/api?action=${action}`, { method: 'POST', headers: { 'X-API-Key': key }, body: JSON.stringify(body) });
}
function mockFetch(t, replies) {
  const calls = [];
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    calls.push({ url, options });
    const reply = replies.shift();
    assert.ok(reply, 'unexpected upstream request');
    if (reply instanceof Error) throw reply;
    return new Response(JSON.stringify(reply.body), { status: reply.status || 200 });
  });
  return calls;
}
test('list uses service secrets, normalizes customer, returns all stream batches without MCC header', async t => {
  const calls = mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: [...batch, ...batch] }]);
  const response = await handleApiRequest(request('list_ips'), environment());
  assert.equal(response.status, 200);
  assert.equal((await response.json()).data.ips.length, 2);
  assert.match(calls[1].url, /customers\/1234567890\/googleAds:searchStream$/);
  assert.equal(calls[1].options.headers['developer-token'], 'test-developer');
  assert.equal(calls[1].options.headers['login-customer-id'], undefined);
  assert.match(JSON.parse(calls[1].options.body).query, /IP_BLOCK/);
});
test('MCC is optional and normalized when supplied; an empty stream is valid', async t => {
  const calls = mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: [] }]);
  const result = await handleApiRequest(request('list_ips', { ...input, manager_id: '987-654-3210' }), environment());
  assert.deepEqual((await result.json()).data.ips, []);
  assert.equal(calls[1].options.headers['login-customer-id'], '9876543210');
});
test('license validation still gates the new endpoints', async t => {
  const calls = mockFetch(t, []);
  assert.equal((await handleApiRequest(request('list_ips', input, ''), environment())).status, 401);
  assert.equal(calls.length, 0);
});
test('wrong-account resource names are rejected before Google is called', async t => {
  const calls = mockFetch(t, []);
  const response = await handleApiRequest(request('remove_ips', { ...input, resource_names: ['customers/9999999999/customerNegativeCriteria/123'] }), environment());
  assert.equal(response.status, 400);
  assert.equal(calls.length, 0);
});
test('removal checks IP membership, deduplicates and disables partial failure', async t => {
  const calls = mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: batch }, { body: { results: [{ resourceName: rn }] } }]);
  const response = await handleApiRequest(request('remove_ips', { ...input, resource_names: [rn, rn] }), environment());
  assert.deepEqual((await response.json()).data, { deleted: 1 });
  assert.deepEqual(JSON.parse(calls[2].options.body), { operations: [{ remove: rn }], partialFailure: false });
});
test('non-IP or stale criteria cannot be removed', async t => {
  const calls = mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: [] }]);
  assert.equal((await handleApiRequest(request('remove_ips', { ...input, resource_names: [rn] }), environment())).status, 409);
  assert.equal(calls.length, 2);
});
test('malformed lists fail instead of reporting a false empty list', async t => {
  mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: {} }]);
  assert.equal((await handleApiRequest(request('list_ips'), environment())).status, 502);
});
test('upstream permission failure reaches the client', async t => {
  mockFetch(t, [{ body: { access_token: 'test-access' } }, { status: 403, body: { error: { message: 'permission denied' } } }]);
  const response = await handleApiRequest(request('list_ips'), environment());
  assert.equal(response.status, 403);
  assert.match((await response.json()).error, /permission denied/);
});
test('an ambiguous delete is not retried', async t => {
  const calls = mockFetch(t, [{ body: { access_token: 'test-access' } }, { body: batch }, new Error('timeout')]);
  const response = await handleApiRequest(request('remove_ips', { ...input, resource_names: [rn] }), environment());
  assert.equal(response.status, 502);
  assert.match((await response.json()).error, /tải lại danh sách/);
  assert.equal(calls.length, 3);
});
