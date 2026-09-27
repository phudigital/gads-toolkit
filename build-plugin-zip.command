#!/bin/zsh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
(cd "$SCRIPT_DIR/cloudflare-worker" && npm run sync:version && npm run check)
python3 "$SCRIPT_DIR/cloudflare-worker/scripts/build-plugin.py"
