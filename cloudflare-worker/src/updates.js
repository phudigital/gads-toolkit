/** Read-only public release API. Publication uses authenticated Wrangler, never HTTP writes. */
export const RELEASE_KEY = 'release:gads-toolkit:stable';
export const UPDATE_BASE = 'https://gads.pdl.vn/updates/gads-toolkit';
const versionPattern = /^\d+\.\d+\.\d+$/;
const shaPattern = /^[a-f0-9]{64}$/;

export function validRelease(data) {
  return data && versionPattern.test(data.version) && shaPattern.test(data.sha256) &&
    typeof data.changelog === 'string' && data.changelog.length <= 30000 &&
    /^\d+\.\d+(?:\.\d+)?$/.test(data.requires) &&
    /^\d+\.\d+(?:\.\d+)?$/.test(data.requires_php) &&
    data.package === `${UPDATE_BASE}/download/${data.version}/${data.sha256}.zip`;
}

function json(data, status = 200) {
  return Response.json(data, { status, headers: { 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' } });
}

export async function handleUpdateRequest(request, env) {
  if (!['GET', 'HEAD'].includes(request.method)) {
    return new Response(null, { status: 405, headers: { Allow: 'GET, HEAD' } });
  }
  const path = new URL(request.url).pathname;
  const prefix = '/updates/gads-toolkit';
  const releaseMatch = path.match(/^\/updates\/gads-toolkit\/releases\/(\d+\.\d+\.\d+)\.json$/);
  if (path === `${prefix}/latest.json` || releaseMatch) {
    const key = releaseMatch ? `release:gads-toolkit:version:${releaseMatch[1]}` : RELEASE_KEY;
    const data = await env.GADS_KV.get(key, 'json');
    if (!data) return json({ error: 'No published release' }, 404);
    if (!validRelease(data)) return json({ error: 'Invalid release metadata' }, 503);
    return request.method === 'HEAD' ? new Response(null, { headers: { 'Cache-Control': 'no-store', 'Content-Type': 'application/json' } }) : json(data);
  }
  const download = path.match(/^\/updates\/gads-toolkit\/download\/(\d+\.\d+\.\d+)\/([a-f0-9]{64})\.zip$/);
  if (!download) return json({ error: 'Not found' }, 404);
  const [, version, sha256] = download;
  const key = `gads-toolkit/${version}/${sha256}.zip`;
  const object = request.method === 'HEAD' ? await env.GADS_RELEASES.head(key) : await env.GADS_RELEASES.get(key);
  if (!object) return json({ error: 'Package not found' }, 404);
  const headers = new Headers({
    'Content-Type': 'application/zip', 'Content-Length': String(object.size),
    'Content-Disposition': `attachment; filename="gads-toolkit-${version}.zip"`,
    'Cache-Control': 'public, max-age=31536000, immutable',
    'X-Content-Type-Options': 'nosniff', ETag: object.httpEtag,
  });
  if (request.headers.get('If-None-Match') === object.httpEtag) return new Response(null, { status: 304, headers });
  return new Response(request.method === 'HEAD' ? null : object.body, { headers });
}
