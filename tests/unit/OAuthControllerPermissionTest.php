<?php
/**
 * Outpost_OAuth_Controller::permission_check() unit tests.
 *
 * `/oauth/{provider}/start`, `/disconnect` and `/verify` are wp-admin
 * actions gated on `manage_options`. IndieAuth's map_meta_cap never
 * restricts `manage_options`, so without a bearer check an admin's token
 * of any scope could call them. The routes refuse every bearer request;
 * a cookie session with a valid REST nonce, and a request with no
 * credential, are decided by the capability check as before.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_OAuth_Controller;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthControllerPermissionTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// Outpost_Request_Headers sanitizes every $_SERVER and $_REQUEST read.
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			static fn ( $nonce, $action ) => 'valid-rest-nonce' === $nonce && 'wp_rest' === $action ? 1 : false
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_REQUEST['_wpnonce'],
			$GLOBALS['wp_rest_auth_cookie']
		);
	}

	/**
	 * @return array<string, array{0: string[]}>
	 */
	public static function token_scopes(): array {
		return array(
			'profile only' => array( array( 'profile' ) ),
			'every scope'  => array( array( 'create', 'update', 'delete', 'undelete', 'media', 'draft', 'read', 'profile', 'email' ) ),
		);
	}

	/**
	 * An admin whose IndieAuth token verified: IndieAuth leaves the token's
	 * response and scopes on its filters, and core never sets the
	 * auth-cookie flag for a token-resolved user.
	 *
	 * @dataProvider token_scopes
	 *
	 * @param string[] $scopes Token scopes.
	 */
	public function test_refuses_an_admin_bearer_token_of_any_scope( array $scopes ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply(
			array(
				'scope' => implode( ' ', $scopes ),
				'user'  => 1,
			)
		);
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( $scopes );

		$this->assertFalse(
			Outpost_OAuth_Controller::permission_check( new \WP_REST_Request( 'POST', '/outpost/v1/oauth/notion/disconnect' ) )
		);
	}

	public function test_allows_an_admin_cookie_session_with_a_valid_rest_nonce(): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_REQUEST['_wpnonce']           = 'valid-rest-nonce';

		$this->assertTrue(
			Outpost_OAuth_Controller::permission_check( new \WP_REST_Request( 'POST', '/outpost/v1/oauth/notion/disconnect' ) )
		);
	}

	public function test_leaves_a_request_with_no_credential_to_the_capability_check(): void {
		$this->assertTrue(
			Outpost_OAuth_Controller::permission_check( new \WP_REST_Request( 'GET', '/outpost/v1/oauth/notion/start' ) )
		);
	}
}
