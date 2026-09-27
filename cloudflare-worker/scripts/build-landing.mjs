import { cp, mkdir, readFile, writeFile } from 'node:fs/promises';

const pluginSource = await readFile(new URL('../../gads-toolkit.php', import.meta.url), 'utf8');
const APP_VERSION = pluginSource.match(/\* Version:\s*(\S+)/)?.[1];
if (!APP_VERSION) throw new Error('Missing plugin version');

const templateUrl = new URL('../../landing-page/index.html', import.meta.url);
const outputDirUrl = new URL('../public/', import.meta.url);
const outputUrl = new URL('../public/index.html', import.meta.url);
const template = await readFile(templateUrl, 'utf8');

const dateParts = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'Asia/Ho_Chi_Minh',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
}).formatToParts(new Date());
const date = Object.fromEntries(
  dateParts
    .filter(({ type }) => type !== 'literal')
    .map(({ type, value }) => [type, value])
);
const updatedDate = `${date.day}/${date.month}/${date.year}`;

const replacements = {
  '{{GADS_VERSION}}': APP_VERSION,
  '{{LANDING_UPDATED_DATE}}': updatedDate,
};

let output = template;
for (const [placeholder, value] of Object.entries(replacements)) {
  if (!output.includes(placeholder)) {
    throw new Error(`Landing page is missing placeholder ${placeholder}.`);
  }
  output = output.replaceAll(placeholder, value);
}

// 1. Build for Cloudflare Worker public
await mkdir(outputDirUrl, { recursive: true });
await writeFile(outputUrl, output);

// Copy assets to Cloudflare Worker public/assets
const landingAssetsDir = new URL('../../landing-page/assets/', import.meta.url);
const workerAssetsDir = new URL('../public/assets/', import.meta.url);
await mkdir(workerAssetsDir, { recursive: true });
await cp(landingAssetsDir, workerAssetsDir, { recursive: true });

// 2. Build for GitHub Pages (docs/)
const docsDirUrl = new URL('../../docs/', import.meta.url);
const docsUrl = new URL('../../docs/index.html', import.meta.url);
const docsAssetsDir = new URL('../../docs/assets/', import.meta.url);
await mkdir(docsDirUrl, { recursive: true });
await writeFile(docsUrl, output);
await mkdir(docsAssetsDir, { recursive: true });
await cp(landingAssetsDir, docsAssetsDir, { recursive: true });

// Copy favicons to public and docs/
const favicons = ['favicon.svg', 'favicon-landing.svg'];
for (const fav of favicons) {
  const src = new URL(`../../landing-page/${fav}`, import.meta.url);
  await cp(src, new URL(`../public/${fav}`, import.meta.url)).catch(() => {});
  await cp(src, new URL(`../../docs/${fav}`, import.meta.url)).catch(() => {});
}

console.log(`Built landing page for v${APP_VERSION}, updated ${updatedDate} (synced to cloudflare-worker/public and docs/).`);
