#!/bin/sh
# Install this checkout's committed tree into the disposable Studio site, the
# way a release package is built: `git archive HEAD`, filtered through
# .distignore. Uncommitted edits never reach the site.
#
#   sh tests/web-testing/refresh-plugin.sh            # HEAD
#   sh tests/web-testing/refresh-plugin.sh <commit>   # any commit
#
# WT_OUTPOST_SITE overrides the site path (default ~/Studio/outpost-bombadil).
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="${WT_OUTPOST_SITE:-$HOME/Studio/outpost-bombadil}"
DEST="$SITE/wp-content/plugins/outpost-mobile-publishing"
REV="${1:-HEAD}"
[ -d "$SITE/wp-content/plugins" ] || { echo "no WordPress site at $SITE" >&2; exit 2; }

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/outpost-archive.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT
git -C "$ROOT" archive --format=tar "$REV" | tar -x -C "$STAGE"
mkdir -p "$DEST"
rsync -a --delete --delete-excluded --exclude-from="$ROOT/.distignore" "$STAGE/" "$DEST/"
SHA="$(git -C "$ROOT" rev-parse "$REV")"
printf '%s\n' "$SHA" > "$DEST/.wt-installed-commit"
echo "installed $SHA into $DEST ($(grep -m1 "OUTPOST_VERSION'" "$DEST/outpost.php" | sed "s/.*, '\([^']*\)'.*/\1/"))"
