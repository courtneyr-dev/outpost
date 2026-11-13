<?php
/**
 * Cookie-authenticated REST requests fail closed without a valid nonce (T10-S3-02).
 *
 * Models WP_REST_Server::serve_request(): authenticate through the real
 * `rest_authentication_errors` chain, then dispatch the real Outpost route.
 * A third-party callback at priority 5 returns `true` (a "REST hardening"
 * plugin, say). That non-empty result makes core's own
 * `rest_cookie_check_errors()` (priority 100) skip its nonce check. Outpost's
 * permission callbacks must then refuse a cookie session whose REST nonce is
 * missing or invalid by themselves, and the protected work (a user-meta write,
 * a rate-limit transient) must not happen.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase as TestCase;

final class CookieNonceFailClosedTest extends TestCase {

	private int $editor_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** Number of user-meta writes for the editor in the current test. */
	private int $meta_writes = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Appearance_REST_Controller' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_server      = $_SERVER;
		$this->saved_request     = $_REQUEST;
		$this->saved_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;

		$this->editor_id = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->meta_writes = 0;
		add_action( 'added_user_meta', array( $this, 'count_meta_write' ), 10, 2 );
		add_action( 'updated_user_meta', array( $this, 'count_meta_write' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_action( 'added_user_meta', array( $this, 'count_meta_write' ), 10 );
		remove_action( 'updated_user_meta', array( $this, 'count_meta_write' ), 10 );
		remove_filter( 'rest_authentication_errors', '__return_true', 5 );
		$_SERVER                        = $this->saved_server;
		$_REQUEST                       = $this->saved_request;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_auth_cookie;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Hook callback: count user-meta writes for the test editor.
	 *
	 * @param int $meta_id Meta row id.
	 * @param int $user_id User id.
	 */
	public function count_meta_write( $meta_id, $user_id ): void {
		unset( $meta_id );
		if ( (int) $user_id === $this->editor_id ) {
			++$this->meta_writes;
		}
	}

	/**
	 * A cookie-authenticated browser request as core sees it after the auth
	 * cookie validated. `$nonce` null sends none; otherwise it rides in the
	 * X-WP-Nonce header.
	 */
	private function arrive_as_cookie_editor( ?string $nonce ): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}
	}

	/**
	 * Authenticate like serve_request(), then dispatch when that passed.
	 *
	 * @return array{auth: mixed, response: \WP_REST_Response|null}
	 */
	private function serve( WP_REST_Request $request ): array {
		$server = rest_get_server();
		$auth   = $server->check_authentication();
		if ( is_wp_error( $auth ) ) {
			return array(
				'auth'     => $auth,
				'response' => null,
			);
		}
		return array(
			'auth'     => $auth,
			'response' => $server->dispatch( $request ),
		);
	}

	private function appearance_post(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/appearance/tokens' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'mode_preference' => 'night' ) ) );
		return $request;
	}

	private function config_rate_limit_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_config_rl_%'" );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing nonce' => array( null ),
			'invalid nonce' => array( 'forged0000' ),
		);
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_appearance_write_refused_when_a_third_party_gate_skips_core( ?string $nonce ): void {
		add_filter( 'rest_authentication_errors', '__return_true', 5 );
		$this->arrive_as_cookie_editor( $nonce );

		$result = $this->serve( $this->appearance_post() );

		$this->assertTrue( $result['auth'], 'Precondition: the third-party gate made core skip its nonce check.' );
		$this->assertNotNull( $result['response'] );
		$this->assertGreaterThanOrEqual( 401, $result['response']->get_status(), 'Outpost must refuse a cookie session without a valid nonce.' );
		$this->assertSame( 0, $this->meta_writes, 'No user meta may be written for a refused request.' );
		$this->assertSame( '', (string) get_user_meta( $this->editor_id, 'outpost_appearance_mode', true ) );
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_composer_config_refused_when_a_third_party_gate_skips_core( ?string $nonce ): void {
		add_filter( 'rest_authentication_errors', '__return_true', 5 );
		$this->arrive_as_cookie_editor( $nonce );
		$rows_before = $this->config_rate_limit_rows();

		$result = $this->serve( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );

		$this->assertNotNull( $result['response'] );
		$this->assertGreaterThanOrEqual( 401, $result['response']->get_status() );
		$this->assertArrayNotHasKey( 'companions', (array) $result['response']->get_data(), 'The handler must not build the config body.' );
		$this->assertSame( $rows_before, $this->config_rate_limit_rows(), 'A refused request must not spend rate-limit budget.' );
	}

	public function test_valid_nonce_still_passes_behind_a_third_party_gate(): void {
		add_filter( 'rest_authentication_errors', '__return_true', 5 );
		wp_set_current_user( $this->editor_id );
		$this->arrive_as_cookie_editor( wp_create_nonce( 'wp_rest' ) );

		$result = $this->serve( $this->appearance_post() );

		$this->assertSame( 200, $result['response'] ? $result['response']->get_status() : 0 );
		$this->assertSame( 'night', get_user_meta( $this->editor_id, 'outpost_appearance_mode', true ) );
	}

	public function test_default_core_still_refuses_a_missing_nonce(): void {
		$this->arrive_as_cookie_editor( null );

		$result = $this->serve( $this->appearance_post() );

		$this->assertNotNull( $result['response'], 'Core demotes a nonce-less cookie session to anonymous and dispatches.' );
		$this->assertGreaterThanOrEqual( 401, $result['response']->get_status() );
		$this->assertSame( 0, $this->meta_writes );
	}

	public function test_default_core_refuses_an_invalid_nonce_before_dispatch(): void {
		$this->arrive_as_cookie_editor( 'forged0000' );

		$result = $this->serve( $this->appearance_post() );

		$this->assertWPError( $result['auth'] );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result['auth']->get_error_code() );
		$this->assertNull( $result['response'] );
		$this->assertSame( 0, $this->meta_writes );
	}
}
