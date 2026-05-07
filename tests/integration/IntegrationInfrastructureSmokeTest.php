<?php
/**
 * Integration suite smoke test (G99 wp-env CI).
 *
 * The single purpose of this file is to confirm the integration
 * infrastructure boots cleanly under CI:
 *
 *   1. wp-env has a real WordPress loaded (`function_exists('wp_safe_remote_get')`).
 *   2. Outpost's plugin file loaded under `muplugins_loaded`
 *      (`class_exists('Outpost_Mock_Server_Filter')`).
 *   3. The mock-server URL was promoted from the environment to the
 *      constant the filter + helper expect.
 *   4. The WireMock admin endpoint is reachable from inside wp-env's
 *      tests-cli container at the configured URL.
 *
 * If all four pass, the infrastructure is sound — per-cluster stub
 * migrations (per docs/dev/g99-stub-migration-inventory.md) can ride
 * the same wiring without re-debugging the platform.
 *
 * If the OUTPOST_TEST_MOCK_SERVER_URL constant isn't defined (local
 * `composer test:integration` without WireMock running, for example),
 * the WireMock checks skip — they'd block local dev otherwise.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class IntegrationInfrastructureSmokeTest extends TestCase {

	/**
	 * Guard: when this test class runs under the unit bootstrap (default
	 * `composer test` discovers everything in `tests/`), WP isn't loaded
	 * and the smoke assertions don't make sense. Skip cleanly. The
	 * integration bootstrap (`tests/integration/bootstrap.php`) loads WP
	 * before tests run; under that path the guard passes.
	 */
	private function require_integration_environment(): void {
		if ( ! function_exists( 'wp_safe_remote_get' ) ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap. Run via `composer test:integration` '
				. '(inside wp-env tests-cli) for the full integration smoke.'
			);
		}
	}

	public function test_wordpress_is_loaded(): void {
		$this->require_integration_environment();
		$this->assertTrue(
			function_exists( 'wp_safe_remote_get' ),
			'WP core not loaded — check wp-env tests-cli + integration bootstrap.'
		);
		$this->assertTrue(
			function_exists( 'register_post_type' ),
			'WP core not loaded — check wp-env tests-cli + integration bootstrap.'
		);
	}

	public function test_outpost_plugin_is_loaded(): void {
		$this->require_integration_environment();
		$this->assertTrue(
			class_exists( 'Outpost_Mock_Server_Filter' ),
			'Outpost not loaded — check muplugins_loaded hook in tests/integration/bootstrap.php.'
		);
		$this->assertTrue(
			defined( 'OUTPOST_VERSION' ),
			'OUTPOST_VERSION not defined — outpost.php did not run.'
		);
	}

	public function test_mock_server_url_constant_is_promoted_from_env(): void {
		$this->require_integration_environment();
		if ( false === getenv( 'OUTPOST_TEST_MOCK_SERVER_URL' ) || '' === getenv( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			$this->markTestSkipped(
				'OUTPOST_TEST_MOCK_SERVER_URL not set in environment — running locally without WireMock. '
				. 'CI sets this via `wp-env run tests-cli` --env passthrough.'
			);
		}
		$this->assertTrue(
			defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ),
			'Env var was set but bootstrap did not promote it to the constant.'
		);
		$this->assertSame(
			getenv( 'OUTPOST_TEST_MOCK_SERVER_URL' ),
			constant( 'OUTPOST_TEST_MOCK_SERVER_URL' )
		);
	}

	public function test_wiremock_admin_endpoint_is_reachable(): void {
		$this->require_integration_environment();
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			$this->markTestSkipped(
				'OUTPOST_TEST_MOCK_SERVER_URL not defined — WireMock check skipped. '
				. 'CI sets this; local dev can export the env var to opt in.'
			);
		}
		$health_url = rtrim( (string) constant( 'OUTPOST_TEST_MOCK_SERVER_URL' ), '/' ) . '/__admin/health';
		// `wp_remote_get` not `wp_safe_remote_get` — the safe variant blocks
		// private IPs (172.17.0.1, the Linux Docker bridge gateway) via WP's
		// SSRF defenses. That protection's correct for production paths;
		// inappropriate for a test-time health check against a known-good
		// runner-host service.
		$response = wp_remote_get( $health_url, array( 'timeout' => 10 ) );

		$this->assertFalse(
			is_wp_error( $response ),
			'WireMock admin endpoint not reachable at ' . $health_url
				. ( is_wp_error( $response ) ? ' — ' . $response->get_error_message() : '' )
		);
		$this->assertSame(
			200,
			(int) wp_remote_retrieve_response_code( $response ),
			'WireMock admin endpoint returned non-200 at ' . $health_url
		);
	}

	public function test_outpost_filter_registered_when_constant_defined(): void {
		$this->require_integration_environment();
		if ( ! defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ) ) {
			$this->markTestSkipped( 'Skipped without OUTPOST_TEST_MOCK_SERVER_URL.' );
		}
		// Filter registers in plugins_loaded → register(); confirms the
		// integration bootstrap's plugin-load path actually fired.
		$this->assertNotFalse(
			has_filter(
				'pre_http_request',
				array( 'Outpost_Mock_Server_Filter', 'maybe_rewrite' )
			),
			'Outpost_Mock_Server_Filter::maybe_rewrite is not hooked on pre_http_request.'
		);
	}
}
