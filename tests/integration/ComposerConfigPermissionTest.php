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

	use IndieAuthTokenFixture;

	private int $subscriber_id = 0;
	private int $editor_id     = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->reset_indieauth_fixture();
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
		$this->reset_indieauth_fixture();
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
	 * A bearer token in a form-encoded body authenticates the endpoint even
	 * when the Authorization header never reaches PHP (some hosts strip
	 * it): IndieAuth reads `$_POST['access_token']` while WordPress
	 * resolves the user, before the route runs. No wp-admin cookie is
	 * involved.
	 *
	 * The token is `create`-scoped: H6's scope gate refuses a bearer
	 * request whose token carries no create/update/read scope, and under
	 * real IndieAuth only `create` (or `draft`) grants `edit_posts`.
	 *
	 * @test
	 */
	public function body_token_authenticates_when_the_header_is_stripped(): void {
		// IndieAuthTokenFixture: a real IndieAuth token when IndieAuth is
		// loaded, otherwise a stand-in that reads the header and
		// `$_POST['access_token']`, the two places IndieAuth reads.
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'create' ) );

		try {
			$ok        = $this->probe( array( 'form' => $token ) );
			$bad       = $this->probe( array( 'form' => 'not-a-real-token' ) );
			$json      = $this->probe( array( 'json' => $token ) );
			$json_user = get_current_user_id();
			$json_hdr  = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
			$query     = $this->probe( array( 'query' => $token ) );
			$query_hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
		} finally {
			$this->reset_indieauth_request_state();
			wp_set_current_user( 0 );
		}

		$this->assertSame( 200, $ok->get_status(), 'A valid form-encoded token must authenticate with no header and no cookie.' );
		$this->assertArrayHasKey( 'companions', (array) $ok->get_data() );
		$this->assertSame( 401, $bad->get_status(), 'A bogus form-encoded token must be rejected.' );
		// wp.org review 2026-09-14: the route never switches users, so a
		// token IndieAuth can't read (a JSON body) authenticates nobody.
		$this->assertSame( 401, $json->get_status(), 'A token in a JSON body is not a credential.' );
		$this->assertSame( 0, $json_user, 'The route must not switch to the JSON-body token\'s user.' );
		$this->assertNull( $json_hdr, 'The route must not copy a body token into the Authorization header.' );
		$this->assertSame( 401, $query->get_status(), 'A token in the query string must not authenticate.' );
		$this->assertNull( $query_hdr, 'A query-string token must not be restored to the Authorization header.' );
	}

	/**
	 * One request, as a fresh PHP process sees it: clear the previous
	 * request's credentials, let PHP fill `$_POST` for a form body, resolve
	 * the user the way core does before dispatch, then dispatch.
	 *
	 * @param array<string, string> $token One of 'form', 'json', 'query' => token.
	 */
	private function probe( array $token ): \WP_REST_Response {
		$this->reset_indieauth_request_state();
		wp_set_current_user( 0 );
		if ( isset( $token['form'] ) ) {
			$_POST['access_token'] = $token['form'];
		}
		$this->resolve_current_user_like_core();
		return rest_get_server()->dispatch( $this->composer_config_request( $token ) );
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
