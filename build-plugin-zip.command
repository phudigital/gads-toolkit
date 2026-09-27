#!/bin/zsh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
(cd "$SCRIPT_DIR/cloudflare-worker" && npm run check:version)
python3 "$SCRIPT_DIR/cloudflare-worker/scripts/build-plugin.py"
