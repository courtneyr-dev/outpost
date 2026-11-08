# Integration testing

Integration tests exercise Outpost against a real WordPress install instead of WP_Mock stubs. This catches issues unit tests can't reach: rewrite-rule registration through `WP_Rewrite`, REST endpoint behavior through `WP_REST_Server`, capability cascades, hook ordering against WordPress's built-in handlers.

Outpost uses [@wordpress/env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for the test environment — the same Docker-based setup the WordPress core team uses for Gutenberg and core PHPUnit.

## Prerequisites

- **Docker Desktop** running. macOS: `brew install --cask docker` and launch the app once. The npm scripts will fail with a helpful error if Docker isn't running.
- `npm install` already done (installs `@wordpress/env` per `package.json` devDependencies).

The first `wp-env start` pulls a few hundred MB of Docker images (PHP, MySQL, WordPress) and takes a few minutes. Subsequent starts are seconds.

## Configuration

`.wp-env.json` at the repo root configures:

- **WordPress core:** `6.7`, pinned in `.wp-env.json`. This is the version the integration suite runs against, not the newest version Outpost supports: `readme.txt` declares `Tested up to: 7.1`.
- **Plugins installed:**
  - `.` — Outpost itself, mapped from the working tree
  - IndieAuth (from WordPress.org) — required dependency per the IndieAuth-first chain
  - Micropub (from WordPress.org) — required dependency
- **Debug:** `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` all on; `WP_DEBUG_DISPLAY` off so debug output goes to the log instead of clobbering response bodies.
- **Fatal-error handler disabled** so genuine fatal errors surface during tests rather than getting swallowed by WordPress's recovery mode.

A separate `tests` env config inherits these defaults — wp-env runs two parallel WP installs (one for development at `localhost:8888`, one for tests at `localhost:8889`).

## Running tests

```bash
# Start the containers (one-time per session)
npm run wp-env:start

# Run the integration suite inside the tests-cli container
npm run test:integration

# Stop containers when done
npm run wp-env:stop

# Reset everything (Docker volumes too) if state gets weird
npm run wp-env:clean
```

The `test:integration` npm script expands to:

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/outpost composer test:integration
```

Which runs `composer test:integration` inside the wp-env tests-cli container, with the working directory set to the Outpost plugin folder. PHPUnit picks up `tests/integration/bootstrap.php` (configured in `composer.json`'s `test:integration` script), which loads the WP core test suite from `/wordpress-phpunit` and registers Outpost on `muplugins_loaded`.

## Status

The `test-integration` job in `.github/workflows/ci.yml` runs `npm run test:integration` on every push and pull request against `main`; see [CI integration](#ci-integration) below. Not every test in `tests/integration/` runs its assertions yet: see [What's still skipped](#whats-still-skipped).

Integration tests extend `PHPUnit\Framework\TestCase`, not `WP_UnitTestCase`. The bootstrap loads real WordPress, so WordPress functions, the database, and `WP_REST_Server` are available inside a plain PHPUnit test case.

## Two PHPUnit bootstraps

PHPUnit 9.6 doesn't support per-testsuite bootstraps in `phpunit.xml.dist`. Outpost solves this via Composer scripts:

| Suite | Bootstrap | Loads |
|-------|-----------|-------|
| `unit` | `tests/bootstrap.php` (default from `phpunit.xml.dist`) | WP_Mock stubs + Outpost classes |
| `integration` | `tests/integration/bootstrap.php` (overridden in `composer.json`'s `test:integration` script) | Real WP via the WP core test suite |

`phpunit.xml.dist` sets no default suite, so `composer test` runs both the `unit` and `integration` suites under the unit bootstrap; integration tests that need real WordPress skip there. `composer test:unit` runs only the unit suite, which is what CI's PHPUnit job runs. `composer test:integration` overrides the bootstrap for the integration suite. The `--bootstrap=` flag on the PHPUnit command line takes precedence over `phpunit.xml.dist`'s `bootstrap` attribute.

## Why @wordpress/env over `bin/install-wp-tests.sh`

The legacy approach (still common in older plugins): a shell script that downloads WP, sets up MySQL, copies test config. Brittle (assumes specific MySQL setup), polluting (writes to the host filesystem), and slow to maintain across PHP/WordPress version matrices.

@wordpress/env wraps Docker so:
- Each PHP/WP combination is a clean container, not a host-mutating install
- `core` and `plugins` are configurable per-project in `.wp-env.json`
- The WordPress core team uses it, so it stays current with core test-suite changes
- CI integration is straightforward (Docker is available on GitHub-hosted runners)

## Troubleshooting

**`npm run wp-env:start` hangs at "Pulling images"**: first run pulls ~500 MB of images; be patient. Re-runs use cached images and start in seconds.

**Tests fail with "WP test suite not found"**: the integration bootstrap couldn't find `/wordpress-phpunit/includes/functions.php`. This usually means the test wasn't run inside the tests-cli container. Always invoke via `npm run test:integration`, not directly via `composer test:integration` from outside the container.

**"This site can't be reached" at localhost:8888**: containers stopped. Run `npm run wp-env:start` again.

**Docker is using too much memory/disk**: `npm run wp-env:clean` removes all containers, volumes, and images. Next `start` pulls fresh.

**Container state is weird after a code change**: most code changes (PHP, JS) reflect immediately because the working tree is mounted. Schema-level changes (`.wp-env.json` updates, plugin install/remove) require `npm run wp-env:stop && npm run wp-env:start`.

## Local development site

`npm run wp-env:start` also brings up a development site at `localhost:8888` with Outpost, IndieAuth, and Micropub active. Open `http://localhost:8888/post/` to use the composer against your working tree. After you change `pwa/src/`, run `npm run build` (or `npm run dev` for the Vite dev server) so the site serves your change.

## CI integration

The `test-integration` job in `.github/workflows/ci.yml` runs the integration suite on every push + PR against `main`. The job:

1. Sets up Node 24 and PHP 8.2 with Composer and npm caches.
2. Brings up the WireMock sidecar from `tests/mock-server/docker-compose.yml` on the runner host (port 8080 — *not* 8888, since wp-env already binds 8888 for its dev install and 8889 for tests).
3. Polls WireMock's `/__admin/health` endpoint with a 120s timeout before proceeding.
4. Starts wp-env (`npm run wp-env:start`).
5. Runs `npm run test:integration` with no extra environment variables. `tests/integration/bootstrap.php` defines `OUTPOST_TEST_MOCK_SERVER_URL` itself: it uses an `OUTPOST_TEST_MOCK_SERVER_URL` environment variable when one is set inside the container, otherwise `http://host.docker.internal:8080` when that name resolves (Docker Desktop on macOS and Windows), otherwise `http://172.17.0.1:8080` (Linux Docker, which is what the CI runner uses).
6. On failure: dumps wp-env + WireMock logs to the job output for diagnosis.
7. Always: stops wp-env + WireMock to release the runner cleanly.

### Why `172.17.0.1` for the mock-server URL on Linux runners

GitHub Actions Ubuntu runners use Linux Docker. The default bridge network gateway IP from inside any container is `172.17.0.1`, which is the address wp-env's `tests-cli` container sees the runner host on. WireMock binds to `0.0.0.0:8080` on the runner via `docker compose up`, so `http://172.17.0.1:8080` reaches it from inside the container.

Docker Desktop on macOS and Windows doesn't route `172.17.0.1`; it provides `host.docker.internal` instead. The bootstrap's detection picks the right one, so you don't set anything locally. To point at a different mock server, make an `OUTPOST_TEST_MOCK_SERVER_URL` environment variable visible inside the tests-cli container.

### What gates green

The smoke test in `tests/integration/IntegrationInfrastructureSmokeTest.php` covers:

- WP core loaded under wp-env.
- Outpost plugin loaded via the `muplugins_loaded` hook in `tests/integration/bootstrap.php`.
- `OUTPOST_TEST_MOCK_SERVER_URL` constant promoted from the env var.
- WireMock `/__admin/health` reachable from inside the wp-env container.
- `Outpost_Mock_Server_Filter::maybe_rewrite` hooked on `pre_http_request`.

If those five pass, the platform works, and a failing test elsewhere in the suite points at that test or the code it covers. Migrating a skipped test means adding its fixture JSON and removing the skip (see `docs/dev/g99-stub-migration-inventory.md`).

### What's still skipped

On 2026-11-08, `grep -rn markTestSkipped tests/integration` finds 72 calls across 28 files. Some are environment guards that skip only outside wp-env (for example, `integration_environment_ready()` checks); others skip unconditionally until their cluster is migrated. CI discovers and runs every test, but a skipped test stops at the `markTestSkipped` line, so read a test's docblock before you assume its assertions run. `docs/dev/g99-stub-migration-inventory.md` tracks the migration by cluster.
