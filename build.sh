#!/usr/bin/env bash
#
# Build a clean copy of the plugin under ./build/etchfacets/ from the
# source files in ./src/. Use the build folder as the symlink target in
# your local WP install:
#
#   ln -s /absolute/path/to/EtchFacets/build/etchfacets \
#         /path/to/wp-content/plugins/etchfacets
#
# Re-run this script after changing source files to refresh the build.
# Pass --watch to auto-rebuild on change (requires fswatch).

set -euo pipefail

SLUG="etchfacets"
ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="$ROOT/src"
DEST="$ROOT/build/$SLUG"

build() {
	mkdir -p "$DEST"

	rsync -a --delete --delete-excluded \
		--exclude='.DS_Store' \
		--exclude='._*' \
		--exclude='*.zip' \
		--exclude='node_modules' \
		"$SRC/" "$DEST/"

	echo "Built: $DEST"
}

build

if [[ "${1:-}" == "--watch" ]]; then
	if ! command -v fswatch >/dev/null 2>&1; then
		echo "fswatch not found. Install with: brew install fswatch" >&2
		exit 1
	fi
	echo "Watching for changes in $SRC (Ctrl+C to stop)…"
	fswatch -o \
		--exclude="\\._" \
		"$SRC" | while read -r _; do
		build
	done
fi
