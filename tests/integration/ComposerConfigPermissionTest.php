<?php
/**
 * Integration test: composer-config REST permission gate.
 *
 * wp.org plugin-review revision (2026-08): permission_check() accepted
 * any logged-in user via an is_user_logged_in() fallback, so a
 * Subscriber without edit_posts could read composer configuration and
 * companion-plugin enumeration. The gate is now
 * current_user_can( 'edit_posts' ) only, still filterable via
 * outpost_composer_config_permission.
 *
 * Auth-gate discipline (CLAUDE.md Testing): every denial asserts the
 * status AND, independently, that the protected work never fired — no
 * rate-limit transient written, no outpost_bridgy_host_map filter
 * application, no payload keys. The success case asserts the same
 * probes DO fire, so the absence assertions are calibrated rather
 * than vacuously true.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class ComposerConfigPermissionTest extends TestCase {

	private int $subscriber_id = 0;
	private int $editor_id     = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->subscriber_id = (int) wp_insert_user(
			array(
				'user_login' => 'config_sub_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'config_sub_' . uniqid() . '@example.test',
				'role'       => 'subscriber',
			)
		);
		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'config_ed_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'config_ed_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( $this->subscriber_id > 0 ) {
			delete_transient( 'outpost_config_rl_u_' . $this->subscriber_id );
			wp_delete_user( $this->subscriber_id );
		}
		if ( $this->editor_id > 0 ) {
			delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
			wp_delete_user( $this->editor_id );
		}
		$this->subscriber_id = 0;
		$this->editor_id     = 0;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Composer_Config_Endpoint' );
	}

	private function dispatch_config_request(): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/outpost/v1/composer-config' );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @test
	 */
	public function subscriber_is_denied_and_handler_never_runs(): void {
		wp_set_current_user( $this->subscriber_id );
		delete_transient( 'outpost_config_rl_u_' . $this->subscriber_id );
		$bridgy_filter_runs_before = did_filter( 'outpost_bridgy_host_map' );

		$response = $this->dispatch_config_request();

		$this->assertSame(
			403,
			$response->get_status(),
			'Logged-in user without edit_posts (Subscriber) must get 403.'
		);
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey(
			'companions',
			$data,
			'Denied response must not leak companion-plugin enumeration.'
		);
		$this->assertFalse(
			get_transient( 'outpost_config_rl_u_' . $this->subscriber_id ),
			'Denied request must not reach the handler: no rate-limit transient may be written.'
		);
		$this->assertSame(
			$bridgy_filter_runs_before,
			did_filter( 'outpost_bridgy_host_map' ),
			'Denied request must not reach the handler: outpost_bridgy_host_map must not run.'
		);
	}

	/**
	 * @test
	 */
	public function anonymous_is_denied_and_handler_never_runs(): void {
		wp_set_current_user( 0 );
		$bridgy_filter_runs_before = did_filter( 'outpost_bridgy_host_map' );

		$response = $this->dispatch_config_request();

		$this->assertSame(
			401,
			$response->get_status(),
			'Anonymous request must get 401.'
		);
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey(
			'companions',
			$data,
			'Denied response must not leak companion-plugin enumeration.'
		);
		$this->assertSame(
			$bridgy_filter_runs_before,
			did_filter( 'outpost_bridgy_host_map' ),
			'Denied request must not reach the handler: outpost_bridgy_host_map must not run.'
		);
	}

	/**
	 * @test
	 */
	public function editor_succeeds_and_side_effect_probes_fire(): void {
		wp_set_current_user( $this->editor_id );
		delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
		$bridgy_filter_runs_before = did_filter( 'outpost_bridgy_host_map' );

		$response = $this->dispatch_config_request();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'companions', $data );
		$this->assertArrayHasKey( 'bridgyHostMap', $data );
		// Positive control: the same probes the denial tests assert as
		// absent must fire on success, or those absence assertions are
		// vacuously true.
		$this->assertNotFalse(
			get_transient( 'outpost_config_rl_u_' . $this->editor_id ),
			'Success path must write the rate-limit transient (calibrates the denial probes).'
		);
		$this->assertGreaterThan(
			$bridgy_filter_runs_before,
			did_filter( 'outpost_bridgy_host_map' ),
			'Success path must run outpost_bridgy_host_map (calibrates the denial probes).'
		);
	}
}
