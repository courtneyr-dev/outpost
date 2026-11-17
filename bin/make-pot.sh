#!/bin/sh
# Regenerate languages/outpost-mobile-publishing.pot.
#
# Scans the PHP and the block-editor sidebar bundle (build/index.js, loaded
# through wp_set_script_translations()). build/pwa stays out: the PWA isn't
# translated through WordPress (T11 F-52).
#
# POT-Creation-Date is the committer time (UTC) of the last commit that
# changed translatable source, so the same source always gives the same file
# (Phase 5b review F5-06). Commit source changes before regenerating.
set -eu
cd "$(dirname "$0")/.."
created=$(TZ=UTC git log -1 --format=%cd --date=format-local:%Y-%m-%dT%H:%M:%S+00:00 -- outpost.php uninstall.php includes admin build/index.js)
wp i18n make-pot . languages/outpost-mobile-publishing.pot \
	--domain=outpost-mobile-publishing \
	--slug=outpost-mobile-publishing \
	--exclude=build/pwa,pwa,src,tests,bin,docs,node_modules,vendor \
	--headers="{\"POT-Creation-Date\":\"$created\"}"
