import test from 'node:test';
import assert from 'node:assert/strict';
import { handleUpdateRequest, UPDATE_BASE, validRelease } from '../src/updates.js';
const sha256 = 'a'.repeat(64);
const release = { version: '4.2.0', sha256, requires: '5.0', requires_php: '7.4', changelog: 'Update', package: `${UPDATE_BASE}/download/4.2.0/${sha256}.zip` };
function env(metadata = release, object = { size: 3, httpEtag: '"etag"', body: 'zip' }) {
  return { GADS_KV: { get: async () => metadata }, GADS_RELEASES: { get: async () => object, head: async () => object } };
}
const call = (path, bindings = env(), init) => handleUpdateRequest(new Request(`${UPDATE_BASE}${path}`, init), bindings);
test('latest manifest, absent release, invalid manifest', async () => {
  assert.deepEqual(await (await call('/latest.json')).json(), release);
  assert.equal((await call('/latest.json', env(null))).status, 404);
  assert.equal((await call('/latest.json', env({ ...release, package: 'https://evil.example/x.zip' }))).status, 503);
  assert.equal(validRelease({ ...release, sha256: '../x' }), false);
});
test('versioned metadata uses a separate key', async () => {
  let key;
  await call('/releases/4.2.0.json', { GADS_KV: { get: async k => { key = k; return release; } } });
  assert.equal(key, 'release:gads-toolkit:version:4.2.0');
});
test('GET streams zip, HEAD has no body, conditional GET returns 304', async () => {
  const path = `/download/4.2.0/${sha256}.zip`;
  const get = await call(path);
  assert.equal(await get.text(), 'zip');
  assert.equal(get.headers.get('content-type'), 'application/zip');
  assert.match(get.headers.get('content-disposition'), /gads-toolkit-4.2.0.zip/);
  const head = await call(path, env(), { method: 'HEAD' });
  assert.equal(await head.text(), '');
  assert.equal(head.headers.get('content-length'), '3');
  assert.equal((await call(path, env(), { headers: { 'If-None-Match': '"etag"' } })).status, 304);
  assert.equal((await call(path, env(release, null))).status, 404);
});
test('write methods and arbitrary object paths are rejected', async () => {
  assert.equal((await call('/latest.json', env(), { method: 'POST' })).status, 405);
  assert.equal((await call('/download/4.2.0/not-a-hash.zip')).status, 404);
  assert.equal((await call('/secrets.json')).status, 404);
});
