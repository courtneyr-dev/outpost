<?php
/**
 * Unit tests for Outpost_Companion_Registry + the concrete adapters.
 *
 * Exercises:
 *   - all() returns one instance per default adapter class
 *   - get() caches per class — same class returns same instance
 *   - Adapter file() / label() / capabilities() shapes
 *   - all_active_capabilities() de-duplicates and sorts
 *   - The outpost_companion_adapters filter prunes unknown classes
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Companion_Registry;
use Outpost_Companion_Base;
use Outpost_Post_Kinds_Adapter;
use Outpost_XFN_Adapter;
use Outpost_Yoast_Adapter;
use WP_Mock;

final class CompanionRegistryTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Companion_Registry::reset_for_tests();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Companion_Registry::reset_for_tests();
	}

	public function test_all_returns_seven_default_adapters(): void {
		$adapters = Outpost_Companion_Registry::all();
		$this->assertCount( 7, $adapters );
		foreach ( $adapters as $adapter ) {
			$this->assertInstanceOf( Outpost_Companion_Base::class, $adapter );
		}
	}

	public function test_get_caches_by_class(): void {
		$first  = Outpost_Companion_Registry::get( Outpost_XFN_Adapter::class );
		$second = Outpost_Companion_Registry::get( Outpost_XFN_Adapter::class );
		$this->assertSame( $first, $second );
	}

	public function test_post_kinds_adapter_shape(): void {
		$adapter = new Outpost_Post_Kinds_Adapter();
		$this->assertSame( OUTPOST_POST_KINDS_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Post Kinds for IndieWeb', $adapter->label() );
		$caps = $adapter->capabilities();
		$this->assertContains( 'post-kinds.listen', $caps );
		$this->assertContains( 'post-kinds.like', $caps );
		$this->assertContains( 'post-kinds.quotation', $caps );
	}

	public function test_xfn_adapter_shape(): void {
		$adapter = new Outpost_XFN_Adapter();
		$this->assertSame( OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Link Extension for XFN', $adapter->label() );
		$this->assertSame( array( 'xfn.relationships' ), $adapter->capabilities() );
	}

	public function test_yoast_adapter_shape(): void {
		$adapter = new Outpost_Yoast_Adapter();
		$this->assertSame( OUTPOST_YOAST_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Yoast SEO', $adapter->label() );
		$this->assertSame( array( 'yoast.focus-keyphrase' ), $adapter->capabilities() );
	}

	public function test_all_active_capabilities_dedupes_and_sorts(): void {
		// Stub is_plugin_active so every adapter reports active.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		$caps = Outpost_Companion_Registry::all_active_capabilities();
		// Result is sorted alphabetically and each cap appears exactly once.
		$this->assertSame( $caps, array_values( array_unique( $caps ) ) );
		$sorted = $caps;
		sort( $sorted );
		$this->assertSame( $sorted, $caps );
		// Spot-check known caps are present.
		$this->assertContains( 'xfn.relationships', $caps );
		$this->assertContains( 'yoast.focus-keyphrase', $caps );
		$this->assertContains( 'post-kinds.listen', $caps );
	}
}
