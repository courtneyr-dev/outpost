#!/usr/bin/env bash
#
# Run the integration suite for any Outpost checkout or worktree.
#
# `npm run test:integration` only runs the checkout wp-env mounts. This
# script borrows a running wp-env environment's test WordPress, companion
# plugins, PHP image and MySQL server, mounts the checkout you name as the
# plugin, and runs PHPUnit in a one-off container.
#
# Usage:
#   bin/itest-worktree.sh [--env <wp-env id>] [--db <name>] [<checkout>] [-- <phpunit args>]
#
#   <checkout>  Path to the Outpost checkout to test. Default: this script's repo.
#   --env       wp-env environment id, e.g. wp-env-outpost-d4b948c2 (the
#               folder name under ~/.wp-env). Default: the one running
#               Outpost environment; the script stops if it finds none or
#               several.
#   --db        Test database name ([A-Za-z0-9_], up to 64 characters). Default:
#               outpost_itest_<checkout folder>. Each checkout gets its own
#               database, so a run never wipes another checkout's tables or
#               the environment's own tests-wordpress database.
#   MOCK_URL    WireMock URL as the container sees it.
#               Default: http://host.docker.internal:8080
#
# Example:
#   bin/itest-worktree.sh ~/Developer/outpost-hardening -- --filter TelegraphRequestGateTest
#
# Needs: Docker, a running Outpost wp-env (`npx wp-env start` in any
# checkout), `composer install` in the checkout, and the WireMock sidecar
# (`cd tests/mock-server && docker compose up -d`).

set -euo pipefail

die() {
	printf 'itest-worktree: %s\n' "$1" >&2
	exit 1
}

script_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_id=''
db=''
checkout=''
phpunit_args=()

while [ "$#" -gt 0 ]; do
	case "$1" in
		--env)
			[ "$#" -ge 2 ] || die '--env needs a value'
			env_id="$2"
			shift 2
			;;
		--db)
			[ "$#" -ge 2 ] || die '--db needs a value'
			db="$2"
			shift 2
			;;
		--)
			shift
			phpunit_args=("$@")
			break
			;;
		-h | --help)
			sed -n '2,32p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		-*)
			die "unknown option $1 (pass PHPUnit options after --)"
			;;
		*)
			[ -z "$checkout" ] || die "one checkout only; got $checkout and $1"
			checkout="$1"
			shift
			;;
	esac
done

checkout="$(cd "${checkout:-$script_repo}" && pwd)" || die 'checkout not found'
[ -f "$checkout/outpost.php" ] || die "$checkout has no outpost.php"
[ -x "$checkout/vendor/bin/phpunit" ] || die "$checkout has no vendor/bin/phpunit; run composer install there"

# Find the environment: a running tests-mysql container whose wp-env
# folder carries the IndieAuth and Micropub companions Outpost needs.
if [ -z "$env_id" ]; then
	candidates=()
	while IFS= read -r project; do
		if [ -d "$HOME/.wp-env/$project/indieauth" ] && [ -d "$HOME/.wp-env/$project/micropub" ]; then
			candidates+=("$project")
		fi
	done < <(docker ps --filter 'label=com.docker.compose.service=tests-mysql' \
		--format '{{.Label "com.docker.compose.project"}}' | sort -u)
	case "${#candidates[@]}" in
		0) die "no running Outpost wp-env found; run 'npx wp-env start' in any Outpost checkout" ;;
		1) env_id="${candidates[0]}" ;;
		*) die "several running Outpost wp-envs (${candidates[*]}); pick one with --env" ;;
	esac
fi

env_dir="$HOME/.wp-env/$env_id"
[ -d "$env_dir/tests-WordPress" ] || die "$env_dir/tests-WordPress not found; is $env_id a wp-env id?"

mysql_container="$(docker ps --filter "label=com.docker.compose.project=$env_id" \
	--filter 'label=com.docker.compose.service=tests-mysql' --format '{{.Names}}' | head -n 1)"
[ -n "$mysql_container" ] || die "$env_id has no running tests-mysql container"

if [ -z "$db" ]; then
	db="outpost_itest_$(basename "$checkout" | tr -c 'A-Za-z0-9_\n' '_')"
fi
printf '%s' "$db" | grep -Eq '^[A-Za-z0-9_]{1,64}$' || die "bad --db name: $db"

# wp-env's tests-mysql is MariaDB, whose image has `mariadb`, not `mysql`.
# The name was checked above, so it needs no quoting in the SQL.
# shellcheck disable=SC2016 # $1 expands inside the container's shell.
docker exec "$mysql_container" sh -c \
	'client="$(command -v mariadb || command -v mysql)" && "$client" -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS $1"' \
	sh "$db" 2>/dev/null ||
	die "could not create database $db in $mysql_container"

mock_url="${MOCK_URL:-http://host.docker.internal:8080}"
if ! curl -sf -o /dev/null --max-time 3 http://localhost:8080/__admin/health; then
	printf 'itest-worktree: WireMock is not answering on :8080; mock-backed tests will fail.\n' >&2
	printf '  Start it: (cd tests/mock-server && docker compose up -d)\n' >&2
fi

printf 'itest-worktree: %s on %s, database %s\n' "$checkout" "$env_id" "$db" >&2

plugin_dir=/var/www/html/wp-content/plugins/outpost
exec docker run --rm \
	--user "$(id -u):$(id -g)" \
	--network "${env_id}_default" \
	-e WORDPRESS_DB_NAME="$db" \
	-e WORDPRESS_DB_HOST=tests-mysql \
	-e WORDPRESS_DB_USER=root \
	-e WORDPRESS_DB_PASSWORD=password \
	-e WP_TESTS_DIR=/wordpress-phpunit \
	-e HOME=/tmp \
	-e OUTPOST_TEST_MOCK_SERVER_URL="$mock_url" \
	-v "$env_dir/tests-WordPress:/var/www/html" \
	-v "$env_dir/tests-WordPress-PHPUnit/tests/phpunit:/wordpress-phpunit" \
	-v "$env_dir/indieauth:/var/www/html/wp-content/plugins/indieauth" \
	-v "$env_dir/micropub:/var/www/html/wp-content/plugins/micropub" \
	-v "$checkout:$plugin_dir" \
	-w "$plugin_dir" \
	"${env_id}-tests-cli" \
	php vendor/bin/phpunit --bootstrap=tests/integration/bootstrap.php --testsuite=integration \
	${phpunit_args[@]+"${phpunit_args[@]}"}
