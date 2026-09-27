import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const workerDir = fileURLToPath(new URL('..', import.meta.url));
const rootDir = resolve(workerDir, '..');

// 1. Read SSOT version from gads-toolkit.php
const pluginPath = resolve(rootDir, 'gads-toolkit.php');
const pluginContent = await readFile(pluginPath, 'utf8');

const pluginVersion = pluginContent.match(/define\('GADS_TOOLKIT_VERSION',\s*'([^']+)'\)/)?.[1]
  || pluginContent.match(/\* Version:\s*(\S+)/)?.[1];

if (!pluginVersion || !/^\d+\.\d+\.\d+$/.test(pluginVersion)) {
  throw new Error(`Invalid or missing plugin version in ${pluginPath}: found "${pluginVersion}"`);
}

console.log(`\n🔄 Syncing codebase to version ${pluginVersion} (SSOT from gads-toolkit.php)...`);

// 2. Sync cloudflare-worker/package.json
const packageJsonPath = resolve(workerDir, 'package.json');
const packageJson = JSON.parse(await readFile(packageJsonPath, 'utf8'));
if (packageJson.version !== pluginVersion) {
  packageJson.version = pluginVersion;
  await writeFile(packageJsonPath, JSON.stringify(packageJson, null, 2) + '\n');
  console.log(`  ✓ Updated cloudflare-worker/package.json -> ${pluginVersion}`);
} else {
  console.log(`  ✓ cloudflare-worker/package.json already ${pluginVersion}`);
}

// 3. Sync cloudflare-worker/src/version.js
const versionJsPath = resolve(workerDir, 'src/version.js');
const versionJsContent = `// Update this value for every GAds Toolkit product release.\nexport const APP_VERSION = '${pluginVersion}';\n`;
await writeFile(versionJsPath, versionJsContent);
console.log(`  ✓ Updated cloudflare-worker/src/version.js -> ${pluginVersion}`);

// 4. Sync cloudflare-worker/src/index.js docblock version
const indexJsPath = resolve(workerDir, 'src/index.js');
let indexJsContent = await readFile(indexJsPath, 'utf8');
if (indexJsContent.includes('* @version')) {
  indexJsContent = indexJsContent.replace(/\* @version\s+[0-9.]+/g, `* @version ${pluginVersion}`);
  await writeFile(indexJsPath, indexJsContent);
  console.log(`  ✓ Updated cloudflare-worker/src/index.js docblock -> ${pluginVersion}`);
}

// 5. Sync README.md version badge
const readmePath = resolve(rootDir, 'README.md');
let readmeContent = await readFile(readmePath, 'utf8');
const updatedReadme = readmeContent.replace(
  /badge\/version-[0-9.]+-blue\.svg/g,
  `badge/version-${pluginVersion}-blue.svg`
);
if (updatedReadme !== readmeContent) {
  await writeFile(readmePath, updatedReadme);
  console.log(`  ✓ Updated README.md version badge -> ${pluginVersion}`);
} else {
  console.log(`  ✓ README.md version badge already ${pluginVersion}`);
}

// 6. Build Landing Page & Docs (public/index.html & docs/index.html)
console.log(`  ⚡ Rebuilding landing page and docs...`);
execFileSync(process.execPath, [resolve(workerDir, 'scripts/build-landing.mjs')], { stdio: 'inherit' });

console.log(`\n🎉 All components successfully synchronized to v${pluginVersion}!\n`);
