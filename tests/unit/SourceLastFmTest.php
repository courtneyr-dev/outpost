<?php
/**
 * Unit tests for Outpost_Source_LastFm (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_LastFm;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceLastFmTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	public function test_capabilities_shape(): void {
		$source = new Outpost_Source_LastFm();
		$caps   = $source->capabilities();

		$this->assertSame( 'lastfm', $caps['id'] );
		$this->assertSame( 'Last.fm', $caps['label'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'u-listen-of', $caps['h_entry_property'] );
	}

	public function test_mapping_omits_description_intentionally(): void {
		$source = new Outpost_Source_LastFm();
		$caps   = $source->capabilities();

		// Per Source_LastFm docblock: og:description on Last.fm pages
		// is unhelpful (Wikipedia bio paste), so the mapping omits it.
		$this->assertArrayNotHasKey( 'og:description', $caps['mapping'] );
	}

	public function test_track_url_matches(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertTrue( $source->matches_url( 'https://www.last.fm/music/Sample+Artist/_/Sample+Track' ) );
	}

	public function test_artist_url_matches(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertTrue( $source->matches_url( 'https://www.last.fm/music/Sample+Artist' ) );
	}

	public function test_album_url_matches(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertTrue( $source->matches_url( 'https://www.last.fm/music/Sample+Artist/Sample+Album' ) );
	}

	public function test_user_profile_url_does_not_match(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertFalse( $source->matches_url( 'https://www.last.fm/user/example-user' ) );
	}

	public function test_chart_url_does_not_match(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertFalse( $source->matches_url( 'https://www.last.fm/charts' ) );
	}

	public function test_mode_is_listen(): void {
		$source = new Outpost_Source_LastFm();
		$this->assertSame( 'listen', $source->mode_for_url( 'https://www.last.fm/music/Sample' ) );
	}

	public function test_mapping_for_track_fixture(): void {
		$source     = new Outpost_Source_LastFm();
		$body       = SourceFixtureLoader::load_raw_fixture( 'lastfm', 'og-track-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.last.fm/music/Sample+Artist/_/Sample+Track';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertStringContainsString( 'Sample Track', $mapped['p-name'] );
		$this->assertSame( 'https://lastfm.freetls.fastly.net/i/u/example-album-cover.png', $mapped['u-photo'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
		$this->assertArrayNotHasKey( 'p-summary', $mapped, 'Last.fm mapping omits og:description on purpose.' );
	}

	public function test_registry_finds_lastfm_for_track_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_LastFm() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.last.fm/music/Sample/_/Track' );

		$this->assertNotNull( $found );
		$this->assertSame( 'lastfm', $found->capabilities()['id'] );
	}
}
