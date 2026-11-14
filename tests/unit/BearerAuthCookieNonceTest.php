<?php
/**
 * Outpost_Bearer_Auth::bearer_has_scope(): explicit cookie-nonce gate (T10-S3-02).
 *
 * The WordPress.org review (T10) asks that a required nonce fail early when it
 * is missing or invalid, instead of relying on another layer. When WordPress
 * authenticated the request with the wp-admin cookie (`$wp_rest_auth_cookie`
 * is true), Outpost now refuses the request itself unless the REST nonce is
 * present and valid. It no longer depends on core's
 * `rest_cookie_check_errors()` having run, which a third-party
 * `rest_authentication_errors` callback can skip. Bearer tokens and
 * Application Passwords never set that flag, so they keep their own rules.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use WP_Mock;
use WP_Mock\Tools\TestCase;

final class BearerAuthCookieNonceTest extends TestCase {

	/** Number of wp_verify_nonce() calls in the current test. */
	private int $verify_calls = 0;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->verify_calls = 0;
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			function ( $nonce, $action ) {
				++$this->verify_calls;
				if ( 'wp_rest' !== $action ) {
					return false;
				}
				if ( 'current-tick-nonce' === $nonce ) {
					return 1;
				}
				if ( 'previous-tick-nonce' === $nonce ) {
					return 2;
				}
				return false;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['HTTP_X_WP_NONCE'],
			$_REQUEST['_wpnonce'],
			$GLOBALS['wp_rest_auth_cookie']
		);
	}

	/**
	 * Call the protected trait method with a scope list of `create`/`update`.
	 */
	private static function check(): bool {
		$probe = new class() {
			use \Outpost_Bearer_Auth;

			public static function run( \WP_REST_Request $request ): bool {
				return self::bearer_has_scope( $request, array( 'create', 'update' ) );
			}
		};
		return $probe::run( new \WP_REST_Request( 'POST', '/outpost/v1/appearance/tokens' ) );
	}

	/** IndieAuth verified a token with these scopes. */
	private static function verified_token( array $scopes ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply( array( 'scope' => implode( ' ', $scopes ) ) );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( $scopes );
	}

	public function test_cookie_session_with_a_current_nonce_passes(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'current-tick-nonce';
		$this->assertTrue( self::check() );
	}

	public function test_cookie_session_with_a_previous_tick_nonce_passes(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_REQUEST['_wpnonce']           = 'previous-tick-nonce';
		$this->assertTrue( self::check(), 'wp_verify_nonce() returns 2 for a nonce from the previous tick; it is valid.' );
	}

	public function test_cookie_session_with_no_nonce_fails_without_verifying(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$this->assertFalse( self::check(), 'A cookie session that sends no REST nonce must be refused by Outpost itself.' );
		$this->assertSame( 0, $this->verify_calls, 'A missing nonce fails early, before wp_verify_nonce().' );
	}

	public function test_cookie_session_with_an_invalid_nonce_fails(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'forged';
		$this->assertFalse( self::check() );
	}

	public function test_cookie_session_with_no_nonce_fails_even_with_a_bearer_header(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_AUTHORIZATION']  = 'Bearer unverified'; // outpost-lint:fixture-credential
		$this->assertFalse( self::check(), 'A header that rides along does not excuse a cookie session from its nonce.' );
	}

	public function test_cookie_session_with_a_valid_nonce_passes_with_a_stray_bearer_header(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'current-tick-nonce';
		$_SERVER['HTTP_AUTHORIZATION']  = 'Bearer unverified'; // outpost-lint:fixture-credential
		$this->assertTrue( self::check() );
	}

	public function test_verified_bearer_token_with_a_listed_scope_passes_without_a_nonce(): void {
		self::verified_token( array( 'create' ) );
		$this->assertTrue( self::check(), 'Bearer requests never need a cookie nonce.' );
		$this->assertSame( 0, $this->verify_calls );
	}

	public function test_verified_bearer_token_without_a_listed_scope_fails(): void {
		self::verified_token( array( 'read' ) );
		$this->assertFalse( self::check() );
	}

	public function test_application_password_request_is_left_to_the_capability_check(): void {
		// Basic auth (an Application Password) never sets $wp_rest_auth_cookie.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz'; // outpost-lint:fixture-credential
		$this->assertTrue( self::check() );
		$this->assertSame( 0, $this->verify_calls, 'No cookie nonce is required for Application Password requests.' );
	}

	public function test_request_with_no_credential_is_left_to_the_capability_check(): void {
		$this->assertTrue( self::check() );
	}
}
