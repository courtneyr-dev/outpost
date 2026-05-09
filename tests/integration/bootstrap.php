<?php
/**
 * PHPUnit bootstrap for integration tests.
 *
 * Runs inside @wordpress/env's `tests-cli` container, where WordPress is
 * installed and `WP_TESTS_DIR` (or the legacy default `/wordpress-phpunit`)
 * points at the WP core test suite.
 *
 * Different from `tests/bootstrap.php` (unit tests with WP_Mock stubs).
 * Integration tests need real WP loaded so `WP_Rewrite`, `WP_REST_Server`,
 * the database, and capability helpers are all available.
 *
 * Activation:
 *   1. Start wp-env (Docker required):  npm run wp-env:start
 *   2. Run the integration suite:       npm run test:integration
 *
 * The npm script `test:integration` invokes wp-env's `tests-cli` container
 * with `--env-cwd=wp-content/plugins/outpost` so PHPUnit and Composer run
 * inside the plugin directory of the running WP install.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

// Promote OUTPOST_TEST_MOCK_SERVER_URL from the environment to a PHP
// constant when set. Both Outpost_Mock_Server_Filter and the test
// helper Outpost_Mock_Server gate on the constant — without it, the
// filter is a no-op and the helper throws a clear error. CI passes
// this env var through `wp-env run tests-cli`; local dev sessions can
// `export OUTPOST_TEST_MOCK_SERVER_URL=http://172.17.0.1:8080` before
// running `npm run test:integration`.
$outpost_mock_server_url = getenv( 'OUTPOST_TEST_MOCK_SERVER_URL' );
if ( false !== $outpost_mock_server_url && '' !== $outpost_mock_server_url
	&& ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
	define( 'OUTPOST_TEST_MOCK_SERVER_URL', $outpost_mock_server_url );
}

// Make Outpost_PWA_Shell::halt() a no-op so share-target / route-handler
// tests can capture state without exit()ing PHPUnit. Mirrors the same
// constant set in tests/bootstrap.php for unit tests; integration env
// previously didn't need it because no migrated cluster exercised halt().
if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
	define( 'OUTPOST_TESTING_PWA_SHELL', true );
}

// Load Composer's autoloader before anything else so test helpers
// (Outpost_Mock_Server in tests/_helpers/, registered via the
// autoload-dev classmap) are available to the integration tests.
// Without this, `class_exists('Outpost_Mock_Server')` returns false
// and migrated stubs that depend on the helper skip silently.
$outpost_autoloader = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( file_exists( $outpost_autoloader ) ) {
	require_once $outpost_autoloader;
}

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	// @wordpress/env's default location for the WP test suite.
	$wp_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP test suite not found at {$wp_tests_dir}/includes/functions.php\n" );
	fwrite( STDERR, "Integration tests require @wordpress/env. See docs/INTEGRATION-TESTING.md\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Load Outpost on the `muplugins_loaded` action so the plugin's hooks register
 * in the same way they would on a real site, before WP_Rewrite locks in.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/outpost.php';
	}
);

/**
 * Whitelist the mock-server host past `wp_http_validate_url()`'s
 * private-IP block. `wp_safe_remote_get` (used by Og_Inbound and
 * other production fetchers) rejects 172.17.0.1 — the Linux Docker
 * bridge gateway where WireMock binds — as a private IP via
 * RFC 1918 enforcement. The `http_request_host_is_external` filter
 * narrows the bypass to only the configured mock-server host;
 * production paths stay fully protected.
 *
 * Scoped on the OUTPOST_TEST_MOCK_SERVER_URL constant — production
 * never defines that constant so the filter is a no-op outside
 * integration tests.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			return;
		}
		$mock_host = wp_parse_url( OUTPOST_TEST_MOCK_SERVER_URL, PHP_URL_HOST );
		if ( ! is_string( $mock_host ) || '' === $mock_host ) {
			return;
		}
		add_filter(
			'http_request_host_is_external',
			static function ( $is_external, $host ) use ( $mock_host ) {
				return $host === $mock_host ? true : $is_external;
			},
			10,
			2
		);
	}
);

/**
 * Make `is_plugin_active( OUTPOST_PLUGIN_BASENAME )` return true under
 * the integration test environment. The WP test suite loads Outpost via
 * the `muplugins_loaded` filter above, which executes the plugin file
 * but does NOT register Outpost in the `active_plugins` option. Without
 * this filter, every companion adapter that delegates to
 * `Outpost_Companion_Detector::status()` (which itself calls
 * `is_plugin_active()`) returns 'inactive' for Outpost, so
 * `Manual_Share_Adapter::is_active()` returns false, so
 * `Companion_Registry::active()` filters it out, so chip-dependent
 * tests silently see zero chips.
 *
 * Discovered 2026-05-09 (gotcha #11). See
 * `docs/dev/integration-test-gotchas.md` for the full diagnosis. Same
 * shape as gotcha #9 (test environment failing to model production
 * faithfully) — fix the environment, not each test class.
 *
 * Appends to the live option value so any plugins activated by the WP
 * test suite (or by `.wp-env.json`'s plugins list — currently
 * indieauth + micropub) stay activated. Production never reads this
 * filter because production never registers it.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		if ( ! defined( 'OUTPOST_PLUGIN_BASENAME' ) ) {
			return;
		}
		add_filter(
			'option_active_plugins',
			static function ( $plugins ) {
				if ( ! is_array( $plugins ) ) {
					$plugins = array();
				}
				if ( ! in_array( OUTPOST_PLUGIN_BASENAME, $plugins, true ) ) {
					$plugins[] = OUTPOST_PLUGIN_BASENAME;
				}
				return $plugins;
			}
		);
	}
);

require_once $wp_tests_dir . '/includes/bootstrap.php';
