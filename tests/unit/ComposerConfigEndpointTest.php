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

	public function test_permission_check_requires_edit_posts(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_posts' )
			->andReturn( true );
		$this->assertTrue( Outpost_Composer_Config_Endpoint::permission_check() );
	}

	public function test_permission_check_denies_unauthorized_user(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_posts' )
			->andReturn( false );
		$this->assertFalse( Outpost_Composer_Config_Endpoint::permission_check() );
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
