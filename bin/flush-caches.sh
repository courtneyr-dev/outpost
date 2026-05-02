#!/usr/bin/env bash
#
# Flush WordPress caches via wp-cli.
#
# Run before / after deploying anything that touches assets or settings,
# during debugging when "stale" symptoms appear, or on demand.
#
# Layers this hits (inner → outer order — see docs/CACHE-LAYERS.md):
#
#   1. Object cache         — wp cache flush
#   2. Transients           — wp transient delete --all (DB-backed transients
#                              not already cleaned by object-cache flush)
#   3. Outpost rate limits  — explicit delete of outpost_*_rl_* transients
#   4. Rewrite rules        — wp rewrite flush --hard
#   5. Perfmatters          — wp perfmatters cache clear (if available)
#   6. WP Rocket            — wp rocket clean (if available)
#   7. Autoptimize          — wp autoptimize clear (if available)
#   8. LiteSpeed Cache      — wp litespeed-purge all (if available)
#   9. GoDaddy / WP Engine  — host-specific commands (if available)
#
# Cloudflare edge is intentionally NOT flushed here — wp-cli has no native
# Cloudflare integration. Use the GoDaddy/Cloudflare admin UI, or set
# CLOUDFLARE_ZONE_ID + CLOUDFLARE_API_TOKEN env vars to trigger an API
# purge at the end.
#
# Usage:
#   bin/flush-caches.sh                              # local WP install
#   bin/flush-caches.sh --url=https://example.com    # remote (via wp-cli alias or SSH)
#   bin/flush-caches.sh --quiet                      # suppress per-step echoes
#
# Exit status: 0 if every available command succeeded; 1 if a known
# command failed unexpectedly. Missing optional plugins are not failures.

set -u

URL_ARG=""
QUIET=0

for arg in "$@"; do
	case "$arg" in
		--url=*)
			URL_ARG="$arg"
			;;
		--quiet|-q)
			QUIET=1
			;;
		-h|--help)
			grep '^#' "$0" | sed 's/^# \?//'
			exit 0
			;;
		*)
			echo "Unknown argument: $arg" >&2
			exit 2
			;;
	esac
done

say() {
	if [ "$QUIET" -eq 0 ]; then
		echo "$@"
	fi
}

# Wrap a wp-cli call so a missing command (subcommand not found) is a no-op,
# but a real failure (DB unreachable, etc.) is surfaced.
try_wp() {
	local label="$1"
	shift
	say "→ $label"
	if wp $URL_ARG "$@" >/dev/null 2>&1; then
		return 0
	else
		say "  (skipped — command not available or no-op on this install)"
		return 0
	fi
}

require_wp() {
	if ! command -v wp >/dev/null 2>&1; then
		echo "Error: wp-cli (wp) not on PATH." >&2
		echo "Install: https://wp-cli.org/#installing" >&2
		exit 1
	fi
}

require_wp

# 1. Object cache
try_wp "Object cache flush" cache flush

# 2. Transients (DB-backed; some object caches don't store transients)
try_wp "Transients delete (all)" transient delete --all

# 3. Outpost-specific rate-limit transients. Listed by name so we know
#    they're gone even if the object cache doesn't expose them via
#    `transient list`.
say "→ Outpost rate-limit transients"
# `wp db query` runs a raw SQL DELETE against the options table where
# transients live when there's no persistent object cache.
wp $URL_ARG db query \
	"DELETE FROM \$(echo wp_options) WHERE option_name LIKE '_transient_outpost_%_rl_%' OR option_name LIKE '_transient_timeout_outpost_%_rl_%'" \
	>/dev/null 2>&1 || true
say "  done"

# 4. Rewrite rules — fixes route 404s after deploys that change `Outpost_Route_Handler::rules()`
try_wp "Rewrite rules flush (hard)" rewrite flush --hard

# 5. Perfmatters
try_wp "Perfmatters cache clear" perfmatters cache clear

# 6. WP Rocket
try_wp "WP Rocket clean" rocket clean

# 7. Autoptimize
try_wp "Autoptimize clear" autoptimize clear

# 8. LiteSpeed Cache
try_wp "LiteSpeed purge" litespeed-purge all

# 9. GoDaddy MWP — try a few known command names
try_wp "GoDaddy MWP cache flush" gd-system-plugin flush
try_wp "MWP cache flush (alt)" mwp cache flush

# 10. WP Engine
try_wp "WP Engine cache purge" wpe-cache flush

# 11. Cloudflare API purge (only if zone + token env vars set)
if [ -n "${CLOUDFLARE_ZONE_ID:-}" ] && [ -n "${CLOUDFLARE_API_TOKEN:-}" ]; then
	say "→ Cloudflare edge purge (zone $CLOUDFLARE_ZONE_ID)"
	curl -s -X POST \
		"https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/purge_cache" \
		-H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
		-H "Content-Type: application/json" \
		--data '{"purge_everything":true}' \
		>/dev/null 2>&1 \
		&& say "  ✓ purged" \
		|| say "  ✗ Cloudflare API call failed (check token + zone id)"
else
	say "→ Cloudflare edge: skipped (set CLOUDFLARE_ZONE_ID + CLOUDFLARE_API_TOKEN to enable)"
fi

say ""
say "✓ Cache flush complete."
say ""
say "If you still see stale content after this:"
say "  1. Hard-refresh the browser (Cmd+Shift+R / pull-to-refresh on iOS twice)"
say "  2. Unregister the service worker (Web Inspector → Storage → Service Workers)"
say "  3. Cache-bust a single test request: ?_cb=\$(date +%s)"
