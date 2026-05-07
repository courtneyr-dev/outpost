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
// `export OUTPOST_TEST_MOCK_SERVER_URL=http://172.17.0.1:8888` before
// running `npm run test:integration`.
$outpost_mock_server_url = getenv( 'OUTPOST_TEST_MOCK_SERVER_URL' );
if ( false !== $outpost_mock_server_url && '' !== $outpost_mock_server_url
	&& ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
	define( 'OUTPOST_TEST_MOCK_SERVER_URL', $outpost_mock_server_url );
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

require_once $wp_tests_dir . '/includes/bootstrap.php';
