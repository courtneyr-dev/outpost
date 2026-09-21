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

	/**
	 * A bearer token in the request body authenticates the endpoint even when
	 * the Authorization header never reaches PHP (managed-WP hosts like GoDaddy
	 * strip it). This is the path that broke composer-config on live after the
	 * 1.0.4 CSRF fix removed the cookie fallback: the token now authenticates
	 * through Outpost_Bearer_Auth, with no wp-admin cookie involved.
	 *
	 * @test
	 */
	public function body_token_authenticates_when_the_header_is_stripped(): void {
		$editor = $this->editor_id;
		// Stand in for IndieAuth's determine_current_user validator: it reads
		// the Authorization header the trait restores from the body token.
		$validator = static function ( $user ) use ( $editor ) {
			if ( $user ) {
				return $user;
			}
			$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
			return ( false !== stripos( $auth, 'Bearer valid-editor-token' ) ) ? $editor : $user;
		};
		add_filter( 'determine_current_user', $validator, 15 );
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		try {
			// dispatch() skips WP_REST_Server::serve_request(), which is what
			// copies $_POST and the raw body onto the request on a real HTTP
			// call. The trait reads the token from the request, so each probe
			// builds its request the way core would.
			$ok = rest_get_server()->dispatch( $this->composer_config_request( array( 'form' => 'valid-editor-token' ) ) );

			// Each real HTTP request is a fresh process; reset the user AND the
			// header the trait restored so the next token is judged alone.
			wp_set_current_user( 0 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			$bad = rest_get_server()->dispatch( $this->composer_config_request( array( 'form' => 'not-a-real-token' ) ) );

			// The PWA's real shape: Content-Type application/json.
			wp_set_current_user( 0 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			$json_ok = rest_get_server()->dispatch( $this->composer_config_request( array( 'json' => 'valid-editor-token' ) ) );

			wp_set_current_user( 0 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			$json_bad = rest_get_server()->dispatch( $this->composer_config_request( array( 'json' => 'not-a-real-token' ) ) );

			// A valid token in the URL must not authenticate: query strings
			// reach access logs, browser history, and CDN cache keys.
			wp_set_current_user( 0 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			$query              = rest_get_server()->dispatch( $this->composer_config_request( array( 'query' => 'valid-editor-token' ) ) );
			$header_after_query   = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			remove_filter( 'determine_current_user', $validator, 15 );
			wp_set_current_user( 0 );
		}

		$this->assertSame( 200, $ok->get_status(), 'A valid body token must authenticate with no header and no cookie.' );
		$this->assertArrayHasKey( 'companions', (array) $ok->get_data() );
		$this->assertSame( 401, $bad->get_status(), 'A bogus body token must be rejected.' );
		$this->assertSame( 200, $json_ok->get_status(), 'A valid token in a JSON body must authenticate.' );
		$this->assertSame( 401, $json_bad->get_status(), 'A bogus token in a JSON body must be rejected.' );
		$this->assertSame( 401, $query->get_status(), 'A token in the query string must not authenticate.' );
		$this->assertNull( $header_after_query, 'A query-string token must not be restored to the Authorization header.' );
	}

	/**
	 * Build a composer-config POST carrying a token the way
	 * WP_REST_Server::serve_request() would have populated it.
	 *
	 * @param array<string, string> $token One of 'form', 'json', 'query' => token.
	 */
	private function composer_config_request( array $token ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/composer-config' );
		if ( isset( $token['form'] ) ) {
			$request->set_body_params( array( 'access_token' => $token['form'] ) );
		}
		if ( isset( $token['json'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'access_token' => $token['json'] ) ) );
		}
		if ( isset( $token['query'] ) ) {
			$request->set_query_params( array( 'access_token' => $token['query'] ) );
		}
		return $request;
	}
}
