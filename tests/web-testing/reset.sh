#!/bin/sh
# Reset the disposable Studio site to its seeded state before a check.
#
#   sh tests/web-testing/reset.sh          restore the seed snapshot, reinstall
#                                          this checkout's HEAD, mint sessions
#   sh tests/web-testing/reset.sh --seed   create the seed snapshot (run once,
#                                          on a site you are willing to reset)
#   sh tests/web-testing/reset.sh --mint   only mint fresh sessions
#
# The snapshot lives outside the repository. Sessions (IndieAuth tokens and
# wp-admin cookies, valid for an hour) go to git-ignored files:
#   tests/web-testing/.auth/sessions.json   editor, subscriber, expired
#   tests/bombadil/.auth/session.json       editor { token, me }, for spec.ts
#
# WT_OUTPOST_SITE overrides the site path; WT_OUTPOST_SEED the snapshot path.
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="${WT_OUTPOST_SITE:-$HOME/Studio/outpost-bombadil}"
SEED="${WT_OUTPOST_SEED:-$HOME/.local/share/web-testing/cache/outpost-seed.sqlite}"
DB="$SITE/wp-content/database/.ht.sqlite"
AUTH="$ROOT/tests/web-testing/.auth"

wp() { (cd "$SITE" && studio wp "$@"); }

mint() {
	mkdir -p "$AUTH" "$ROOT/tests/bombadil/.auth"
	out="$(wp eval-file "$ROOT/tests/web-testing/mint-sessions.php")"
	json="$(printf '%s' "$out" | sed -n 's/^[^{]*\({.*}\)[^}]*$/\1/p' | tail -n 1)"
	[ -n "$json" ] || { echo "reset: minting sessions failed" >&2; exit 1; }
	umask 077
	printf '%s\n' "$json" > "$AUTH/sessions.json"
	printf '%s' "$json" | node -e 'let s="";process.stdin.on("data",(c)=>s+=c).on("end",()=>{const e=JSON.parse(s).editor;process.stdout.write(JSON.stringify({token:e.token,me:e.me}))})' > "$ROOT/tests/bombadil/.auth/session.json"
}

[ -f "$DB" ] || { echo "reset: no SQLite database at $DB" >&2; exit 2; }

if [ "${1:-}" = "--mint" ]; then
	mint
	exit 0
fi

if [ "${1:-}" = "--seed" ]; then
	sh "$ROOT/tests/web-testing/refresh-plugin.sh"
	wp rewrite structure '/%year%/%monthnum%/%day%/%postname%/' --hard > /dev/null
	mint > /dev/null
	mkdir -p "$(dirname "$SEED")"
	sqlite3 "$DB" ".backup '$SEED'"
	echo "seeded $SEED ($(wp user list --field=user_login | tr '\n' ' '))"
	exit 0
fi

[ -f "$SEED" ] || { echo "reset: no seed snapshot at $SEED; run reset.sh --seed first" >&2; exit 2; }
sqlite3 "$DB" ".restore '$SEED'"
sh "$ROOT/tests/web-testing/refresh-plugin.sh" > /dev/null
mint
echo "reset: restored $SEED, installed $(cat "$SITE/wp-content/plugins/outpost-mobile-publishing/.wt-installed-commit"), minted sessions"
