<?php
/**
 * Unit tests for Outpost_Source_Twitch (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Twitch;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceTwitchTest extends \WP_Mock\Tools\TestCase {

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
		$source = new Outpost_Source_Twitch();
		$caps   = $source->capabilities();

		$this->assertSame( 'twitch', $caps['id'] );
		$this->assertSame( 'Twitch', $caps['label'] );
		$this->assertSame( 'watch', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-watch-of', $caps['h_entry_property'] );
	}

	public function test_caveat_documents_helix_omission(): void {
		$source = new Outpost_Source_Twitch();
		$caps   = $source->capabilities();

		$this->assertNotEmpty( $caps['caveats'] );
		$this->assertStringContainsString( 'Helix', $caps['caveats'][0] );
	}

	public function test_channel_url_matches(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertTrue( $source->matches_url( 'https://www.twitch.tv/example-channel' ) );
	}

	public function test_videos_url_matches(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertTrue( $source->matches_url( 'https://www.twitch.tv/videos/0000000000' ) );
	}

	public function test_clip_url_matches(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertTrue( $source->matches_url( 'https://clips.twitch.tv/SampleClipSlug' ) );
	}

	public function test_apex_twitch_url_matches(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertTrue( $source->matches_url( 'https://twitch.tv/example-channel' ) );
	}

	public function test_arbitrary_url_does_not_match(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertFalse( $source->matches_url( 'https://example.com/page' ) );
	}

	public function test_mode_is_watch(): void {
		$source = new Outpost_Source_Twitch();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://www.twitch.tv/videos/123' ) );
	}

	public function test_mapping_for_vod_fixture(): void {
		$source     = new Outpost_Source_Twitch();
		$body       = SourceFixtureLoader::load_raw_fixture( 'twitch', 'og-vod-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.twitch.tv/videos/0000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample VOD Title', $mapped['p-name'] );
		$this->assertStringContainsString( 'description', $mapped['p-summary'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_mapping_for_clip_fixture(): void {
		$source     = new Outpost_Source_Twitch();
		$body       = SourceFixtureLoader::load_raw_fixture( 'twitch', 'og-clip-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://clips.twitch.tv/SampleClipSlug';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Clip Title', $mapped['p-name'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_registry_finds_twitch_for_clip_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Twitch() );
		$found = Outpost_Source_Registry::find_for_url( 'https://clips.twitch.tv/abc' );

		$this->assertNotNull( $found );
		$this->assertSame( 'twitch', $found->capabilities()['id'] );
	}
}
