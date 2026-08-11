#!/usr/bin/env bash
#
# Media Mage - release packager.
#
# Produces a clean, installable zip containing exactly the files a user's
# server should receive: no test harness, no packaging metadata, no stale
# build output.
#
# Usage:
#   bash media-mage/build.sh
#
# The zip is written to  <parent-of-plugin-dir>/dist/media-mage-<version>.zip
# and NEVER next to the source. A zip sitting beside the source it was built
# from is how a months-old artifact gets uploaded by mistake, so the build
# output lives somewhere it can be deleted wholesale without touching code.
#
# Re-running overwrites the zip for the same version. Nothing is left behind
# in the plugin directory.
#
# Two packaging paths:
#
#   1. git archive - used when the plugin subtree is committed and clean, and
#      .gitattributes (which carries the export-ignore rules) is tracked. This
#      is the authoritative path: it packages a commit, so what ships is
#      exactly what is in version control.
#
#   2. working-tree copy - used otherwise. Copies the plugin directory through
#      tar, applying the patterns in .distignore. The usual tool here is
#      `rsync --exclude-from`, but rsync is not present in Git Bash on
#      Windows; GNU tar's --exclude-from does the same filtering with tools
#      that are always available.
#
# The script prints which path it took, so a release is never ambiguous about
# whether it came from a commit or from uncommitted edits.

set -euo pipefail

SLUG="media-mage"

# ---------------------------------------------------------------------------
# Resolve paths from the script's own location, so the script works no matter
# what the caller's working directory is.
# ---------------------------------------------------------------------------
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
DIST_DIR="$PARENT_DIR/dist"
MAIN_FILE="$PLUGIN_DIR/$SLUG.php"

[ -f "$MAIN_FILE" ] || { echo "ERROR: $MAIN_FILE not found. Is build.sh in the plugin directory?" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Version.
#
# WordPress reads the "Version:" line in the plugin header, so that is what
# names the zip. WPMJ_VERSION and the readme's Stable tag have to agree with
# it or the plugin reports one version and the directory lists another. A
# mismatch is a warning rather than a hard failure - the build still needs to
# run so the packaging can be tested - but it is printed loudly.
# ---------------------------------------------------------------------------
header_version() {
	sed -n 's/^[[:space:]]*\*\{0,1\}[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -1
}
constant_version() {
	sed -n "s/.*define([[:space:]]*'WPMJ_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" "$MAIN_FILE" | head -1
}
readme_stable_tag() {
	[ -f "$PLUGIN_DIR/readme.txt" ] || return 0
	sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' "$PLUGIN_DIR/readme.txt" | head -1 | tr -d '\r'
}

VERSION="$(header_version)"
CONST_VERSION="$(constant_version)"
STABLE_TAG="$(readme_stable_tag)"

[ -n "$VERSION" ] || { echo "ERROR: could not read Version from the plugin header." >&2; exit 1; }

if [ "$CONST_VERSION" != "$VERSION" ] || [ "$STABLE_TAG" != "$VERSION" ]; then
	echo "WARNING: version strings disagree."
	echo "  plugin header Version : $VERSION   (used to name the zip)"
	echo "  WPMJ_VERSION          : ${CONST_VERSION:-<not found>}"
	echo "  readme.txt Stable tag : ${STABLE_TAG:-<not found>}"
	echo "  Fix these before publishing."
	echo
fi

ZIP_PATH="$DIST_DIR/$SLUG-$VERSION.zip"

# ---------------------------------------------------------------------------
# Work area. Removed on exit whether the build succeeds or fails.
# ---------------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"

# ---------------------------------------------------------------------------
# Decide which packaging path to use.
# ---------------------------------------------------------------------------
use_git_archive=0
git_reason="not a git repository"

if git -C "$PLUGIN_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	REPO_ROOT="$(git -C "$PLUGIN_DIR" rev-parse --show-toplevel)"
	# Path of the plugin directory relative to the repo root, which is what
	# git archive needs as its tree-ish suffix.
	#
	# Asked for directly rather than derived by stripping the repo root off the
	# absolute path: on Windows, git reports C:/xampp/... while the shell holds
	# /c/xampp/..., so the strip silently did nothing and left an absolute path
	# that git archive rejects. That only showed up the first time the tree was
	# clean enough to take this branch at all.
	REL_PATH="$(git -C "$PLUGIN_DIR" rev-parse --show-prefix)"
	REL_PATH="${REL_PATH%/}"

	if [ -n "$(git -C "$PLUGIN_DIR" status --porcelain -- "$PLUGIN_DIR")" ]; then
		git_reason="the plugin directory has uncommitted changes, so a commit is not what you want to ship"
	elif ! git -C "$PLUGIN_DIR" ls-files --error-unmatch "$PLUGIN_DIR/.gitattributes" >/dev/null 2>&1; then
		git_reason="the export-ignore rules in .gitattributes are not committed yet, so git archive would ignore them"
	else
		use_git_archive=1
	fi
fi

# ---------------------------------------------------------------------------
# Stage the files.
# ---------------------------------------------------------------------------
mkdir -p "$STAGE/$SLUG"

if [ "$use_git_archive" -eq 1 ]; then
	echo "Packaging from: git archive (HEAD:$REL_PATH)"
	git -C "$REPO_ROOT" archive --format=tar "HEAD:$REL_PATH" | tar -xf - -C "$STAGE/$SLUG"
else
	echo "Packaging from: working tree, filtered by .distignore"
	echo "  (git archive skipped because $git_reason)"

	[ -f "$PLUGIN_DIR/.distignore" ] || { echo "ERROR: .distignore not found and git archive is unavailable." >&2; exit 1; }

	# GNU tar's --exclude-from takes one raw pattern per line and has no
	# concept of comments, blank lines or trailing slashes. Normalise first.
	# For a directory pattern "test/" we emit both "test" (which stops tar
	# descending into it) and "test/*" (belt and braces).
	PATTERNS="$STAGE/.distignore.patterns"
	: > "$PATTERNS"
	while IFS= read -r line || [ -n "$line" ]; do
		line="${line%$'\r'}"                       # strip CR from CRLF files
		case "$line" in ''|'#'*) continue ;; esac  # skip blanks and comments
		line="${line#/}"                           # leading slash is noise here
		base="${line%/}"                           # trailing slash marks a dir
		printf '%s\n' "$base" >> "$PATTERNS"
		[ "$base" != "$line" ] && printf '%s\n' "$base/*" >> "$PATTERNS"
	done < "$PLUGIN_DIR/.distignore"

	# Copy through a tar pipe: one pass, preserves the tree, applies the
	# excludes, and does not need rsync.
	tar -cf - -C "$PLUGIN_DIR" --exclude-from="$PATTERNS" . \
		| tar -xf - -C "$STAGE/$SLUG"
