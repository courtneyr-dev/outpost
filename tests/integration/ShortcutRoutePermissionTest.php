<?php
/**
 * The Shortcut route fails closed on a cookie session without a REST nonce
 * (T10-S4-14, Phase 4 Antigravity A-04).
 *
 * Every other authenticated Outpost route refuses a cookie session that
 * lacks a valid `wp_rest` nonce on its own, without relying on core's
 * rest_cookie_check_errors() (which a third-party filter can skip). The
 * Shortcut route only checked the login and edit_posts. The Shortcut itself
 * signs in with an application password, which keeps working.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ShortcutRoutePermissionTest extends TestCase {

	private int $editor_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** @var mixed */
	private $saved_app_password = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_IOS_Shortcut_REST_Controller' ) || ! function_exists( 'rest_get_server' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_server       = $_SERVER;
		$this->saved_request      = $_REQUEST;
		$this->saved_auth_cookie  = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->saved_app_password = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
		$this->editor_id          = (int) wp_insert_user(
			array(
				'user_login' => 's414_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's414_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		rest_get_server();
	}

	protected function tearDown(): void {
		$_SERVER                                      = $this->saved_server;
		$_REQUEST                                     = $this->saved_request;
		$GLOBALS['wp_rest_auth_cookie']               = $this->saved_auth_cookie;
		$GLOBALS['wp_rest_application_password_uuid'] = $this->saved_app_password;
		wp_set_current_user( 0 );
		if ( $this->editor_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->editor_id );
		}
		parent::tearDown();
	}

	private function response(): \WP_REST_Response {
		unset( $_REQUEST['_wpnonce'] );
		$request = new \WP_REST_Request( 'POST', '/outpost/v1/shortcut' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'url' => 'https://example.org/article' ) ) );
		return rest_do_request( $request );
	}

	private function post(): int {
		return $this->response()->get_status();
	}

	public function test_the_shortcut_application_password_still_works(): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie']               = false;
		$GLOBALS['wp_rest_application_password_uuid'] = '00000000-0000-4000-8000-000000000000';
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'synthetic:not-a-real-password' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertSame( 200, $this->post() );
	}

	public function test_a_cookie_session_with_a_valid_nonce_works(): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

		$this->assertSame( 200, $this->post() );
	}

	public function test_a_cookie_session_without_a_nonce_is_refused(): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WP_NONCE'] );

		$response = $this->response();
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'outpost_ios_shortcut_unauthorized', $response->get_data()['code'] ?? null, 'Refused by the route itself, not a later step.' );
	}
}
