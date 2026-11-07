#!/usr/bin/env bash
# Builds dist/spoko-content-mcp.zip from the committed HEAD.
# With --publish (on main, in sync with origin) it also creates the GitHub release v<version>
# with that zip; sites pick it up through updater.php.
#
#   bin/release.sh            # build only
#   bin/release.sh --publish  # build and release
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' spoko-content-mcp.php | tr -d '\r')
const=$(sed -n "s/^[[:space:]]*const VERSION = '\(.*\)';.*/\1/p" spoko-content-mcp.php | tr -d '\r')
if [ -z "$version" ] || [ "$version" != "$const" ]; then
	echo "Version header '$version' and VERSION '$const' differ." >&2
	exit 1
fi
if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
	echo "Uncommitted changes; the zip is built from HEAD." >&2
	exit 1
fi

stable=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '\r')
if [ "$stable" != "$version" ]; then
	echo "readme.txt Stable tag '$stable' differs from version '$version'." >&2
	exit 1
fi

mkdir -p dist/wporg
rm -f dist/spoko-content-mcp.zip dist/wporg/spoko-content-mcp.zip
# GitHub build: updates itself from GitHub releases (updater.php).
git archive --format=zip --prefix=spoko-content-mcp/ -o dist/spoko-content-mcp.zip HEAD \
	spoko-content-mcp.php updater.php readme.txt README.md LICENSE
# WordPress.org build: no updater (the directory forbids self-updates), readme.txt as the page.
git archive --format=zip --prefix=spoko-content-mcp/ -o dist/wporg/spoko-content-mcp.zip HEAD \
	spoko-content-mcp.php readme.txt LICENSE
echo "Built dist/spoko-content-mcp.zip and dist/wporg/spoko-content-mcp.zip: $version at $(git rev-parse --short HEAD)"

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
gh release create "v$version" dist/spoko-content-mcp.zip \
	--target "$(git rev-parse HEAD)" --title "$version" --notes "${notes:-Spoko Content MCP $version}"
