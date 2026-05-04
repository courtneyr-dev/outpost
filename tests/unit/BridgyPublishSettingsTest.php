<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Settings (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Settings;
use WP_Mock;

final class BridgyPublishSettingsTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, mixed> */
	private array $option_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->option_store = array();
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
	}

	public function test_is_enabled_false_by_default(): void {
		$this->assertFalse( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
	}

	public function test_set_enabled_true_persists(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		$this->assertTrue( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
	}

	public function test_set_enabled_false_unsets(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		$this->assertTrue( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', false );
		$this->assertFalse( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
	}

	public function test_per_silo_independent(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-bluesky', false );
		$this->assertTrue( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
		$this->assertFalse( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-bluesky' ) );
		$this->assertFalse( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-flickr' ) );
	}

	public function test_all_enabled_returns_only_truthy_entries(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-flickr', true );

		$enabled = Outpost_Bridgy_Publish_Settings::all_enabled();
		$this->assertSame(
			array( 'bridgy-mastodon' => true, 'bridgy-flickr' => true ),
			$enabled
		);
	}

	public function test_all_enabled_drops_malformed_storage(): void {
		// Storage corrupted to non-array.
		$this->option_store[ Outpost_Bridgy_Publish_Settings::option_key() ] = 'not-an-array';
		$this->assertSame( array(), Outpost_Bridgy_Publish_Settings::all_enabled() );
	}

	public function test_all_enabled_filters_non_string_keys_and_non_true_values(): void {
		// Hostile or corrupted storage shape.
		$this->option_store[ Outpost_Bridgy_Publish_Settings::option_key() ] = array(
			'bridgy-mastodon' => true,
			'bridgy-fake'     => 'truthy-string',  // not strict true
			0                 => true,             // not string key
			'bridgy-bluesky'  => 1,                // not strict true
		);
		$enabled = Outpost_Bridgy_Publish_Settings::all_enabled();
		$this->assertSame(
			array( 'bridgy-mastodon' => true ),
			$enabled
		);
	}

	public function test_clear_for_tests(): void {
		Outpost_Bridgy_Publish_Settings::set_enabled( 'bridgy-mastodon', true );
		Outpost_Bridgy_Publish_Settings::clear_for_tests();
		$this->assertFalse( Outpost_Bridgy_Publish_Settings::is_enabled( 'bridgy-mastodon' ) );
	}

	public function test_option_key_is_documented_constant(): void {
		$this->assertSame(
			'outpost_bridgy_silos_enabled',
			Outpost_Bridgy_Publish_Settings::option_key()
		);
	}
}
