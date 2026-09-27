import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

import { APP_VERSION } from '../src/version.js';

const workerDir = fileURLToPath(new URL('..', import.meta.url));
const rootDir = resolve(workerDir, '..');

const [packageJsonStr, pluginFile, readmeFile, changelogFile, publicLandingFile, docsLandingFile] = await Promise.all([
  readFile(resolve(workerDir, 'package.json'), 'utf8'),
  readFile(resolve(rootDir, 'gads-toolkit.php'), 'utf8'),
  readFile(resolve(rootDir, 'README.md'), 'utf8').catch(() => ''),
  readFile(resolve(rootDir, 'CHANGELOG.md'), 'utf8').catch(() => ''),
  readFile(resolve(workerDir, 'public/index.html'), 'utf8').catch(() => ''),
  readFile(resolve(rootDir, 'docs/index.html'), 'utf8').catch(() => ''),
]);

const packageVersion = JSON.parse(packageJsonStr).version;
const pluginConstVersion = pluginFile.match(/GADS_TOOLKIT_VERSION',\s*'([^']+)'/)?.[1];
const pluginHeaderVersion = pluginFile.match(/\* Version:\s*(\S+)/)?.[1];

const errors = [];

// 1. Plugin internal version check (SSOT)
if (!pluginConstVersion) {
  errors.push('Could not find GADS_TOOLKIT_VERSION constant in gads-toolkit.php.');
}
if (!pluginHeaderVersion) {
  errors.push('Could not find "* Version:" header in gads-toolkit.php.');
}
if (pluginConstVersion && pluginHeaderVersion && pluginConstVersion !== pluginHeaderVersion) {
  errors.push(`Plugin header Version (${pluginHeaderVersion}) does not match GADS_TOOLKIT_VERSION (${pluginConstVersion}).`);
}

const canonicalVersion = pluginConstVersion;

// 2. Worker APP_VERSION check
if (APP_VERSION !== canonicalVersion) {
  errors.push(`Worker APP_VERSION in src/version.js (${APP_VERSION}) does not match plugin version (${canonicalVersion}).`);
}

// 3. Worker package.json check
if (packageVersion !== canonicalVersion) {
  errors.push(`cloudflare-worker/package.json version (${packageVersion}) does not match plugin version (${canonicalVersion}).`);
}

// 4. README badge check
const readmeBadgeMatch = readmeFile.match(/badge\/version-([0-9.]+)-blue\.svg/);
if (readmeBadgeMatch && readmeBadgeMatch[1] !== canonicalVersion) {
  errors.push(`README.md version badge (${readmeBadgeMatch[1]}) does not match plugin version (${canonicalVersion}).`);
}

// 5. CHANGELOG entry check
if (changelogFile && !changelogFile.includes(`## [${canonicalVersion}]`)) {
  errors.push(`CHANGELOG.md is missing an entry for ## [${canonicalVersion}].`);
}

// 6. Landing page checks
if (publicLandingFile) {
  if (publicLandingFile.includes('{{GADS_VERSION}}')) {
    errors.push('cloudflare-worker/public/index.html contains unreplaced {{GADS_VERSION}} placeholder.');
  }
  if (!publicLandingFile.includes(`v${canonicalVersion}`) && !publicLandingFile.includes(`Phiên bản ${canonicalVersion}`)) {
    errors.push(`cloudflare-worker/public/index.html is outdated (does not contain v${canonicalVersion} or "Phiên bản ${canonicalVersion}"). Run "npm run build:landing".`);
  }
} else {
  errors.push('cloudflare-worker/public/index.html is missing. Run "npm run build:landing".');
}

if (docsLandingFile) {
  if (docsLandingFile.includes('{{GADS_VERSION}}')) {
    errors.push('docs/index.html contains unreplaced {{GADS_VERSION}} placeholder.');
  }
}

if (errors.length > 0) {
  console.error('\n❌ Version Check Failed with the following mismatches:');
  for (const err of errors) {
    console.error(`  - ${err}`);
  }
  console.error('\n💡 To resolve this automatically, run:');
  console.error('     npm run sync:version\n');
  process.exit(1);
}

console.log(`✅ Version check passed: All components are strictly synchronized at v${canonicalVersion}.`);
