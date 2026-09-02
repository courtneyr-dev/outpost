<?php
/**
 * Unit tests for Outpost_Composer_Config_Endpoint.
 *
 * Covers the permission check, the resolution of post-formats from
 * theme support, and the response shape.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Composer_Config_Endpoint;
use WP_Mock;

final class ComposerConfigEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['REQUEST_URI'],
			$GLOBALS['wp']
		);
		WP_Mock::tearDown();
	}

	/** Model the route WordPress resolved (what it will dispatch). */
	private function set_resolved_route( ?string $route ): void {
		$wp             = new \stdClass();
		$wp->query_vars = array();
		if ( null !== $route ) {
			$wp->query_vars['rest_route'] = $route;
		}
		$GLOBALS['wp'] = $wp;
	}

	private function mock_is_wp_error(): void {
		if ( ! function_exists( 'is_wp_error' ) ) {
			WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
				static fn( $thing ) => $thing instanceof \WP_Error
			);
		}
	}

	private function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Outpost_Composer_Config_Endpoint::class, $method );
		return $ref->invoke( null, ...$args );
	}

	public function test_permission_check_requires_auth_by_default(): void {
		// As of v0.1.59 the endpoint requires auth by default — payload
		// aggregates plugin enumeration + taxonomy + Bridgy host map, which
		// makes anonymous plugin-version reconnaissance trivial. Mock all
		// three auth paths to false to simulate an unauthenticated visitor;
		// the filter then receives `false` and we leave it alone.
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )
			->with( false )
			->reply( false );
		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_permission_check_denies_anonymous_bearer_presence(): void {
		// Regression for the composer-config bearer-bypass recon finding.
		// An anonymous attacker sends `Authorization: Bearer x` (an
		// unvalidated, throwaway token). No cookie user, no cap, and the
		// IndieAuth plugin never translates the bogus bearer to a WP user.
		// Presence of the header alone must NOT authorize the request —
		// otherwise the payload (companion map, Bridgy host map, taxonomy
		// terms, site settings) leaks to unauthenticated callers.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		// Filter is a passthrough so the assertion reflects the raw
		// permission decision, not a filtered override.
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);

		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_permission_check_passes_for_user_with_edit_posts(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )
			->with( true )
			->reply( true );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_permission_check_denies_logged_in_user_without_edit_posts(): void {
		// wp.org plugin-review revision: a logged-in user without
		// edit_posts (a Subscriber) must not read composer config +
		// companion-plugin enumeration. The old check fell back to
		// is_user_logged_in(), which let Subscribers through. The
		// filter is a passthrough so the assertion reflects the raw
		// permission decision, not a filtered override.
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);

		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_permission_check_filter_can_open_anonymous(): void {
		// A site that wants build-time pre-fetch can opt back into anonymous
		// access via the filter.
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )
			->with( false )
			->reply( true );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_resolve_post_formats_returns_null_when_absent(): void {
		$result = $this->invoke_private( 'resolve_post_formats', array( 'absent' ) );
		$this->assertNull( $result );
	}

	public function test_resolve_post_formats_returns_null_when_inactive(): void {
		$result = $this->invoke_private( 'resolve_post_formats', array( 'inactive' ) );
		$this->assertNull( $result );
	}

	public function test_resolve_post_formats_returns_theme_subset_when_declared(): void {
		WP_Mock::userFunction( 'get_theme_support' )
			->once()
			->with( 'post-formats' )
			->andReturn( array( array( 'aside', 'image', 'gallery' ) ) );
		$result = $this->invoke_private( 'resolve_post_formats', array( 'active' ) );
		$this->assertEquals( array( 'aside', 'image', 'gallery' ), $result );
	}

	public function test_resolve_post_formats_returns_full_list_when_no_subset_declared(): void {
		WP_Mock::userFunction( 'get_theme_support' )
			->once()
			->with( 'post-formats' )
			->andReturn( true );
		$result = $this->invoke_private( 'resolve_post_formats', array( 'active' ) );
		$this->assertContains( 'aside', $result );
		$this->assertContains( 'gallery', $result );
		$this->assertContains( 'image', $result );
		$this->assertCount( 9, $result );
	}

	public function test_resolve_post_formats_filters_non_string_values(): void {
		WP_Mock::userFunction( 'get_theme_support' )
			->once()
			->with( 'post-formats' )
			->andReturn( array( array( 'aside', 42, null, 'image' ) ) );
		$result = $this->invoke_private( 'resolve_post_formats', array( 'active' ) );
		$this->assertEquals( array( 'aside', 'image' ), $result );
	}
	// --- allow_anonymous_for_self: route identity = resolved rest_route ----
	//
	// The late (priority 999) rest_authentication_errors filter exists so a
	// third-party "must be logged in" gate cannot stop the composer-config
	// request from reaching its own edit_posts permission callback. It must
	// key on the route WordPress will DISPATCH, never on REQUEST_URI. The
	// audited defect: a request to the composer-config PATH carrying
	// ?rest_route=/wp/v2/posts/5&_method=DELETE&_wpnonce=bogus had core's
	// invalid-nonce error cleared and executed the DELETE as the cookie user.

	public function test_csrf_regression_bogus_nonce_smuggled_through_composer_path_is_not_cleared(): void {
		$this->mock_is_wp_error();
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/5&_method=DELETE&_wpnonce=bogus';
		$this->set_resolved_route( '/wp/v2/posts/5' );
		$error = new \WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );

		$result = Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error );

		$this->assertSame( $error, $result, 'A nonce failure on a route we do not own must survive untouched.' );
	}

	public function test_third_party_gate_is_cleared_for_our_own_resolved_route(): void {
		$this->mock_is_wp_error();
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config';
		$this->set_resolved_route( '/outpost/v1/composer-config' );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked by a hardening plugin', array( 'status' => 401 ) );

		$this->assertNull( Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_third_party_gate_is_cleared_for_plain_permalink_form(): void {
		$this->mock_is_wp_error();
		$_SERVER['REQUEST_URI'] = '/?rest_route=/outpost/v1/composer-config';
		$this->set_resolved_route( '/outpost/v1/composer-config' );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked', array( 'status' => 401 ) );

		$this->assertNull( Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_trailing_slash_on_resolved_route_still_matches(): void {
		$this->mock_is_wp_error();
		$this->set_resolved_route( '/outpost/v1/composer-config/' );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked', array( 'status' => 401 ) );

		$this->assertNull( Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_core_invalid_nonce_error_is_never_cleared_even_on_our_own_route(): void {
		// Core's cookie-nonce check is WordPress's CSRF defense. The opt-out
		// exists for third-party blanket gates, not to disable that.
		$this->mock_is_wp_error();
		$this->set_resolved_route( '/outpost/v1/composer-config' );
		$error = new \WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );

		$this->assertSame( $error, Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_fails_closed_when_no_route_was_resolved(): void {
		$this->mock_is_wp_error();
		// Decoy: the path says composer-config, but WordPress resolved nothing.
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/composer-config';
		$this->set_resolved_route( null );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked', array( 'status' => 401 ) );

		$this->assertSame( $error, Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );

		unset( $GLOBALS['wp'] );
		$this->assertSame( $error, Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_route_smuggled_in_another_query_key_does_not_match(): void {
		$this->mock_is_wp_error();
		$_SERVER['REQUEST_URI'] = '/?rest_route=/wp/v2/users&x=rest_route=/outpost/v1/composer-config';
		$this->set_resolved_route( '/wp/v2/users' );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked', array( 'status' => 401 ) );

		$this->assertSame( $error, Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ) );
	}

	public function test_unrelated_route_passes_null_and_true_through_unchanged(): void {
		$this->mock_is_wp_error();
		$this->set_resolved_route( '/wp/v2/posts' );

		$this->assertNull( Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( null ) );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( true ) );
	}

	public function test_filter_never_consults_request_uri(): void {
		// Same resolved route, wildly different URIs: identical decisions.
		$this->mock_is_wp_error();
		$this->set_resolved_route( '/wp/v2/posts/5' );
		$error = new \WP_Error( 'rest_not_logged_in', 'blocked', array( 'status' => 401 ) );

		foreach ( array(
			'/wp-json/outpost/v1/composer-config',
			'/wp-json/outpost/v1/composer-config/',
			'/wp-json/outpost/v1/composer-config?rest_route=/wp/v2/posts/5',
			'/?rest_route=/outpost/v1/composer-config',
			'/wp-json/wp/v2/posts/5',
		) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertSame( $error, Outpost_Composer_Config_Endpoint::allow_anonymous_for_self( $error ), "URI must not matter: $uri" );
		}
	}
}
