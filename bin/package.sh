#!/usr/bin/env bash
#
# Build a distributable plugin zip, excluding dev and build-source files.
# Produces ../stemageddon-lms-{version}.zip with a top-level
# stemageddon-lms/ folder (required by the WordPress plugin installer).
#
# Usage:  bash bin/package.sh
# Requires: zip (or falls back to PowerShell Compress-Archive on Windows).

set -euo pipefail

PLUGIN_SLUG="stemageddon-lms"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$SRC_DIR")"

# Read version from the plugin header.
VERSION="$(grep -m1 -i 'Version:' "$SRC_DIR/$PLUGIN_SLUG.php" | sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/')"
[ -z "$VERSION" ] && VERSION="0.0.0"

OUT_ZIP="$PARENT_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
STAGE="$(mktemp -d)"
DEST="$STAGE/$PLUGIN_SLUG"

mkdir -p "$DEST"

# Copy the plugin, excluding dev/build cruft.
rsync -a \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude '.github' \
	--exclude 'node_modules' \
	--exclude 'vendor' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml.dist' \
	--exclude '.phpcs.cache' \
	--exclude '.phpunit.result.cache' \
	--exclude 'bin' \
	--exclude 'docs' \
	--exclude 'tests' \
	--exclude 'assets/src' \
	--exclude '*.zip' \
	"$SRC_DIR/" "$DEST/"

rm -f "$OUT_ZIP"
( cd "$STAGE" && zip -rq "$OUT_ZIP" "$PLUGIN_SLUG" )
rm -rf "$STAGE"

echo "Built: $OUT_ZIP"
