#!/usr/bin/env bash
# Builds dist/polo-blocks-mcp.zip from the committed HEAD.
# With --publish (on main, in sync with origin) it also creates the GitHub release v<version>
# with that zip; blog.polo.blue picks it up through updater.php.
#
#   bin/release.sh            # build only
#   bin/release.sh --publish  # build and release
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' polo-blocks-mcp.php | tr -d '\r')
const=$(sed -n "s/^[[:space:]]*const VERSION = '\(.*\)';.*/\1/p" polo-blocks-mcp.php | tr -d '\r')
if [ -z "$version" ] || [ "$version" != "$const" ]; then
	echo "Version header '$version' and VERSION '$const' differ." >&2
	exit 1
fi
if ! git diff --quiet HEAD; then
	echo "Uncommitted changes; the zip is built from HEAD." >&2
	exit 1
fi

mkdir -p dist
rm -f dist/polo-blocks-mcp.zip
git archive --format=zip --prefix=polo-blocks-mcp/ -o dist/polo-blocks-mcp.zip HEAD \
	polo-blocks-mcp.php updater.php README.md
echo "Built dist/polo-blocks-mcp.zip: $version at $(git rev-parse --short HEAD)"

[ "${1:-}" = "--publish" ] || exit 0

if [ "$(git rev-parse --abbrev-ref HEAD)" != "main" ]; then
	echo "Publish from main." >&2
	exit 1
fi
git fetch -q origin main
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
	echo "Local main differs from origin/main." >&2
	exit 1
fi
if gh release view "v$version" >/dev/null 2>&1; then
	echo "Release v$version already exists. Bump the version first." >&2
	exit 1
fi

# Release notes: this version's entry from the README changelog.
notes=$(awk -v v="$version" '
	index($0, "- **" v "**") == 1 { on = 1; print; next }
	on && /^- \*\*/ { exit }
	on && /^$/ { exit }
	on { print }
' README.md)
gh release create "v$version" dist/polo-blocks-mcp.zip \
	--target "$(git rev-parse HEAD)" --title "$version" --notes "${notes:-Polo Blocks MCP $version}"
