<?php
/**
 * Unit tests for Outpost_Source_Spotify (F7).
 *
 * Validates that F5's Source_Base design supports a real concrete
 * source with a capabilities()-only implementation. Every assertion
 * exercises a default Source_Base method (matches_url,
 * recipe_for_url, mode_for_url, map_extracted) — no method-override
 * tests because the adapter overrides nothing.
 *
 * Per concepts/capture-inbound-may-2026.md §3.1: oEmbed mapping is
 * title->p-name, thumbnail_url->u-photo, provider_name->p-publication,
 * @source_url->u-listen-of (always, regardless of oEmbed response).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Spotify;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Oembed;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceSpotifyTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	// --- capabilities shape -----------------------------------------------

	public function test_capabilities_shape_matches_f5_contract(): void {
		$source = new Outpost_Source_Spotify();
		$caps   = $source->capabilities();

		$this->assertSame( 'spotify', $caps['id'] );
		$this->assertSame( 'Spotify', $caps['label'] );
		$this->assertSame( array( 'open.spotify.com', 'spotify.link' ), $caps['host_patterns'] );
		$this->assertSame( 'unambiguous', $caps['ambiguity'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertNull( $caps['mode_options'] );
		$this->assertNull( $caps['mode_default'] );
		$this->assertSame( 'oembed', $caps['extractor'] );
		$this->assertSame( 'u-listen-of', $caps['h_entry_property'] );
		$this->assertFalse( $caps['auth_required'] );
		$this->assertSame( array( 'listen' ), $caps['tags_default'] );
		$this->assertSame( array(), $caps['caveats'] );
	}

	public function test_capabilities_recipe_uses_oembed_endpoint(): void {
		$source = new Outpost_Source_Spotify();
		$caps   = $source->capabilities();

		$this->assertSame(
			'https://open.spotify.com/oembed?url={url}',
			$caps['recipe']['endpoint']
		);
		$this->assertSame( 'json', $caps['recipe']['response_format'] );
	}

	public function test_capabilities_mapping_includes_listen_of_substitution(): void {
		$source = new Outpost_Source_Spotify();
		$caps   = $source->capabilities();

		$this->assertSame( 'p-name', $caps['mapping']['title'] );
		$this->assertSame( 'u-photo', $caps['mapping']['thumbnail_url'] );
		$this->assertSame( 'p-publication', $caps['mapping']['provider_name'] );
		$this->assertSame( 'u-listen-of', $caps['mapping']['@source_url'] );
	}

	// --- adapter overrides nothing ---------------------------------------

	public function test_adapter_overrides_only_capabilities(): void {
		// F7 acceptance #1 — the adapter is capabilities()-only. If a
		// future change adds an override, this test should fail
		// loudly so the override gets justified in CLAUDE.md as a
		// Source_Base design gap rather than smuggled through.
		$ref = new \ReflectionClass( Outpost_Source_Spotify::class );
		$declared_methods = array();
		foreach ( $ref->getMethods() as $method ) {
			if ( $method->getDeclaringClass()->getName() === Outpost_Source_Spotify::class ) {
				$declared_methods[] = $method->getName();
			}
		}
		sort( $declared_methods );
		$this->assertSame( array( 'capabilities' ), $declared_methods );
	}

	// --- URL pattern matching across all 9 documented variants -----------

	public function test_track_url_matches_via_default_matches_url(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/track/0000000000000000000000' ) );
	}

	public function test_album_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/album/0000000000000000000000' ) );
	}

	public function test_episode_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/episode/0000000000000000000000' ) );
	}

	public function test_show_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/show/0000000000000000000000' ) );
	}

	public function test_playlist_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/playlist/0000000000000000000000' ) );
	}

	public function test_intl_regional_track_url_matches(): void {
		// The intl-{lang} prefix is a path segment. host_patterns is
		// host-only, so the host part open.spotify.com still matches —
		// the intl-* regional variant Just Works because we don't
		// constrain on path.
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/intl-de/track/0000000000000000000000' ) );
	}

	public function test_intl_regional_album_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/intl-ja/album/0000000000000000000000' ) );
	}

	public function test_intl_regional_episode_url_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://open.spotify.com/intl-en/episode/0000000000000000000000' ) );
	}

	public function test_spotify_link_short_url_matches(): void {
		// spotify.link is a separate host — needs its own host_patterns
		// entry which the adapter declares.
		$source = new Outpost_Source_Spotify();
		$this->assertTrue( $source->matches_url( 'https://spotify.link/abcdefghij' ) );
	}

	// --- non-Spotify URLs do NOT match -----------------------------------

	public function test_arbitrary_url_does_not_match(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertFalse( $source->matches_url( 'https://example.com/article' ) );
	}

	public function test_apple_music_url_does_not_match(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertFalse( $source->matches_url( 'https://music.apple.com/us/album/EXAMPLE/0000000000' ) );
	}

	public function test_subdomain_of_spotify_does_not_match_via_exact_pattern(): void {
		// `open.spotify.com` is exact-host, NOT a wildcard. A subdomain
		// like accounts.spotify.com is NOT claimed.
		$source = new Outpost_Source_Spotify();
		$this->assertFalse( $source->matches_url( 'https://accounts.spotify.com/login' ) );
	}

	// --- mode_for_url returns 'listen' for any matched URL ---------------

	public function test_mode_is_listen_for_all_urls(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertSame( 'listen', $source->mode_for_url( 'https://open.spotify.com/track/0000000000000000000000' ) );
		$this->assertSame( 'listen', $source->mode_for_url( 'https://open.spotify.com/playlist/0000000000000000000000' ) );
		$this->assertSame( 'listen', $source->mode_for_url( 'https://spotify.link/abcdefghij' ) );
	}

	// --- mapping with a real-shape oEmbed response (track fixture) -------

	public function test_mapping_produces_h_entry_properties_from_track_response(): void {
		$source     = new Outpost_Source_Spotify();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-track-success' );
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Track Title', $mapped['p-name'] );
		$this->assertSame( 'https://i.scdn.co/image/example-cover-art-thumb', $mapped['u-photo'] );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}

	public function test_mapping_album_response(): void {
		$source     = new Outpost_Source_Spotify();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-album-success' );
		$source_url = 'https://open.spotify.com/album/0000000000000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Album Title', $mapped['p-name'] );
		$this->assertSame( 'https://i.scdn.co/image/example-album-cover-thumb', $mapped['u-photo'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}

	public function test_mapping_episode_response(): void {
		$source     = new Outpost_Source_Spotify();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-episode-success' );
		$source_url = 'https://open.spotify.com/episode/0000000000000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Podcast Episode Title', $mapped['p-name'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}

	// --- degraded paths --------------------------------------------------

	public function test_degraded_d3_malformed_json_throws_runtime_exception(): void {
		// D3 from F7 prompt: malformed JSON. Extractor_Oembed::parse
		// rejects with RuntimeException; preview-endpoint catches and
		// surfaces 502 to the composer.
		$ext  = new Outpost_Source_Extractor_Oembed();
		$body = SourceFixtureLoader::load_raw_fixture( 'spotify', 'oembed-malformed', 'json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'JSON' );
		$ext->parse( $body, array( 'endpoint' => 'https://open.spotify.com/oembed?url={url}' ) );
	}

	public function test_degraded_d1_404_response_body_parses_but_lacks_oembed_keys(): void {
		// D1: Spotify returns a 404 with a JSON error body. The HTTP
		// status check in the preview endpoint short-circuits before
		// the body reaches the parser; this test verifies that even
		// if some future caller did pass the body through, the
		// oEmbed parser accepts it (it's valid JSON), but the source
		// mapping produces an empty result because the title /
		// thumbnail_url / provider_name keys are absent.
		$source     = new Outpost_Source_Spotify();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-404' );
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		// u-listen-of always set from @source_url, even when oEmbed
		// returned an error body. p-name / u-photo / p-publication
		// drop because the oEmbed keys aren't present in the error
		// body.
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
		$this->assertArrayNotHasKey( 'p-name', $mapped );
		$this->assertArrayNotHasKey( 'u-photo', $mapped );
		$this->assertArrayNotHasKey( 'p-publication', $mapped );
	}

	// --- compute_fetch_url substitution ---------------------------------

	public function test_oembed_extractor_builds_correct_fetch_url(): void {
		$source     = new Outpost_Source_Spotify();
		$ext        = new Outpost_Source_Extractor_Oembed();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';

		$fetch_url = $ext->compute_fetch_url( $source_url, $source->recipe_for_url( $source_url ) );
		$this->assertSame(
			'https://open.spotify.com/oembed?url=' . rawurlencode( $source_url ),
			$fetch_url
		);
	}

	// --- registry integration --------------------------------------------

	public function test_registry_finds_spotify_for_track_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Spotify() );
		$found = Outpost_Source_Registry::find_for_url( 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertNotNull( $found );
		$this->assertSame( 'spotify', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_non_spotify_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Spotify() );
		$found = Outpost_Source_Registry::find_for_url( 'https://example.com/article' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}

	// --- filter override -------------------------------------------------

	public function test_outpost_source_capabilities_filter_can_replace_shape(): void {
		WP_Mock::onFilter( 'outpost_source_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'               => 'spotify',
					'label'            => 'Custom Spotify Label',
					'host_patterns'    => array( 'open.spotify.com' ),
					'ambiguity'        => 'unambiguous',
					'mode'             => 'listen',
					'mode_options'     => null,
					'mode_default'     => null,
					'extractor'        => 'oembed',
					'recipe'           => array( 'endpoint' => 'https://open.spotify.com/oembed?url={url}', 'response_format' => 'json' ),
					'mapping'          => array(),
					'h_entry_property' => 'u-listen-of',
					'auth_required'    => false,
					'tags_default'     => array( 'listen' ),
					'caveats'          => array(),
				)
			);

		$source = new Outpost_Source_Spotify();
		$caps   = $source->capabilities();

		$this->assertSame( 'Custom Spotify Label', $caps['label'] );
		$this->assertSame( array( 'open.spotify.com' ), $caps['host_patterns'] );
	}
}
