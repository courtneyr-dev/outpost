#!/bin/sh
# Show that each spec file catches a controlled violation and passes once it is
# removed. For each check: reset the site, plant one fault in the site's
# installed copy of Outpost (never in this checkout), run the test that should
# catch it, reset (which reinstalls HEAD), and run the same test again.
#
#   sh tests/web-testing/prove-faults.sh [journeys|a11y|visual|network|pwa ...]
#
# Evidence: tests/web-testing/output/proofs/<stamp>/<check>/{faulted,restored}/
# (Playwright JSON and traces) and proof.json beside them.
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="${WT_OUTPOST_SITE:-$HOME/Studio/outpost-bombadil}"
PLUGIN="$SITE/wp-content/plugins/outpost-mobile-publishing"
OUT="$ROOT/tests/web-testing/output/proofs/$(date -u +%Y-%m-%dT%H-%M-%SZ)"
mkdir -p "$OUT"

bundle() { ls "$PLUGIN"/build/pwa/assets/index-*.js; }

# replace FILE OLD NEW: replaces every occurrence; none found means the fault is not planted.
replace() {
	n=$(grep -o -F -- "$2" "$1" | wc -l | tr -d ' ')
	[ "$n" -ge 1 ] || { echo "fault not planted: '$2' not in $1" >&2; exit 2; }
	OLD="$2" NEW="$3" perl -0pi -e 's/\Q$ENV{OLD}\E/$ENV{NEW}/g' "$1"
}

plant() {
	case "$1" in
	# About › Help links to a destination that isn't the documented one.
	journeys) replace "$(bundle)" 'outpost/troubleshooting/' 'outpost/troubleshoot/' ;;
	# The Preferences gear loses its accessible name (axe button-name).
	a11y) replace "$(bundle)" '{class:"outpost-visually-hidden",children:"Preferences"}' '{class:"outpost-visually-hidden",children:""}' ;;
	# Home's cards drop from three columns to two at 40rem and up (brief section 6).
	# (A token-file color edit doesn't reach the page: under Outpost Default the
	# resolver's scoped CSS supplies paint and outranks styles/outpost-tokens.css.)
	visual) replace "$(ls "$PLUGIN"/build/pwa/assets/index-*.css)" '.outpost-home__grid{grid-template-columns:repeat(3,minmax(0,1fr))' '.outpost-home__grid{grid-template-columns:repeat(2,minmax(0,1fr))' ;;
	# A network failure no longer tells the person the post was saved for later.
	network) replace "$(bundle)" "Saved for later. Outpost will post this when you're back online." 'Done.' ;;
	# The worker stops precaching the shell.
	pwa) replace "$PLUGIN/includes/class-pwa-shell.php" '	SHELL_URL,' '' ;;
	esac
}

target() {
	case "$1" in
	journeys) echo 'desktop|About shows runtime connection facts' ;;
	a11y) echo 'desktop|@a11y About$' ;;
	visual) echo 'desktop|Home in Light' ;;
	network) echo 'network|a network failure queues the post' ;;
	pwa) echo 'pwa|precaches the documented shell' ;;
	esac
}

run_one() { # check phase
	t="$(target "$1")"
	project="${t%%|*}"
	grep="${t#*|}"
	dir="$OUT/$1/$2"
	mkdir -p "$dir"
	set +e
	WT_RUN_DIR="$dir" npx -y -p node@24 npx playwright test -c "$ROOT/tests/web-testing/playwright.config.ts" --project "$project" --grep "$grep" > "$dir/output.log" 2>&1
	code=$?
	set -e
	echo "$code"
}

checks="${*:-journeys a11y visual network pwa}"
printf '[' > "$OUT/proof.json"
sep=''
for c in $checks; do
	sh "$ROOT/tests/web-testing/reset.sh" > /dev/null
	plant "$c"
	faulted=$(run_one "$c" faulted)
	sh "$ROOT/tests/web-testing/reset.sh" > /dev/null
	restored=$(run_one "$c" restored)
	proved=no
	[ "$faulted" -ne 0 ] && [ "$restored" -eq 0 ] && proved=yes
	echo "$c: faulted exit $faulted, restored exit $restored, proved $proved"
	printf '%s{"check":"%s","test":"%s","faultedExit":%s,"restoredExit":%s,"proved":"%s","evidence":"%s"}' "$sep" "$c" "$(target "$c")" "$faulted" "$restored" "$proved" "$OUT/$c" >> "$OUT/proof.json"
	sep=','
done
printf ']\n' >> "$OUT/proof.json"
echo "proof: $OUT/proof.json"
