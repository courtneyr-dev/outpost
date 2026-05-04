<?php
/**
 * Unit tests for Outpost_Source_TikTok (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_TikTok;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceTikTokTest extends \WP_Mock\Tools\TestCase {

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
		$source = new Outpost_Source_TikTok();
		$caps   = $source->capabilities();

		$this->assertSame( 'tiktok', $caps['id'] );
		$this->assertSame( 'watch', $caps['mode'] );
		$this->assertSame( 'u-watch-of', $caps['h_entry_property'] );
	}

	public function test_video_url_matches(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertTrue( $source->matches_url( 'https://www.tiktok.com/@example-creator/video/0000000000000000000' ) );
	}

	public function test_apex_video_url_matches(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertTrue( $source->matches_url( 'https://tiktok.com/@example-creator/video/0000000000000000000' ) );
	}

	public function test_short_link_matches(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertTrue( $source->matches_url( 'https://vm.tiktok.com/abcdefghij/' ) );
	}

	public function test_short_link_root_does_not_match(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertFalse( $source->matches_url( 'https://vm.tiktok.com/' ) );
	}

	public function test_profile_url_does_not_match(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertFalse( $source->matches_url( 'https://www.tiktok.com/@example-creator' ) );
	}

	public function test_homepage_does_not_match(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertFalse( $source->matches_url( 'https://www.tiktok.com/' ) );
	}

	public function test_discover_url_does_not_match(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertFalse( $source->matches_url( 'https://www.tiktok.com/discover' ) );
	}

	public function test_mode_is_watch(): void {
		$source = new Outpost_Source_TikTok();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://www.tiktok.com/@example/video/123' ) );
	}

	public function test_mapping_for_video_fixture(): void {
		$source     = new Outpost_Source_TikTok();
		$body       = SourceFixtureLoader::load_raw_fixture( 'tiktok', 'og-video-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.tiktok.com/@example-creator/video/0000000000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertStringContainsString( 'Sample TikTok', $mapped['p-name'] );
		$this->assertSame( 'https://p16-sign-va.tiktokcdn.com/example-thumb.jpeg', $mapped['u-photo'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_registry_finds_tiktok_for_video_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_TikTok() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.tiktok.com/@example/video/123' );

		$this->assertNotNull( $found );
		$this->assertSame( 'tiktok', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_profile(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_TikTok() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.tiktok.com/@example' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}
}
