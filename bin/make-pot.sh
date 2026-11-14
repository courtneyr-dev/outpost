#!/bin/sh
# Regenerate languages/outpost-mobile-publishing.pot.
#
# POT-Creation-Date is the committer time (UTC) of the last commit that
# changed translatable PHP, so the same source always gives the same file
# (Phase 5b review F5-06). Commit source changes before regenerating.
set -eu
cd "$(dirname "$0")/.."
created=$(TZ=UTC git log -1 --format=%cd --date=format-local:%Y-%m-%dT%H:%M:%S+00:00 -- outpost.php uninstall.php includes admin)
wp i18n make-pot . languages/outpost-mobile-publishing.pot \
	--domain=outpost-mobile-publishing \
	--slug=outpost-mobile-publishing \
	--exclude=build,pwa,src,tests,bin,docs,node_modules,vendor \
	--headers="{\"POT-Creation-Date\":\"$created\"}"
