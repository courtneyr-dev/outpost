<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Adapter (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Adapter;
use Outpost_Bridgy_Publish_Settings;
use Outpost_Bridgy_Publish_Silo_Registry;
use WP_Mock;

final class BridgyPublishAdapterTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, mixed> */
	private array $option_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->option_store = array();
		Outpost_Bridgy_Publish_Silo_Registry::reset_for_tests();

		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( string $key, $default = false ) {
				return $this->option_store[ $key ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( string $key, $value ): bool {
				$this->option_store[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_option' )->andReturnUsing(
			function ( string $key ): bool {
				unset( $this->option_store[ $key ] );
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Bridgy_Publish_Silo_Registry::reset_for_tests();
	}

	// =====================================================================
	// Adapter shape
	// =====================================================================

	public function test_file_returns_outpost_basename(): void {
		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$this->assertSame( OUTPOST_PLUGIN_BASENAME, $adapter->file() );
	}

	public function test_label_is_brand_name(): void {
		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$this->assertSame( 'Bridgy Publish', $adapter->label() );
	}

	public function test_feature_slugs_empty(): void {
		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$this->assertSame( array(), $adapter->feature_slugs() );
	}

	public function test_capabilities_returns_null(): void {
		// Umbrella never surfaces a single chip; chips per-silo.
		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$this->assertNull( $adapter->capabilities() );
	}

	// =====================================================================
	// platform_chips() filters by settings
	// =====================================================================

	public function test_platform_chips_empty_when_no_silos_enabled(): void {
		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$chips   = $adapter->platform_chips();
		$this->assertSame( array(), $chips );
	}

	public function test_platform_chips_returns_only_enabled_silos(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-flickr', true );

		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$chips   = $adapter->platform_chips();

		$this->assertCount( 2, $chips );
		$ids = array_column( $chips, 'id' );
		$this->assertContains( 'bridgy-mastodon', $ids );
		$this->assertContains( 'bridgy-flickr', $ids );
		$this->assertNotContains( 'bridgy-bluesky', $ids );
	}

	public function test_platform_chips_have_bridgy_publish_extension_data(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-bluesky', true );

		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$chip    = $adapter->platform_chips()[0];
		$this->assertSame( 'bridgy-bluesky', $chip['id'] );
		$this->assertArrayHasKey( 'bridgy_publish', $chip );
		$this->assertSame( 'bluesky', $chip['bridgy_publish']['silo_id'] );
		$this->assertSame( 'https://brid.gy/publish/bluesky', $chip['bridgy_publish']['bridgy_url'] );
	}

	public function test_chip_accepts_modes_match_silo_config(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-github', true );

		$adapter = new Outpost_Bridgy_Publish_Adapter();
		$chip    = $adapter->platform_chips()[0];
		// GitHub silo config declares: note + reply + like
		$this->assertSame( array( 'note', 'reply', 'like' ), $chip['accepts_modes'] );
	}

	// =====================================================================
	// is_silo_enabled_by_silo_id (F9 deferral helper)
	// =====================================================================

	public function test_is_silo_enabled_by_silo_id_true_when_enabled(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-reddit', true );
		$this->assertTrue(
			Outpost_Bridgy_Publish_Adapter::is_silo_enabled_by_silo_id( 'reddit' )
		);
	}

	public function test_is_silo_enabled_by_silo_id_false_when_disabled(): void {
		$this->assertFalse(
			Outpost_Bridgy_Publish_Adapter::is_silo_enabled_by_silo_id( 'flickr' )
		);
	}

	public function test_is_silo_enabled_by_silo_id_false_for_unknown_silo(): void {
		$this->assertFalse(
			Outpost_Bridgy_Publish_Adapter::is_silo_enabled_by_silo_id( 'totally-fake' )
		);
	}
}
