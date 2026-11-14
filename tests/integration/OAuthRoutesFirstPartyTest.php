<?php
/**
 * OAuth start, verify, and disconnect are wp-admin actions only
 * (T10-S4-11, Phase 4 Antigravity A-01; assertions tightened in T10-S4-21).
 *
 * These routes read or remove a person's connected-account credential.
 * T10-S3-06 limited connected-account reads to the person's own wp-admin
 * session or the Outpost app; these routes accepted any non-bearer
 * credential, including an application password made for another app.
 * They now need a first-party cookie session with a valid REST nonce, the
 * way wp-admin calls them (the start link carries `_wpnonce`).
 *
 * Each refusal is an exact 403 (the user is signed in) and must leave no
 * trace: no OAuth state transient, no outbound request, and the stored
 * credential intact. The arrivals set the authentication globals the way
 * core leaves them; a real HTTP Basic round trip is recorded separately in
 * the Phase 4 lab evidence.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use PHPUnit\Framework\TestCase;

final class OAuthRoutesFirstPartyTest extends TestCase {

	private int $admin_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** @var mixed */
	private $saved_app_password = null;

	/** @var array<int, string> */
	private array $transients = array();

	/** @var array<int, string> */
	private array $outbound = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_OAuth_Controller' ) || ! function_exists( 'rest_get_server' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_server       = $_SERVER;
		$this->saved_request      = $_REQUEST;
		$this->saved_auth_cookie  = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->saved_app_password = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
		$this->admin_id           = (int) wp_insert_user(
			array(
				'user_login' => 's411_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's411_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		\Outpost_Encryption_Key_Resolver::resolve();
		Outpost_Credentials_Store::set( 'oura', array( 'access_token' => 'SYNTHETIC-OURA-TOKEN' ), $this->admin_id );
		add_action( 'setted_transient', array( $this, 'record_transient' ) );
		add_filter( 'pre_http_request', array( $this, 'record_outbound' ), 0, 3 );
		rest_get_server();
	}

	protected function tearDown(): void {
		remove_action( 'setted_transient', array( $this, 'record_transient' ) );
		remove_filter( 'pre_http_request', array( $this, 'record_outbound' ), 0 );
		$_SERVER                                      = $this->saved_server;
		$_REQUEST                                     = $this->saved_request;
		$GLOBALS['wp_rest_auth_cookie']               = $this->saved_auth_cookie;
		$GLOBALS['wp_rest_application_password_uuid'] = $this->saved_app_password;
		wp_set_current_user( 0 );
		if ( $this->admin_id > 0 ) {
			Outpost_Credentials_Store::delete( 'oura', $this->admin_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->admin_id );
		}
		parent::tearDown();
	}

	/**
	 * Record each transient name a request writes.
	 */
	public function record_transient( string $name ): void {
		$this->transients[] = $name;
	}

	/**
	 * @param mixed  $pre  Short-circuit value.
	 * @param mixed  $args Request args.
	 * @param string $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function record_outbound( $pre, $args, $url ): array {
		$this->outbound[] = (string) $url;
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function arrive_with_application_password(): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie']               = false;
		$GLOBALS['wp_rest_application_password_uuid'] = '00000000-0000-4000-8000-000000000000';
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'synthetic:not-a-real-password' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function arrive_with_cookie( bool $valid_nonce ): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie']               = true;
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		if ( $valid_nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		}
	}

	private function status( string $method, string $route ): int {
		return rest_do_request( new \WP_REST_Request( $method, $route ) )->get_status();
	}

	private function assert_untouched(): void {
		$this->assertSame( array(), array_values( preg_grep( '/^outpost_oauth_state_/', $this->transients ) ), 'No OAuth state is written.' );
		$this->assertSame( array(), $this->outbound, 'Nothing is sent to the provider.' );
		$this->assertSame(
			array( 'access_token' => 'SYNTHETIC-OURA-TOKEN' ),
			Outpost_Credentials_Store::get( 'oura', $this->admin_id ),
			'The stored credential stays exactly as it was.'
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function routes(): array {
		return array(
			'start'      => array( 'GET', '/outpost/v1/oauth/oura/start' ),
			'verify'     => array( 'GET', '/outpost/v1/oauth/oura/verify' ),
			'disconnect' => array( 'POST', '/outpost/v1/oauth/oura/disconnect' ),
		);
	}

	/**
	 * @dataProvider routes
	 */
	public function test_an_application_password_is_refused_and_changes_nothing( string $method, string $route ): void {
		$this->arrive_with_application_password();

		$this->assertSame( 403, $this->status( $method, $route ), 'An application password is not a wp-admin session.' );
		$this->assert_untouched();
	}

	/**
	 * @dataProvider routes
	 */
	public function test_a_cookie_session_without_a_nonce_is_refused_and_changes_nothing( string $method, string $route ): void {
		$this->arrive_with_cookie( false );

		$this->assertSame( 403, $this->status( $method, $route ) );
		$this->assert_untouched();
	}

	/**
	 * The wp-admin session starts a connection.
	 */
	public function test_the_wp_admin_session_starts_a_connection(): void {
		$this->arrive_with_cookie( true );

		$this->assertSame( 302, $this->status( 'GET', '/outpost/v1/oauth/oura/start' ) );
		$this->assertCount( 1, preg_grep( '/^outpost_oauth_state_/', $this->transients ), 'One OAuth state is written.' );
	}

	/**
	 * The wp-admin session verifies a connection.
	 */
	public function test_the_wp_admin_session_verifies_a_connection(): void {
		$this->arrive_with_cookie( true );

		$this->assertSame( 200, $this->status( 'GET', '/outpost/v1/oauth/oura/verify' ) );
		$this->assertNotSame( array(), $this->outbound, 'Verification asks the provider.' );
	}

	/**
	 * The wp-admin session disconnects.
	 */
	public function test_the_wp_admin_session_disconnects(): void {
		$this->arrive_with_cookie( true );

		$this->assertSame( 200, $this->status( 'POST', '/outpost/v1/oauth/oura/disconnect' ) );
		$this->assertFalse( Outpost_Credentials_Store::is_configured( 'oura', $this->admin_id ), 'The credential is removed.' );
	}
}
