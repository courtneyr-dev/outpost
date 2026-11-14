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
use Outpost_Abilities;
use WP_Mock;

final class ComposerConfigEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// Outpost_Request_Headers sanitizes every $_SERVER read.
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		// WP_Mock filtersWithAnyArgs leak workaround (CLAUDE.md A2 #8).
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['REQUEST_URI'],
			$_SERVER['HTTP_X_WP_NONCE'],
			$_REQUEST['_wpnonce'],
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
		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
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

		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	/**
	 * H6 fix round 1, Important: an under-scoped (but otherwise validated)
	 * bearer token is refused, distinct from an unvalidated-token refusal.
	 */
	public function test_permission_check_refuses_under_scoped_bearer_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_set_current_user' )->never();
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'profile' ) );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )->with( false )->reply( false );

		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	public function test_permission_check_passes_for_user_with_edit_posts(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )
			->with( true )
			->reply( true );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
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

		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
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
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check( new \WP_REST_Request( 'POST', '/' ) ) );
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

	public function test_rest_response_is_identical_to_builder_payload(): void {
		$this->mock_config_dependencies();
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );

		$response = Outpost_Composer_Config_Endpoint::handle();

		$this->assertSame( Outpost_Composer_Config_Endpoint::build_payload(), $response->get_data() );
	}

	public function test_composer_config_ability_does_not_touch_rate_limit_transient(): void {
		$this->mock_config_dependencies();
		WP_Mock::userFunction( 'get_transient' )->never();
		WP_Mock::userFunction( 'set_transient' )->never();

		$payload = Outpost_Abilities::execute_get_composer_config( array() );

		$this->assertArrayHasKey( 'companions', $payload );
		$this->assertArrayHasKey( 'siteSettings', $payload );
	}

	private function mock_config_dependencies(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::userFunction( 'taxonomy_exists' )->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn ( string $name, $default = false ) => $default
		);
		WP_Mock::onFilter( 'outpost_bridgy_host_map' )->withAnyArgs()->reply( array() );
	}
	// --- No authentication override (1.0.27) --------------------------------

	/**
	 * Earlier versions cleared other plugins' rest_authentication_errors
	 * results for this route. That overrode another plugin's security
	 * decision, so 1.0.27 removed it: register() hooks the route and nothing
	 * else, and no override method remains.
	 */
	public function test_register_adds_no_rest_authentication_errors_filter(): void {
		outpost_test_reset_hooks();
		Outpost_Composer_Config_Endpoint::register();
		$this->assertSame(
			array( array( Outpost_Composer_Config_Endpoint::class, 'register_route' ) ),
			array_column( outpost_test_actions_for( 'rest_api_init' ), 'callback' )
		);
		$this->assertFalse(
			method_exists( Outpost_Composer_Config_Endpoint::class, 'allow_anonymous_for_self' ),
			'The authentication override must stay removed.'
		);
	}
}
