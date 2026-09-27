#!/usr/bin/env python3
"""Deterministic allowlisted WordPress archive; no tooling, secrets or backups."""
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[2]
source = (root / 'gads-toolkit.php').read_text()
version = re.search(r'\* Version:\s*(\d+\.\d+\.\d+)', source).group(1)
output = root / ('gads-toolkit-' + version + '.zip')
files = [root / 'gads-toolkit.php', root / 'README.md', root / 'CHANGELOG.md']
for directory, extensions in [('includes', {'.php'}), ('assets', {'.js', '.css', '.png', '.jpg', '.svg', '.webp', '.woff', '.woff2'})]:
    files.extend(p for p in (root / directory).rglob('*') if p.is_file() and p.suffix in extensions and not any(part.startswith('.') for part in p.relative_to(root).parts))
for file in files:
    if file.is_symlink():
        raise SystemExit('Symlinks are not allowed in release packages: ' + str(file))
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for file in sorted(files):
        entry = zipfile.ZipInfo('gads-toolkit/' + file.relative_to(root).as_posix(), date_time=(2020, 1, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, file.read_bytes())
print(output)
