<?php
/**
 * Integration test: REST route identity for route-scoped security decisions.
 *
 * Drives WordPress's real `rest_authentication_errors` chain — core's
 * `rest_cookie_check_errors` (priority 100) followed by Outpost's
 * composer-config opt-out (priority 999) — exactly as
 * `WP_REST_Server::check_authentication()` runs it on a live request.
 *
 * The audited defect (pre-1.0.4 audit, 2026-09-01): the opt-out keyed on
 * REQUEST_URI's path while WordPress dispatches on the `rest_route` query
 * var. A logged-in victim lured to
 *
 *   GET /wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/N&_method=DELETE&_wpnonce=x
 *
 * had core's invalid-nonce error cleared and the DELETE executed as them.
 * Every denial here asserts the auth result AND that the protected work did
 * not run (the target post survives); the valid-nonce control proves the
 * same flow does delete when the nonce is genuine, so the survival assertion
 * is calibrated.
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
final class RestRouteResolutionTest extends TestCase {

	private int $admin_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_wp = null;

	/** @var mixed */
	private $saved_auth_cookie = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Composer_Config_Endpoint' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_server      = $_SERVER;
		$this->saved_request     = $_REQUEST;
		$this->saved_wp          = $GLOBALS['wp'] ?? null;
		$this->saved_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;

		$this->admin_id = (int) wp_insert_user(
			array(
				'user_login' => 'route_admin_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'route_admin_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$_SERVER  = $this->saved_server;
		$_REQUEST = $this->saved_request;
		unset( $_GET['_method'] );
		$GLOBALS['wp']                  = $this->saved_wp;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_auth_cookie;
		remove_all_filters( 'outpost_test_blanket_gate' );
		if ( $this->admin_id > 0 ) {
			wp_delete_user( $this->admin_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_post(): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => 'route-victim-' . uniqid(),
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
				'post_type'   => 'post',
			)
		);
	}

	/**
	 * Model a cookie-authenticated browser request the way core sees it:
	 * the auth cookie validated (so the REST nonce is required), the
	 * dispatch target in `rest_route`, and the raw URI carrying whatever the
	 * attacker wrote.
	 */
	private function arrive_as_cookie_user( string $request_uri, string $resolved_route, ?string $nonce ): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['REQUEST_URI']         = $request_uri;
		$_SERVER['REQUEST_METHOD']      = 'GET';
		if ( null === $nonce ) {
			unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'] );
		} else {
			$_REQUEST['_wpnonce'] = $nonce;
		}
		$wp = new \WP();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test harness models the parsed request.
		$wp->query_vars = array( 'rest_route' => $resolved_route );
		$GLOBALS['wp']  = $wp;
	}

	/**
	 * What WP_REST_Server::serve_request() does: authenticate, and only if
	 * that passes, dispatch. Returns the auth result so tests can assert on it.
	 *
	 * @return mixed
	 */
	private function serve_delete( int $post_id ) {
		$server = rest_get_server();
		$auth   = $server->check_authentication();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$server->dispatch( $request );
		return $auth;
	}

	/**
	 * @test
	 */
	public function bogus_nonce_delete_smuggled_through_composer_config_path_is_refused_and_post_survives(): void {
		$post_id = $this->make_post();
		$this->arrive_as_cookie_user(
			'/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/' . $post_id . '&_method=DELETE&_wpnonce=bogus',
			'/wp/v2/posts/' . $post_id,
			'bogus'
		);

		$auth = $this->serve_delete( $post_id );

		$this->assertInstanceOf( \WP_Error::class, $auth, 'Core\'s invalid-nonce error must survive the composer-config opt-out.' );
		$this->assertSame( 'rest_cookie_invalid_nonce', $auth->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'The protected DELETE must not have run.' );
	}

	/**
	 * @test
	 */
	public function nonceless_delete_smuggled_through_composer_config_path_is_anonymous_and_post_survives(): void {
		$post_id = $this->make_post();
		$this->arrive_as_cookie_user(
			'/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/' . $post_id . '&_method=DELETE',
			'/wp/v2/posts/' . $post_id,
			null
		);

		$this->serve_delete( $post_id );

		$this->assertSame( 0, get_current_user_id(), 'Without a nonce core demotes the cookie session to anonymous.' );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * @test
	 */
	public function valid_nonce_delete_on_the_plain_path_does_delete_which_calibrates_the_survival_assertions(): void {
		$post_id = $this->make_post();
		$this->arrive_as_cookie_user( '/wp-json/wp/v2/posts/' . $post_id, '/wp/v2/posts/' . $post_id, null );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_rest' );

		$auth = $this->serve_delete( $post_id );

		$this->assertTrue( $auth, 'A genuine nonce passes core\'s cookie check.' );
		$this->assertSame( 'trash', get_post_status( $post_id ), 'Positive control: the same flow deletes with a real nonce.' );
	}

	/**
	 * @test
	 */
	public function bogus_nonce_on_composer_config_itself_is_still_refused(): void {
		// The opt-out may clear third-party blanket gates for its own route, but
		// never core's CSRF defense.
		$this->arrive_as_cookie_user( '/wp-json/outpost/v1/composer-config?_wpnonce=bogus', '/outpost/v1/composer-config', 'bogus' );

		$auth = rest_get_server()->check_authentication();

		$this->assertInstanceOf( \WP_Error::class, $auth );
		$this->assertSame( 'rest_cookie_invalid_nonce', $auth->get_error_code() );
	}

	/**
	 * @test
	 */
	public function third_party_blanket_gate_is_cleared_only_for_the_composer_config_route(): void {
		// Simulate a REST-hardening plugin: anonymous requests are refused everywhere.
		$gate = static function ( $result ) {
			if ( null !== $result || is_user_logged_in() ) {
				return $result;
			}
			return new \WP_Error( 'rest_not_logged_in', 'blanket gate', array( 'status' => 401 ) );
		};
		add_filter( 'rest_authentication_errors', $gate, 10 );
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_auth_cookie'] = null;

		try {
			// Our own route: the gate is cleared so our permission_callback decides.
			$wp = new \WP();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test harness.
			$wp->query_vars         = array( 'rest_route' => '/outpost/v1/composer-config' );
			$GLOBALS['wp']          = $wp;
			$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config';
			$this->assertNull( rest_get_server()->check_authentication(), 'Compatibility purpose preserved for the composer-config route.' );

			// Our path, another route: the gate must stand.
			$wp->query_vars         = array( 'rest_route' => '/wp/v2/posts' );
			$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts';
			$auth                   = rest_get_server()->check_authentication();
			$this->assertInstanceOf( \WP_Error::class, $auth );
			$this->assertSame( 'rest_not_logged_in', $auth->get_error_code() );
		} finally {
			remove_filter( 'rest_authentication_errors', $gate, 10 );
		}
	}

	/**
	 * @test
	 */
	public function composer_config_request_still_reaches_its_own_permission_callback(): void {
		// Not a CSRF shape: a plain logged-in dispatch of the real route must
		// keep working and be decided by edit_posts, not by the opt-out.
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie'] = null;
		delete_transient( 'outpost_config_rl_u_' . $this->admin_id );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );
		delete_transient( 'outpost_config_rl_u_' . $this->admin_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}

	/**
	 * @test
	 */
	public function media_lookup_body_token_is_only_restored_on_the_lookup_route(): void {
		// Third instance of the class: the bearer reinjection used to hook
		// determine_current_user globally and scope itself by strpos() on
		// REQUEST_URI. Now it lives in the lookup route's own permission
		// callback, so dispatching ANY other route with a body token and the
		// lookup path as a decoy restores nothing.
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		wp_set_current_user( 0 );
		$_POST['access_token']  = 'decoy-token'; // outpost-lint:fixture-credential
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?probe=outpost/v1/lookup';
		$wp                     = new \WP();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test harness.
		$wp->query_vars = array( 'rest_route' => '/wp/v2/users' );
		$GLOBALS['wp']  = $wp;

		// Trigger the current-user determination the old global shim hooked into.
		apply_filters( 'determine_current_user', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
		$this->assertArrayNotHasKey( 'HTTP_AUTHORIZATION', $_SERVER, 'No reinjection on a route that is not the lookup.' );

		// The lookup route itself DOES restore the header for its validating filter.
		$wp->query_vars = array( 'rest_route' => '/outpost/v1/lookup' );
		$request        = new WP_REST_Request( 'POST', '/outpost/v1/lookup' );
		$request->set_body_params( array( 'kind' => 'book', 'q' => 'dune', 'access_token' => 'decoy-token' ) );
		rest_get_server()->dispatch( $request );
		$this->assertSame( 'Bearer decoy-token', $_SERVER['HTTP_AUTHORIZATION'] ?? null );
		unset( $_POST['access_token'], $_SERVER['HTTP_AUTHORIZATION'] );
	}
}
