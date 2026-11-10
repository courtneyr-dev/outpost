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
		// Outpost_Request_Headers sanitizes every $_SERVER read.
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
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

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

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

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

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

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

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

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * wp.org review 2026-09-14: the permission callback never resolves or
	 * switches users. A JSON-body token WordPress didn't resolve leaves the
	 * request anonymous, and the geocode route refuses it with 401.
	 */
	public function test_check_permission_refuses_a_body_token_wordpress_did_not_resolve(): void {
		$request = new \WP_REST_Request( 'POST', '/' );
		$request->set_body( '{"access_token":"x"}' ); // outpost-lint:fixture-credential
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_set_current_user' )->never();
		// Even a token IndieAuth would accept: the callback never asks.
		$this->mock_filters( 42 );

		$result = Outpost_Geocode_Endpoint::check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
		$this->assertArrayNotHasKey( 'HTTP_AUTHORIZATION', $_SERVER, 'The callback must not write a request header.' );
	}

	/**
	 * A valid bearer token that IndieAuth resolves to an editor is allowed —
	 * the fix must not break the legitimate PWA flow.
	 */
	public function test_check_permission_allows_validated_bearer_editor(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		// IndieAuth resolved the header token during WordPress's own user
		// resolution, before the route ran.
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'wp_set_current_user' )->never();
		$this->mock_filters( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		// H6: bearer_has_scope() reads indieauth_scopes via the real
		// apply_filters() shim (WP_Mock::onFilter), not the userFunction
		// mock above — mock_filters()'s wholesale apply_filters override is
		// inert for this call (see "Scope source" in trait-bearer-auth.php).
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'read' ) );

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	/**
	 * H6 fix round 1, Important: an under-scoped (but otherwise validated)
	 * bearer token is refused, distinct from an unvalidated-token refusal.
	 */
	public function test_check_permission_refuses_under_scoped_bearer_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_set_current_user' )->never();
		$this->mock_filters( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'profile' ) );

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * A cookie-authenticated editor (no bearer) is allowed.
	 */
	public function test_check_permission_allows_cookie_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$this->mock_filters( false );

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) ) );
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

		$result = Outpost_Geocode_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	// --- wp.org review 2026-09-14: json_decode output is validated ---------

	/**
	 * setUp() already mocks sanitize_text_field() as trim(), so a trimmed
	 * display name proves the field went through it.
	 */
	private function mock_field_sanitizers(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static fn( $v ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) )
		);
	}

	/**
	 * @dataProvider invalid_nominatim_bodies
	 */
	public function test_parse_results_fails_closed_on_invalid_json( string $body ): void {
		$this->mock_field_sanitizers();

		$result = Outpost_Geocode_Endpoint::parse_results( $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'geocode_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_nominatim_bodies(): array {
		return array(
			'empty body'         => array( '' ),
			'truncated JSON'     => array( '[{"lat":"1","lon":"2"' ),
			'HTML error page'    => array( '<html><body>502 Bad Gateway</body></html>' ),
			'JSON scalar'        => array( '"ok"' ),
			'JSON object'        => array( '{"lat":"1","lon":"2","display_name":"x"}' ),
			'nested too deep'    => array( str_repeat( '[', 40 ) . str_repeat( ']', 40 ) ),
			'over the size cap'  => array( '[' . str_repeat( ' ', 1048576 ) . ']' ),
		);
	}

	public function test_parse_results_keeps_valid_places_and_sanitizes_strings(): void {
		$this->mock_field_sanitizers();
		$body = (string) json_encode(
			array(
				array(
					'lat'          => '41.8781',
					'lon'          => '-87.6298',
					'display_name' => "  Chicago, Illinois\t",
					'type'         => 'City"><img>',
				),
			)
		);

		$result = Outpost_Geocode_Endpoint::parse_results( $body );

		$this->assertSame(
			array(
				array(
					'lat'         => 41.8781,
					'lon'         => -87.6298,
					'displayName' => 'Chicago, Illinois',
					'type'        => 'cityimg',
				),
			),
			$result
		);
	}

	public function test_parse_results_skips_entries_with_bad_types_or_ranges(): void {
		$this->mock_field_sanitizers();
		$body = (string) json_encode(
			array(
				'not an object',
				array( 'lat' => 'north', 'lon' => '2', 'display_name' => 'Words for numbers' ),
				array( 'lat' => '91', 'lon' => '2', 'display_name' => 'Latitude past the pole' ),
				array( 'lat' => '1', 'lon' => '-181', 'display_name' => 'Longitude past the antimeridian' ),
				array( 'lat' => '1', 'lon' => '2', 'display_name' => array( 'nested' ) ),
				array( 'lat' => array( 1 ), 'lon' => '2', 'display_name' => 'Array latitude' ),
				array( 'lat' => '1', 'lon' => '2', 'display_name' => "   \n" ),
				array( 'lat' => 10, 'lon' => 20.5, 'display_name' => 'Numeric coordinates', 'type' => array( 'x' ) ),
			)
		);

		$result = Outpost_Geocode_Endpoint::parse_results( $body );

		$this->assertSame(
			array(
				array(
					'lat'         => 10.0,
					'lon'         => 20.5,
					'displayName' => 'Numeric coordinates',
					'type'        => '',
				),
			),
			$result
		);
	}
}