fi

# ---------------------------------------------------------------------------
# Safety net.
#
# The whole point of this script is that test/ does not ship. If a pattern
# ever stops matching, fail the build rather than quietly produce a zip that
# hands a stranger a database-dropping shell script.
# ---------------------------------------------------------------------------
if [ -e "$STAGE/$SLUG/test" ]; then
	echo "ERROR: test/ made it into the staged build. Refusing to package." >&2
	exit 1
fi
if [ ! -f "$STAGE/$SLUG/readme.txt" ]; then
	echo "ERROR: readme.txt is missing from the staged build. WordPress.org rejects that." >&2
	exit 1
fi
if [ ! -f "$STAGE/$SLUG/$SLUG.php" ]; then
	echo "ERROR: $SLUG.php is missing from the staged build." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# Zip it. The archive root must be a single "media-mage/" directory or
# WordPress installs the plugin into a wrongly named folder.
#
# `zip` is the normal tool. It is absent from Git Bash on Windows, so fall
# back to 7-Zip and then to PowerShell's Compress-Archive.
# ---------------------------------------------------------------------------
zip_with_seven() {
	local exe
	for exe in 7z 7za "/c/Program Files/7-Zip/7z.exe" "/c/Program Files (x86)/7-Zip/7z.exe"; do
		if command -v "$exe" >/dev/null 2>&1 || [ -x "$exe" ]; then
			( cd "$STAGE" && "$exe" a -tzip -bso0 -bsp0 "$ZIP_PATH" "$SLUG" ) && return 0
		fi
	done
	return 1
}

zip_with_powershell() {
	command -v powershell.exe >/dev/null 2>&1 || return 1
	# Compress-Archive wants Windows paths.
	local win_stage win_zip
	win_stage="$(cd "$STAGE" && pwd -W 2>/dev/null || echo "$STAGE")"
	win_zip="$(cd "$DIST_DIR" && pwd -W 2>/dev/null || echo "$DIST_DIR")/$(basename "$ZIP_PATH")"
	powershell.exe -NoProfile -NonInteractive -Command \
		"Compress-Archive -Path '${win_stage}/${SLUG}' -DestinationPath '${win_zip}' -Force" >/dev/null
}

if command -v zip >/dev/null 2>&1; then
	( cd "$STAGE" && zip -rq "$ZIP_PATH" "$SLUG" -x '*.DS_Store' )
	ZIP_TOOL="zip"
elif zip_with_seven; then
	ZIP_TOOL="7-Zip"
elif zip_with_powershell; then
	ZIP_TOOL="PowerShell Compress-Archive"
else
	echo "ERROR: no zip tool found (tried zip, 7z, PowerShell Compress-Archive)." >&2
	exit 1
fi

[ -f "$ZIP_PATH" ] || { echo "ERROR: $ZIP_PATH was not created." >&2; exit 1; }

# ---------------------------------------------------------------------------
# Report.
# ---------------------------------------------------------------------------
FILE_COUNT="$(find "$STAGE/$SLUG" -type f | wc -l | tr -d ' ')"
DIR_COUNT="$(find "$STAGE/$SLUG" -mindepth 1 -type d | wc -l | tr -d ' ')"
ZIP_SIZE="$(du -h "$ZIP_PATH" | cut -f1)"

echo
echo "Built  : $SLUG $VERSION"
echo "Zipped : $ZIP_TOOL"
echo "Path   : $ZIP_PATH"
echo "Files  : $FILE_COUNT file(s) in $DIR_COUNT director(ies), $ZIP_SIZE"
echo
echo "Contents:"
find "$STAGE/$SLUG" -type f | sed "s#^$STAGE/#  #" | sort
echo
echo "Verify with: unzip -l \"$ZIP_PATH\""
