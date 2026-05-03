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
		WP_Mock::tearDown();
	}

	private function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Outpost_Composer_Config_Endpoint::class, $method );
		$ref->setAccessible( true );
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

	public function test_permission_check_passes_for_logged_in_user(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_composer_config_permission' )
			->with( true )
			->reply( true );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check() );
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
}
