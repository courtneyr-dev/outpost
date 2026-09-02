<?php
/**
 * Unit tests for Outpost_Request_Headers::resolved_rest_route() and
 * ::is_rest_route() — the one authoritative REST-route identity every
 * route-scoped security decision must key on.
 *
 * WordPress dispatches on `$GLOBALS['wp']->query_vars['rest_route']`, which
 * `$_GET`/`$_POST` override ahead of the `/wp-json/` rewrite. REQUEST_URI,
 * path substrings, and hand-parsed query strings are therefore not route
 * identity. These tests pin the helper's normalization and its fail-closed
 * behavior when no route was resolved.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Request_Headers;
use WP_Mock;

final class RequestHeadersResolvedRouteTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		unset( $GLOBALS['wp'], $_SERVER['REQUEST_URI'] );
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp'], $_SERVER['REQUEST_URI'] );
		WP_Mock::tearDown();
	}

	/**
	 * @param mixed $route Value to place in query_vars['rest_route'], or the
	 *                     sentinel 'ABSENT' to leave the key out entirely.
	 */
	private function set_resolved_route( $route ): void {
		$wp             = new \stdClass();
		$wp->query_vars = array();
		if ( 'ABSENT' !== $route ) {
			$wp->query_vars['rest_route'] = $route;
		}
		$GLOBALS['wp'] = $wp;
	}

	// --- resolved_rest_route() ------------------------------------------

	public function test_null_when_wp_global_is_absent(): void {
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
		$this->assertTrue( true );
	}

	public function test_null_when_query_vars_is_not_an_array(): void {
		$wp             = new \stdClass();
		$wp->query_vars = 'not-an-array';
		$GLOBALS['wp']  = $wp;
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_null_when_rest_route_key_is_absent(): void {
		$this->set_resolved_route( 'ABSENT' );
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_null_for_empty_and_whitespace_routes(): void {
		$this->set_resolved_route( '' );
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
		$this->set_resolved_route( "  \t" );
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_null_for_non_string_route(): void {
		$this->set_resolved_route( array( '/outpost/v1/composer-config' ) );
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
		$this->set_resolved_route( 42 );
		$this->assertNull( Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_adds_leading_slash(): void {
		$this->set_resolved_route( 'outpost/v1/composer-config' );
		$this->assertSame( '/outpost/v1/composer-config', Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_strips_trailing_slash(): void {
		$this->set_resolved_route( '/outpost/v1/composer-config/' );
		$this->assertSame( '/outpost/v1/composer-config', Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_exact_route_is_returned_unchanged(): void {
		$this->set_resolved_route( '/wp/v2/posts/5' );
		$this->assertSame( '/wp/v2/posts/5', Outpost_Request_Headers::resolved_rest_route() );
	}

	// --- is_rest_route() -------------------------------------------------

	public function test_is_rest_route_matches_exactly(): void {
		$this->set_resolved_route( '/outpost/v1/composer-config' );
		$this->assertTrue( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
		// Callers may pass the route with or without the leading slash.
		$this->assertTrue( Outpost_Request_Headers::is_rest_route( 'outpost/v1/composer-config' ) );
	}

	public function test_is_rest_route_normalizes_trailing_slash_on_both_sides(): void {
		$this->set_resolved_route( '/outpost/v1/composer-config/' );
		$this->assertTrue( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
		$this->assertTrue( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config/' ) );
	}

	public function test_is_rest_route_rejects_a_different_route(): void {
		$this->set_resolved_route( '/wp/v2/posts/5' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
	}

	public function test_is_rest_route_rejects_prefix_and_suffix_variants(): void {
		$this->set_resolved_route( '/outpost/v1/composer-config-extra' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
		$this->set_resolved_route( '/x/outpost/v1/composer-config' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
	}

	public function test_is_rest_route_fails_closed_when_no_route_resolved(): void {
		$this->set_resolved_route( 'ABSENT' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
		unset( $GLOBALS['wp'] );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
	}

	public function test_request_uri_never_influences_route_identity(): void {
		// The raw URI says composer-config; WordPress resolved /wp/v2/posts.
		// This is the CSRF shape: identity must come from the resolved route.
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/5&_wpnonce=bogus';
		$this->set_resolved_route( '/wp/v2/posts/5' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
		$this->assertSame( '/wp/v2/posts/5', Outpost_Request_Headers::resolved_rest_route() );
	}

	public function test_route_smuggled_in_another_query_key_is_ignored(): void {
		// /?rest_route=/wp/v2/users&x=rest_route=/outpost/v1/composer-config
		// WordPress resolves rest_route from its own query var only.
		$_SERVER['REQUEST_URI'] = '/?rest_route=/wp/v2/users&x=rest_route=/outpost/v1/composer-config';
		$this->set_resolved_route( '/wp/v2/users' );
		$this->assertFalse( Outpost_Request_Headers::is_rest_route( '/outpost/v1/composer-config' ) );
	}
}
