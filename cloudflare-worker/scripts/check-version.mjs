import { readFile } from 'node:fs/promises';

import { APP_VERSION } from '../src/version.js';

const [packageJson, pluginFile] = await Promise.all([
  readFile(new URL('../package.json', import.meta.url), 'utf8'),
  readFile(new URL('../../gads-toolkit.php', import.meta.url), 'utf8'),
]);

const packageVersion = JSON.parse(packageJson).version;
const pluginVersion = pluginFile.match(/GADS_TOOLKIT_VERSION', '([^']+)'/)?.[1];

if (!pluginVersion) {
  throw new Error('Could not read GADS_TOOLKIT_VERSION from gads-toolkit.php.');
}

if (new Set([APP_VERSION, packageVersion, pluginVersion]).size !== 1) {
  throw new Error(
    `Version mismatch: Worker=${APP_VERSION}, package=${packageVersion}, plugin=${pluginVersion}`
  );
}

console.log(`GAds Toolkit release version ${APP_VERSION} is consistent.`);
