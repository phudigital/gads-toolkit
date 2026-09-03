#!/bin/zsh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="$(basename "$SCRIPT_DIR")"
PLUGINS_DIR="$(dirname "$SCRIPT_DIR")"
MAIN_FILE="$SCRIPT_DIR/gads-toolkit.php"
WORKER_DIR="$SCRIPT_DIR/cloudflare-worker"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "ERROR: Khong tim thay file chinh: $MAIN_FILE"
  exit 1
fi

if [[ ! -f "$WORKER_DIR/package.json" ]]; then
  echo "ERROR: Khong tim thay Cloudflare Worker: $WORKER_DIR"
  exit 1
fi

(cd "$WORKER_DIR" && npm run check:version)

VERSION="$(sed -n "s/.*GADS_TOOLKIT_VERSION', '\([^']*\)'.*/\1/p" "$MAIN_FILE" | head -n 1)"
if [[ -z "$VERSION" ]]; then
  VERSION="$(sed -n 's/.*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -n 1)"
fi
if [[ -z "$VERSION" ]]; then
  VERSION="$(date +%Y%m%d%H%M%S)"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$SCRIPT_DIR/$ZIP_NAME"

echo "Building WordPress plugin zip..."
echo "Plugin: $PLUGIN_SLUG"
echo "Version: $VERSION"
echo "Output: $ZIP_PATH"
echo ""

cd "$PLUGINS_DIR"
rm -f "$ZIP_PATH"

/usr/bin/zip -r "$ZIP_PATH" "$PLUGIN_SLUG" \
  -x "$PLUGIN_SLUG/.git/*" \
     "$PLUGIN_SLUG/.wrangler/*" \
     "$PLUGIN_SLUG/cloudflare-worker/*" \
     "$PLUGIN_SLUG/node_modules/*" \
     "$PLUGIN_SLUG/vendor/*" \
     "$PLUGIN_SLUG/central-service/*" \
     "$PLUGIN_SLUG/help/*" \
     "$PLUGIN_SLUG/*.zip" \
     "$PLUGIN_SLUG/implementation_plan.md" \
     "$PLUGIN_SLUG/*.bak" \
     "$PLUGIN_SLUG/**/*.bak" \
     "$PLUGIN_SLUG/*.backup" \
     "$PLUGIN_SLUG/**/*.backup" \
     "$PLUGIN_SLUG/*.old" \
     "$PLUGIN_SLUG/**/*.old" \
     "$PLUGIN_SLUG/.DS_Store" \
     "$PLUGIN_SLUG/**/.DS_Store" \
     "$PLUGIN_SLUG/*.command"

echo ""
echo "Done."
echo "Created: $ZIP_PATH"
echo ""
echo "Ban co the upload file zip nay trong WordPress Admin > Plugins > Add New > Upload Plugin."

if [[ -t 0 ]]; then
  echo "Nhan phim bat ky de dong cua so..."
  read -k 1
fi
