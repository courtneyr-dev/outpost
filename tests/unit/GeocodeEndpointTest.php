<?php
/**
 * Unit tests for Outpost_Geocode_Endpoint's permission callback.
 *
 * Regression coverage for the anonymous open-proxy vulnerability: the
 * endpoint used to treat the mere presence of a `?access_token=` or
 * `?_o_token=` query-string parameter as sufficient authorization, which
 * turned the site into an unauthenticated proxy to OpenStreetMap Nominatim
 * (third-party API abuse) and re-introduced the token-in-URL leak this repo
 * had already fixed once. A query-string token validates nothing and must
 * never grant access.
 *
 * Coverage mirrors MediaLookupEndpointTest / PreviewEndpointTest: the
 * permission callback is exercised in isolation with WP_Mock; no live REST
 * server and no outbound HTTP.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Geocode_Endpoint;
use WP_Error;
use WP_Mock;

final class GeocodeEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		unset(
			$_GET['access_token'],
			$_GET['_o_token'],
			$_POST['access_token'],
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION']
		);
		WP_Mock::tearDown();
	}

	/**
	 * Prime the anonymous baseline: no cookie session, no WP capability, and
	 * the permission filter passes the decision through unchanged so the test
	 * asserts on the endpoint's own logic rather than a filter override.
	 */
	private function prime_anonymous(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
	}

	/**
	 * Register apply_filters so the permission callback can call the
	 * `determine_current_user` and `outpost_geocode_permission` hooks.
	 *
	 * @param int|false $determine_user Value IndieAuth's determine_current_user
	 *                                  resolves the bearer token to. `false`
	 *                                  simulates an invalid/rejected token.
	 */
	private function mock_filters( $determine_user ): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $hook, $value ) use ( $determine_user ) {
				if ( 'determine_current_user' === $hook ) {
					return $determine_user;
				}
				return $value;
			}
		);
	}

	/**
	 * The geocode route accepts POST requests so credentials can stay in the
	 * request body instead of the URL.
	 */
	public function test_register_route_uses_post(): void {
		$registered_args = array();
		WP_Mock::userFunction(
			'register_rest_route',
			array(
				'times'  => 1,
				'return' => static function ( $namespace, $route, $args ) use ( &$registered_args ): bool {
					$registered_args = $args;
					return true;
				},
			)
		);

		Outpost_Geocode_Endpoint::register_route();

		$this->assertSame( 'POST', $registered_args['methods'] ?? null );
		$this->assertArrayHasKey( 'q', $registered_args['args'] ?? array() );
		$this->assertTrue( $registered_args['args']['q']['required'] ?? false );
	}

	/**
	 * The proven exploit: /geocode?q=Berlin&access_token=x from an
	 * anonymous caller. A query-string token must be rejected with 401.
	 */
	public function test_permission_denied_for_query_string_access_token(): void {
		$_GET['access_token'] = 'x'; // outpost-lint:fixture-credential
		$this->prime_anonymous();

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * The same exploit via the legacy `_o_token` query-string parameter.
	 */
	public function test_permission_denied_for_query_string_o_token(): void {
		$_GET['_o_token'] = 'anything'; // outpost-lint:fixture-credential
		$this->prime_anonymous();

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Control: a fully anonymous request with no token at all is denied.
	 */
	public function test_permission_denied_when_fully_unauthenticated(): void {
		$this->prime_anonymous();

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Regression: the reported anonymous-open-proxy auth bypass.
	 *
	 * An unauthenticated caller presenting a syntactically-valid but
	 * bogus `Authorization: Bearer x` header must NOT pass the permission
	 * gate. Before the fix, bearer-header *presence* alone returned true,
	 * opening the server-side fetcher to anonymous callers.
	 */
	public function test_check_permission_rejects_unvalidated_bearer_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		// IndieAuth rejects the bogus token: determine_current_user resolves nobody.
		$this->mock_filters( false );

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * Regression: same bypass via the Micropub body-token fallback.
	 *
	 * `{"access_token":"x", ...}` in the body must also be validated, not
	 * accepted on presence.
	 */
	public function test_check_permission_rejects_unvalidated_body_token(): void {
		$_POST['access_token'] = 'x'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		$this->mock_filters( false );

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * A valid bearer token that IndieAuth resolves to an editor is allowed —
	 * the fix must not break the legitimate PWA flow.
	 */
	public function test_check_permission_allows_validated_bearer_editor(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		// determine_current_user validates the token to user 42, who can edit_posts.
		$this->mock_filters( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission() );
	}

	/**
	 * A cookie-authenticated editor (no bearer) is allowed.
	 */
	public function test_check_permission_allows_cookie_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$this->mock_filters( false );

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission() );
	}

	/**
	 * A logged-in non-editor (e.g. subscriber, no bearer) is now rejected —
	 * the dropped is_user_logged_in() OR-leg no longer opens the fetcher to
	 * every authenticated user.
	 */
	public function test_check_permission_rejects_logged_in_non_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		$this->mock_filters( false );

		$result = Outpost_Geocode_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}
}
