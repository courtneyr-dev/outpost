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
	 * The proven exploit: GET /geocode?q=Berlin&access_token=x from an
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
	 * Surgical guard: removing the query-string branches must not break the
	 * legitimate cookie/capability path.
	 */
	public function test_permission_granted_for_edit_posts(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission() );
	}

	/**
	 * Surgical guard: a real Authorization: Bearer header still authorizes.
	 * Only the query-string acceptance is removed, not the header path.
	 */
	public function test_permission_granted_for_real_bearer_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer live-token'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);

		$this->assertTrue( Outpost_Geocode_Endpoint::check_permission() );
	}
}
