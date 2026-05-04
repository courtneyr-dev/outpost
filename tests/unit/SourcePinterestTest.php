<?php
/**
 * Unit tests for Outpost_Source_Pinterest (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Pinterest;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourcePinterestTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	public function test_capabilities_shape(): void {
		$source = new Outpost_Source_Pinterest();
		$caps   = $source->capabilities();

		$this->assertSame( 'pinterest', $caps['id'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertSame( 'u-bookmark-of', $caps['h_entry_property'] );
	}

	public function test_pin_url_matches(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertTrue( $source->matches_url( 'https://www.pinterest.com/pin/000000000000000000/' ) );
	}

	public function test_apex_pin_url_matches(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertTrue( $source->matches_url( 'https://pinterest.com/pin/000000000000000000/' ) );
	}

	public function test_pin_short_link_matches(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertTrue( $source->matches_url( 'https://pin.it/abcdefghi' ) );
	}

	public function test_board_url_does_not_match(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertFalse( $source->matches_url( 'https://www.pinterest.com/example-user/example-board/' ) );
	}

	public function test_profile_url_does_not_match(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertFalse( $source->matches_url( 'https://www.pinterest.com/example-user/' ) );
	}

	public function test_pinterest_homepage_does_not_match(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertFalse( $source->matches_url( 'https://www.pinterest.com/' ) );
	}

	public function test_pin_it_root_does_not_match(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertFalse( $source->matches_url( 'https://pin.it/' ) );
	}

	public function test_mode_is_bookmark(): void {
		$source = new Outpost_Source_Pinterest();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://www.pinterest.com/pin/123/' ) );
	}

	public function test_mapping_for_pin_fixture(): void {
		$source     = new Outpost_Source_Pinterest();
		$body       = SourceFixtureLoader::load_raw_fixture( 'pinterest', 'og-pin-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.pinterest.com/pin/000000000000000000/';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Pin Title', $mapped['p-name'] );
		$this->assertStringContainsString( 'description', $mapped['p-summary'] );
		$this->assertSame( $source_url, $mapped['u-bookmark-of'] );
	}

	public function test_registry_finds_pinterest_for_pin_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Pinterest() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.pinterest.com/pin/123/' );

		$this->assertNotNull( $found );
		$this->assertSame( 'pinterest', $found->capabilities()['id'] );
	}
}
