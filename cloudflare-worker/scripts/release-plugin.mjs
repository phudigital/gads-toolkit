/** Build by default; explicit --publish uploads, verifies, then announces stable. */
import { readFileSync, writeFileSync, mkdirSync, openSync, closeSync, unlinkSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { UPDATE_BASE, RELEASE_KEY, validRelease } from '../src/updates.js';

const worker = fileURLToPath(new URL('..', import.meta.url));
const root = resolve(worker, '..');
const args = process.argv.slice(2);
if (args.some(arg => arg !== '--publish')) throw new Error('Usage: npm run release:plugin -- [--publish]');
const publish = args.includes('--publish');
const output = resolve(worker, '.release');
mkdirSync(output, { recursive: true });
const lock = resolve(output, 'publish.lock');
const handle = openSync(lock, 'wx');
const run = (command, argv, options = {}) => execFileSync(command, argv, { cwd: worker, stdio: 'inherit', ...options });
const wrangler = argv => run(process.execPath, [resolve(worker, 'node_modules/wrangler/bin/wrangler.js'), ...argv]);
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const compare = (a, b) => {
  const aa = a.split('.').map(Number), bb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) if (aa[i] !== bb[i]) return aa[i] > bb[i] ? 1 : -1;
  return 0;
};
async function remoteJson(url) {
  const res = await fetch(url, { signal: AbortSignal.timeout(30000), cache: 'no-store' });
  if (res.status === 404) {
    const data = await res.json();
    if (data.error === 'No published release') return null;
  }
  if (!res.ok) throw new Error(`Release API unavailable: ${res.status} ${url}`);
  const data = await res.json();
  if (!validRelease(data)) throw new Error('Invalid remote manifest');
  return data;
}
try {
  run(process.execPath, ['scripts/sync-version.mjs']);
  run(process.execPath, ['scripts/check-version.mjs']);
  const main = readFileSync(resolve(root, 'gads-toolkit.php'), 'utf8');
  const header = name => main.match(new RegExp(`\\* ${name}:\\s*([^\\r\\n]+)`))?.[1].trim();
  const version = header('Version');
  if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error('Stable version must use x.y.z');
  const changelog = readFileSync(resolve(root, 'CHANGELOG.md'), 'utf8');
  const section = changelog.split(`## [${version}]`)[1]?.split('\n## [')[0]?.trim();
  if (!section) throw new Error(`Missing CHANGELOG entry for ${version}`);
  run('python3', ['scripts/build-plugin.py']);
  const zip = resolve(root, `gads-toolkit-${version}.zip`);
  const bytes = readFileSync(zip), sha256 = hash(bytes);
  const manifest = {
    name: 'GAds Toolkit', slug: 'gads-toolkit', version,
    requires: header('Requires at least'), requires_php: header('Requires PHP'),
    package: `${UPDATE_BASE}/download/${version}/${sha256}.zip`,
    sha256, size: bytes.length, changelog: section,
  };
  if (!validRelease(manifest)) throw new Error('Invalid release metadata');
  const manifestFile = resolve(output, `${version}.json`);
  writeFileSync(manifestFile, JSON.stringify(manifest, null, 2) + '\n');
  console.log(`Prepared ${version}: ${bytes.length} bytes; SHA-256 ${sha256}`);
  if (publish) {
    // Local durable receipt also guards retries while KV is still propagating.
    const receipt = resolve(output, `published-${version}.json`);
    if (existsSync(receipt) && readFileSync(receipt, 'utf8') !== JSON.stringify(manifest)) {
      throw new Error('This version was already uploaded with different contents. Bump plugin version.');
    }
    // Single release operator. Per-version record prevents accidental republishing.
    const existing = await remoteJson(`${UPDATE_BASE}/releases/${version}.json`);
    const stable = await remoteJson(`${UPDATE_BASE}/latest.json`);
    if (stable && compare(stable.version, version) > 0) throw new Error('Refusing to downgrade stable');
    if (existing && JSON.stringify(existing) !== JSON.stringify(manifest)) throw new Error('Version already published with different contents. Bump plugin version.');
    if (stable?.version === version && stable.sha256 !== sha256) throw new Error('Stable version has different contents. Bump plugin version.');
    const config = readFileSync(resolve(worker, 'wrangler.toml'), 'utf8');
    const bucket = config.match(/binding\s*=\s*"GADS_RELEASES"\s*\n\s*bucket_name\s*=\s*"([^"]+)"/)?.[1];
    if (!bucket) throw new Error('Missing GADS_RELEASES bucket in wrangler.toml');
    if (!existing) wrangler(['r2', 'object', 'put', `${bucket}/gads-toolkit/${version}/${sha256}.zip`, '--file', zip, '--remote', '--content-type', 'application/zip']);
    const download = await fetch(manifest.package, { signal: AbortSignal.timeout(120000) });
    if (!download.ok || hash(Buffer.from(await download.arrayBuffer())) !== sha256) throw new Error('Public package verification failed; stable not changed');
    writeFileSync(receipt, JSON.stringify(manifest));
    if (!existing) wrangler(['kv', 'key', 'put', `release:gads-toolkit:version:${version}`, '--binding', 'GADS_KV', '--path', manifestFile, '--remote']);
    wrangler(['kv', 'key', 'put', RELEASE_KEY, '--binding', 'GADS_KV', '--path', manifestFile, '--remote']);
    console.log(`Published ${version} to R2 and KV.`);

    // Automatically deploy the Cloudflare Worker and its static assets so gads.pdl.vn is in 100% sync
    console.log(`\n🚀 Deploying Worker & Landing Page Assets to Cloudflare (gads.pdl.vn)...`);
    wrangler(['deploy']);
    console.log(`✓ gads.pdl.vn successfully deployed with v${version} assets.`);
    console.log(`\n✨ Successfully published and deployed ${version}! Verify: ${UPDATE_BASE}/latest.json and https://gads.pdl.vn/\n`);
  } else {
    console.log('Build only. Publish with: npm run release:plugin -- --publish');
  }
} finally {
  closeSync(handle);
  unlinkSync(lock);
}
