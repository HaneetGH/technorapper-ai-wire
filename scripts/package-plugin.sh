#!/usr/bin/env bash
# Build the WordPress-installable plugin directory. WordPress.org Plugin Check
# reads license from readme.txt at the plugin root; omitting it fails upload.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-"$ROOT/dist/technorapper-ai-wire"}"

rm -rf "$DEST"
mkdir -p "$DEST/includes" "$DEST/assets/fonts"

cp "$ROOT/technorapper-ai-wire.php" "$DEST/"
cp "$ROOT/readme.txt" "$DEST/"
cp "$ROOT/LICENSE" "$DEST/"
cp "$ROOT/README.md" "$DEST/"
cp "$ROOT/includes/"*.php "$DEST/includes/"
cp "$ROOT/assets/index.php" "$DEST/assets/"
cp "$ROOT/assets/fonts/Anton-Regular.ttf" "$ROOT/assets/fonts/OFL.txt" "$ROOT/assets/fonts/index.php" "$DEST/assets/fonts/"

test -f "$DEST/readme.txt"
test -f "$DEST/LICENSE"
grep -q '^License:[[:space:]]*GPLv2 or later' "$DEST/readme.txt"
grep -q 'License: GPLv2 or later' "$DEST/technorapper-ai-wire.php"
