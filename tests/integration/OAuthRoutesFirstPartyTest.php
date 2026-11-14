<?php
/**
 * OAuth start, verify, and disconnect are wp-admin actions only
 * (T10-S4-11, Phase 4 Antigravity A-01).
 *
 * These routes read or remove a person's connected-account credential.
 * T10-S3-06 limited connected-account reads to the person's own wp-admin
 * session or the Outpost app; these routes accepted any non-bearer
 * credential, including an application password made for another app.
 * They now need a first-party cookie session with a valid REST nonce, the
 * way wp-admin calls them (the start link carries `_wpnonce`).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class OAuthRoutesFirstPartyTest extends TestCase {

	private int $admin_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** @var mixed */
	private $saved_app_password = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_OAuth_Controller' ) || ! function_exists( 'rest_get_server' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_server       = $_SERVER;
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
		rest_get_server();
	}

	protected function tearDown(): void {
		$_SERVER                                      = $this->saved_server;
		$GLOBALS['wp_rest_auth_cookie']               = $this->saved_auth_cookie;
		$GLOBALS['wp_rest_application_password_uuid'] = $this->saved_app_password;
		wp_set_current_user( 0 );
		if ( $this->admin_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->admin_id );
		}
		parent::tearDown();
	}

	private function arrive_with_application_password(): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie']               = false;
		$GLOBALS['wp_rest_application_password_uuid'] = '00000000-0000-4000-8000-000000000000';
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'synthetic:not-a-real-password' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function arrive_with_cookie( bool $valid_nonce ): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie']               = true;
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WP_NONCE'] );
		if ( $valid_nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		}
	}

	private function status( string $method, string $route ): int {
		return rest_do_request( new \WP_REST_Request( $method, $route ) )->get_status();
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
	public function test_an_application_password_is_refused( string $method, string $route ): void {
		$this->arrive_with_application_password();

		$this->assertContains( $this->status( $method, $route ), array( 401, 403 ), 'An application password is not a wp-admin session.' );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_a_cookie_session_without_a_nonce_is_refused( string $method, string $route ): void {
		$this->arrive_with_cookie( false );

		$this->assertContains( $this->status( $method, $route ), array( 401, 403 ) );
	}

	/**
	 * @dataProvider routes
	 */
	public function test_the_admin_wp_admin_session_is_allowed( string $method, string $route ): void {
		$this->arrive_with_cookie( true );

		$this->assertNotContains( $this->status( $method, $route ), array( 401, 403 ), 'The wp-admin session keeps working.' );
	}
}
