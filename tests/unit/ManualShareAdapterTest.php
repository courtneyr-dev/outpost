<?php
/**
 * Unit tests for Outpost_Manual_Share_Adapter (F9).
 *
 * Verifies the umbrella always reports active, capabilities() returns
 * null (umbrella does not surface a single chip), platform_chips()
 * returns one chip per registered platform, and the Bridgy-defer logic
 * filters Reddit/Flickr chips when the filter signals Bridgy is
 * configured.
 *
 * Also verifies Outpost_Companion_Registry::chips_for_mode() now
 * enumerates platform_chips() alongside the F2 capabilities() chip,
 * and per-mode filtering applies to platform-level accepts_modes.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Adapter;
use Outpost_Manual_Share_Platform_Registry;
use Outpost_Companion_Registry;
use WP_Mock;

final class ManualShareAdapterTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		Outpost_Companion_Registry::reset_for_tests();
	}

	public function test_file_returns_outpost_basename_so_detector_reports_active(): void {
		$adapter = new Outpost_Manual_Share_Adapter();
		$this->assertSame( OUTPOST_PLUGIN_BASENAME, $adapter->file() );
	}

	public function test_label_is_brand_name_not_translated(): void {
		$adapter = new Outpost_Manual_Share_Adapter();
		$this->assertSame( 'Manual Share', $adapter->label() );
	}

	public function test_feature_slugs_is_empty(): void {
		$adapter = new Outpost_Manual_Share_Adapter();
		$this->assertSame( array(), $adapter->feature_slugs() );
	}

	public function test_capabilities_returns_null_so_umbrella_is_not_a_chip(): void {
		// The umbrella itself is NOT a syndicate-to chip; chips come
		// from platform_chips() instead. Returning null prevents
		// Outpost_Micropub_Bridges::merge_syndicate_chips from
		// generating an "umbrella" entry in the [uid, name] list.
		$adapter = new Outpost_Manual_Share_Adapter();
		$this->assertNull( $adapter->capabilities() );
	}

	public function test_platform_chips_returns_one_chip_per_default_platform(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// Don't mock outpost_manual_share_defer_to_bridgy — WP_Mock
		// returns the apply_filters default (false) when unregistered,
		// which is exactly what F9's default behavior requires.

		$adapter = new Outpost_Manual_Share_Adapter();
		$chips   = $adapter->platform_chips();

		$this->assertCount( 10, $chips );
		foreach ( $chips as $chip ) {
			$this->assertArrayHasKey( 'id', $chip );
			$this->assertArrayHasKey( 'manual_share', $chip );
			$this->assertTrue( $chip['detected'] );
		}
	}

	public function test_bridgy_defer_filter_hides_reddit_and_flickr_chips(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		WP_Mock::onFilter( 'outpost_manual_share_defer_to_bridgy' )
			->withAnyArgs()
			->reply( true );

		$adapter = new Outpost_Manual_Share_Adapter();
		$chips   = $adapter->platform_chips();

		$ids = array_column( $chips, 'id' );
		$this->assertNotContains( 'reddit-manual', $ids );
		$this->assertNotContains( 'flickr-manual', $ids );
		$this->assertCount( 8, $chips );
		// Visual platforms still present.
		$this->assertContains( 'instagram-feed', $ids );
		$this->assertContains( 'facebook', $ids );
		$this->assertContains( 'pinterest', $ids );
	}

	public function test_bridgy_defer_default_is_false_when_filter_unhooked(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// outpost_manual_share_defer_to_bridgy default-false (no mock).

		$adapter = new Outpost_Manual_Share_Adapter();
		$ids     = array_column( $adapter->platform_chips(), 'id' );

		$this->assertContains( 'reddit-manual', $ids );
		$this->assertContains( 'flickr-manual', $ids );
	}

	// =====================================================================
	// Companion_Registry integration: chips_for_mode enumerates platform_chips
	// =====================================================================

	public function test_chips_for_mode_photo_includes_manual_share_platforms(): void {
		// Restrict default adapters to ManualShare only — keeps the test
		// deterministic against ActivityPub state.
		// Umbrella's file() returns OUTPOST_PLUGIN_BASENAME, which must
		// report active so is_active() returns true. All other plugin
		// files return false so the registry won't try to instantiate
		// other companions.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// outpost_manual_share_defer_to_bridgy default-false (no mock).

		$chips = Outpost_Companion_Registry::chips_for_mode( 'photo' );
		$ids   = array_column( $chips, 'id' );

		$this->assertContains( 'instagram-feed', $ids );
		$this->assertContains( 'facebook', $ids );
		$this->assertContains( 'x-twitter', $ids );
		$this->assertContains( 'linkedin', $ids );
		$this->assertContains( 'threads', $ids );
		$this->assertContains( 'tiktok', $ids );
		$this->assertContains( 'pinterest', $ids );
	}

	public function test_chips_for_mode_listen_excludes_manual_share_platforms(): void {
		// None of the 10 default platforms accept Listen mode → manual-
		// share chips drop entirely on Listen.
		// Umbrella's file() returns OUTPOST_PLUGIN_BASENAME, which must
		// report active so is_active() returns true. All other plugin
		// files return false so the registry won't try to instantiate
		// other companions.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// outpost_manual_share_defer_to_bridgy default-false (no mock).

		$chips = Outpost_Companion_Registry::chips_for_mode( 'listen' );
		$this->assertSame( array(), $chips );
	}

	public function test_chips_for_mode_with_unknown_mode_fails_open(): void {
		// Unknown mode returns every detected manual-share chip per the
		// fail-open contract documented in F2 (and shared with companion-
		// chip filtering).
		// Umbrella's file() returns OUTPOST_PLUGIN_BASENAME, which must
		// report active so is_active() returns true. All other plugin
		// files return false so the registry won't try to instantiate
		// other companions.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// outpost_manual_share_defer_to_bridgy default-false (no mock).

		$chips = Outpost_Companion_Registry::chips_for_mode( 'totally-made-up-mode' );

		$this->assertCount( 10, $chips );
	}

	public function test_chips_for_mode_with_null_returns_all_manual_chips(): void {
		// Umbrella's file() returns OUTPOST_PLUGIN_BASENAME, which must
		// report active so is_active() returns true. All other plugin
		// files return false so the registry won't try to instantiate
		// other companions.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		// outpost_manual_share_defer_to_bridgy default-false (no mock).

		$chips = Outpost_Companion_Registry::chips_for_mode( null );
		$this->assertCount( 10, $chips );
	}

	public function test_chips_for_mode_photo_with_bridgy_defer_drops_reddit_flickr(): void {
		// Umbrella's file() returns OUTPOST_PLUGIN_BASENAME, which must
		// report active so is_active() returns true. All other plugin
		// files return false so the registry won't try to instantiate
		// other companions.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			static fn( string $file ): bool => OUTPOST_PLUGIN_BASENAME === $file
		);
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::onFilter( 'outpost_companion_adapters' )
			->withAnyArgs()
			->reply( array( 'Outpost_Manual_Share_Adapter' ) );
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		WP_Mock::onFilter( 'outpost_manual_share_defer_to_bridgy' )
			->withAnyArgs()
			->reply( true );

		$chips = Outpost_Companion_Registry::chips_for_mode( 'photo' );
		$ids   = array_column( $chips, 'id' );

		$this->assertNotContains( 'reddit-manual', $ids );
		$this->assertNotContains( 'flickr-manual', $ids );
		// Other photo-accepting platforms still present.
		$this->assertContains( 'instagram-feed', $ids );
	}
}
