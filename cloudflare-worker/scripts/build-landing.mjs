import { mkdir, readFile, writeFile } from 'node:fs/promises';

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

await mkdir(outputDirUrl, { recursive: true });
await writeFile(outputUrl, output);

console.log(`Built landing page for v${APP_VERSION}, updated ${updatedDate}.`);
