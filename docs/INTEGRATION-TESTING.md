# Integration Testing

Integration tests exercise Outpost against a real WordPress install instead of WP_Mock stubs. This catches issues unit tests can't reach: rewrite-rule registration through `WP_Rewrite`, REST endpoint behavior through `WP_REST_Server`, capability cascades, hook ordering against WordPress's built-in handlers.

Outpost uses [@wordpress/env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for the test environment — the same Docker-based setup the WordPress core team uses for Gutenberg and core PHPUnit.

## Prerequisites

- **Docker Desktop** running. macOS: `brew install --cask docker` and launch the app once. The npm scripts will fail with a helpful error if Docker isn't running.
- `npm install` already done (installs `@wordpress/env` per `package.json` devDependencies).

The first `wp-env start` pulls a few hundred MB of Docker images (PHP, MySQL, WordPress) and takes a few minutes. Subsequent starts are seconds.

## Configuration

`.wp-env.json` at the repo root configures:

- **WordPress core:** `6.7` (matches `Tested up to:` in the plugin header)
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

- `tests/integration/RouteHandlerIntegrationTest.php` is currently `markTestSkipped()` — the bootstrap is wired but the actual assertions haven't been written. The skipped test's top-of-file comment names the assertions the wp-env-backed test will make: Content-Type per route (`/post/`, `/post/manifest.json`, `/post/sw`, `/post/auth/callback/*`, `/post/share-target/*`), query-var dispatch through `template_redirect`, `register_activation_hook` side effects on `flush_rewrite_rules`.
- The next session focused on integration testing fills these in. Sequence: un-skip the test, write assertions one route at a time, run `npm run test:integration` to verify, commit.
- A future CI job will run `npm run test:integration` on every PR. Deferred until the assertions land — running an empty wp-env in CI just adds 2-5 minutes of Docker boot for no signal.

## Two PHPUnit bootstraps

PHPUnit 9.6 doesn't support per-testsuite bootstraps in `phpunit.xml.dist`. Outpost solves this via Composer scripts:

| Suite | Bootstrap | Loads |
|-------|-----------|-------|
| `unit` | `tests/bootstrap.php` (default from `phpunit.xml.dist`) | WP_Mock stubs + Outpost classes |
| `integration` | `tests/integration/bootstrap.php` (overridden in `composer.json`'s `test:integration` script) | Real WP via the WP core test suite |

`composer test` runs the unit suite (default). `composer test:unit` is the explicit equivalent. `composer test:integration` overrides the bootstrap for the integration suite. The `--bootstrap=` flag on the PHPUnit command line takes precedence over `phpunit.xml.dist`'s `bootstrap` attribute.

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

## Local development workflow (vs. integration testing)

`wp-env:start` also brings up a development site at `localhost:8888` you can browse. This isn't strictly necessary for Outpost (we have staging at `qkf.b0d.myftpupload.com`), but it's available if you want to test something locally without affecting staging.

For routine development, the staging deploy ritual is faster:
1. Edit code locally
2. `npm run build` (rebuilds the PWA bundle)
3. `git commit && git push` (lands on `outpost` main)
4. Bump submodule pointer in `staging-courtneyr-dev`, push (triggers gd-wordpress-deployer)
5. Smoke test via `https://qkf.b0d.myftpupload.com/post/?_cb=<timestamp>`

Local wp-env is useful when you need to run integration tests, or when staging is down, or when you want to test against a different WP version than staging is running.
