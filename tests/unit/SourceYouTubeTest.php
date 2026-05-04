<?php
/**
 * Unit tests for Outpost_Source_YouTube (F15).
 *
 * Second concrete inbound-source adapter. Validates the F5 design
 * supports a source whose URL variants don't all fit the host-only
 * host_patterns mechanism — YouTube watch URLs are at `/watch` (no
 * trailing slash, so Source_Base's path-prefix syntax can't express
 * them), while youtube.com/channel/, /c/, /@handle, /playlist must
 * fall through to Source_Unknown. F15's adapter therefore overrides
 * matches_url() with a path constraint; this is documented as a
 * Source_Base design-gap follow-up in CLAUDE.md F15 #1.
 *
 * Per concepts/capture-inbound-may-2026.md §3.3: oEmbed mapping is
 * title->p-name, thumbnail_url->u-photo, author_name->p-author,
 * provider_name->p-publication, @source_url->u-watch-of (always set).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_YouTube;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Oembed;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceYouTubeTest extends \WP_Mock\Tools\TestCase {

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

	// --- capabilities shape ----------------------------------------------

	public function test_capabilities_shape_matches_f5_contract(): void {
		$source = new Outpost_Source_YouTube();
		$caps   = $source->capabilities();

		$this->assertSame( 'youtube', $caps['id'] );
		$this->assertSame( 'YouTube', $caps['label'] );
		$this->assertSame(
			array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be' ),
			$caps['host_patterns']
		);
		$this->assertSame( 'unambiguous', $caps['ambiguity'] );
		$this->assertSame( 'watch', $caps['mode'] );
		$this->assertNull( $caps['mode_options'] );
		$this->assertNull( $caps['mode_default'] );
		$this->assertSame( 'oembed', $caps['extractor'] );
		$this->assertSame( 'u-watch-of', $caps['h_entry_property'] );
		$this->assertFalse( $caps['auth_required'] );
		$this->assertSame( array( 'watch' ), $caps['tags_default'] );
		$this->assertSame( array(), $caps['caveats'] );
	}

	public function test_capabilities_recipe_uses_oembed_endpoint(): void {
		$source = new Outpost_Source_YouTube();
		$caps   = $source->capabilities();

		$this->assertSame(
			'https://www.youtube.com/oembed?url={url}&format=json',
			$caps['recipe']['endpoint']
		);
		$this->assertSame( 'json', $caps['recipe']['response_format'] );
	}

	public function test_capabilities_mapping_includes_watch_of_substitution(): void {
		$source = new Outpost_Source_YouTube();
		$caps   = $source->capabilities();

		$this->assertSame( 'p-name', $caps['mapping']['title'] );
		$this->assertSame( 'u-photo', $caps['mapping']['thumbnail_url'] );
		$this->assertSame( 'p-author', $caps['mapping']['author_name'] );
		$this->assertSame( 'p-publication', $caps['mapping']['provider_name'] );
		$this->assertSame( 'u-watch-of', $caps['mapping']['@source_url'] );
	}

	// --- adapter declares only capabilities + matches_url ----------------

	public function test_adapter_declares_only_capabilities_and_matches_url(): void {
		// F15 design-gap acceptance: matches_url() override is the ONE
		// concession to the F5 path-pattern limitation. Documented in
		// CLAUDE.md F15 #1. If a future change adds another override,
		// this test should fail loudly so the addition gets justified.
		$ref              = new \ReflectionClass( Outpost_Source_YouTube::class );
		$declared_methods = array();
		foreach ( $ref->getMethods() as $method ) {
			if ( $method->getDeclaringClass()->getName() === Outpost_Source_YouTube::class ) {
				$declared_methods[] = $method->getName();
			}
		}
		sort( $declared_methods );
		$this->assertSame( array( 'capabilities', 'matches_url' ), $declared_methods );
	}

	// --- URL pattern matching across all 8 documented variants -----------

	public function test_canonical_watch_url_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://www.youtube.com/watch?v=EXAMPLE' ) );
	}

	public function test_watch_url_with_timestamp_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://www.youtube.com/watch?v=EXAMPLE&t=42s' ) );
	}

	public function test_watch_url_with_playlist_param_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://www.youtube.com/watch?v=EXAMPLE&list=PLEXAMPLE' ) );
	}

	public function test_shorts_url_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://www.youtube.com/shorts/SHORTS_EXAMPLE' ) );
	}

	public function test_mobile_watch_url_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://m.youtube.com/watch?v=EXAMPLE' ) );
	}

	public function test_youtube_music_watch_url_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://music.youtube.com/watch?v=MUSIC_EXAMPLE' ) );
	}

	public function test_youtu_be_short_link_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://youtu.be/EXAMPLE' ) );
	}

	public function test_youtu_be_short_link_with_timestamp_matches(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://youtu.be/EXAMPLE?t=42s' ) );
	}

	public function test_apex_youtube_com_watch_url_matches(): void {
		// The apex host without `www.` is also accepted (some share
		// sheets emit it that way).
		$source = new Outpost_Source_YouTube();
		$this->assertTrue( $source->matches_url( 'https://youtube.com/watch?v=EXAMPLE' ) );
	}

	// --- non-matching variants must route to Source_Unknown --------------

	public function test_youtube_homepage_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/' ) );
	}

	public function test_channel_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/channel/UCEXAMPLE' ) );
	}

	public function test_custom_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/c/example-channel-name' ) );
	}

	public function test_handle_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/@example-handle' ) );
	}

	public function test_playlist_url_does_not_match(): void {
		// Pure playlist URL (no `v=`) is a list of videos, not a single
		// watch event. Routes to Source_Unknown (Bookmark).
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/playlist?list=PLEXAMPLE' ) );
	}

	public function test_feed_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://www.youtube.com/feed/subscriptions' ) );
	}

	public function test_youtu_be_root_does_not_match(): void {
		// Bare youtu.be/ with no path is the homepage redirect, not a
		// shareable video link.
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://youtu.be/' ) );
	}

	public function test_arbitrary_non_youtube_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://example.com/article' ) );
	}

	public function test_vimeo_url_does_not_match(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://vimeo.com/000000000' ) );
	}

	public function test_subdomain_of_youtube_does_not_match_when_path_is_invalid(): void {
		// studio.youtube.com isn't in the constrained-host list — should
		// fall through regardless of path.
		$source = new Outpost_Source_YouTube();
		$this->assertFalse( $source->matches_url( 'https://studio.youtube.com/channel/UCEXAMPLE' ) );
	}

	// --- mode_for_url returns 'watch' for every matched URL ---------------

	public function test_mode_is_watch_for_canonical_url(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://www.youtube.com/watch?v=EXAMPLE' ) );
	}

	public function test_mode_is_watch_for_shorts(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://www.youtube.com/shorts/SHORTS_EXAMPLE' ) );
	}

	public function test_mode_is_watch_for_youtube_music(): void {
		// CLAUDE.md F15 #1 / Doc 2 §3.3: YouTube Music URLs route to
		// Watch mode, NOT Listen. Listen mode is for audio-only
		// platforms (Spotify, Last.fm); music videos are still videos.
		$source = new Outpost_Source_YouTube();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://music.youtube.com/watch?v=MUSIC_EXAMPLE' ) );
	}

	public function test_mode_is_watch_for_youtu_be(): void {
		$source = new Outpost_Source_YouTube();
		$this->assertSame( 'watch', $source->mode_for_url( 'https://youtu.be/EXAMPLE' ) );
	}

	// --- mapping with real-shape oEmbed responses ------------------------

	public function test_mapping_produces_h_entry_properties_from_watch_response(): void {
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-watch-success' );
		$source_url = 'https://www.youtube.com/watch?v=EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Video Title', $mapped['p-name'] );
		$this->assertSame( 'https://i.ytimg.com/vi/EXAMPLE/hqdefault.jpg', $mapped['u-photo'] );
		$this->assertSame( 'Sample Channel', $mapped['p-author'] );
		$this->assertSame( 'YouTube', $mapped['p-publication'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_mapping_shorts_response(): void {
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-shorts-success' );
		$source_url = 'https://www.youtube.com/shorts/SHORTS_EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Shorts Video', $mapped['p-name'] );
		$this->assertSame( 'Shorts Creator', $mapped['p-author'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_mapping_music_response_still_routes_via_youtube_source(): void {
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-music-success' );
		$source_url = 'https://music.youtube.com/watch?v=MUSIC_EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Music Video', $mapped['p-name'] );
		// "Topic" suffix is YouTube's convention for auto-generated
		// channels of music artists. Adapter is transparent — passes
		// the channel name through verbatim.
		$this->assertSame( 'Sample Artist - Topic', $mapped['p-author'] );
		$this->assertSame( $source_url, $mapped['u-watch-of'] );
	}

	public function test_mapping_passes_html_entities_through_unchanged(): void {
		// Per F8 #11 transparent-adapter contract: source adapters do
		// NOT decode entities. The composer (input value via DOM) and
		// render-time esc_html() handle browser/escape semantics. If
		// we decoded here, downstream code would double-decode.
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-html-entities' );
		$source_url = 'https://www.youtube.com/watch?v=HTML_ENTITIES_EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample &amp; Title with &#39;Entities&#39; &quot;In It&quot;', $mapped['p-name'] );
		$this->assertSame( 'Channel &amp; Co.', $mapped['p-author'] );
	}

	public function test_mapping_preserves_utf8_multi_script_title(): void {
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-utf8-multi' );
		$source_url = 'https://www.youtube.com/watch?v=UTF8_EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( '公式チャンネル예시 동영상мира', $mapped['p-name'] );
		$this->assertSame( '国際チャンネル', $mapped['p-author'] );
	}

	// --- degraded paths --------------------------------------------------

	public function test_degraded_d1_404_response_body_drops_oembed_keys(): void {
		// D1: YouTube returns a JSON 404 error body. The HTTP status
		// check in the preview endpoint short-circuits before reaching
		// the parser; this test verifies that even if some future
		// caller did pass the body through, the source mapping
		// produces an empty result because the title / thumbnail_url /
		// author_name / provider_name keys are absent. u-watch-of is
		// still set from @source_url.
		$source     = new Outpost_Source_YouTube();
		$decoded    = SourceFixtureLoader::load_oembed_fixture( 'youtube', 'oembed-404' );
		$source_url = 'https://www.youtube.com/watch?v=EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( $source_url, $mapped['u-watch-of'] );
		$this->assertArrayNotHasKey( 'p-name', $mapped );
		$this->assertArrayNotHasKey( 'u-photo', $mapped );
		$this->assertArrayNotHasKey( 'p-author', $mapped );
		$this->assertArrayNotHasKey( 'p-publication', $mapped );
	}

	public function test_degraded_d3_non_json_503_body_throws_runtime_exception(): void {
		// D3: YouTube returns a 503 with an nginx HTML body (region-
		// blocked / private video / outage). Extractor_Oembed::parse
		// rejects with RuntimeException because the body isn't JSON;
		// preview-endpoint catches and surfaces 502 to the composer.
		$ext  = new Outpost_Source_Extractor_Oembed();
		$body = SourceFixtureLoader::load_raw_fixture( 'youtube', 'oembed-503', 'txt' );

		$this->expectException( \RuntimeException::class );
		$ext->parse( $body, array( 'endpoint' => 'https://www.youtube.com/oembed?url={url}&format=json' ) );
	}

	public function test_degraded_d3_plain_text_401_body_throws_runtime_exception(): void {
		// 401 region-restricted / private video → plain-text body, not
		// JSON. Same RuntimeException as the 503 case.
		$ext  = new Outpost_Source_Extractor_Oembed();
		$body = SourceFixtureLoader::load_raw_fixture( 'youtube', 'oembed-401', 'txt' );

		$this->expectException( \RuntimeException::class );
		$ext->parse( $body, array( 'endpoint' => 'https://www.youtube.com/oembed?url={url}&format=json' ) );
	}

	// --- compute_fetch_url substitution ---------------------------------

	public function test_oembed_extractor_builds_correct_fetch_url(): void {
		$source     = new Outpost_Source_YouTube();
		$ext        = new Outpost_Source_Extractor_Oembed();
		$source_url = 'https://www.youtube.com/watch?v=EXAMPLE';

		$fetch_url = $ext->compute_fetch_url( $source_url, $source->recipe_for_url( $source_url ) );
		$this->assertSame(
			'https://www.youtube.com/oembed?url=' . rawurlencode( $source_url ) . '&format=json',
			$fetch_url
		);
	}

	public function test_oembed_extractor_substitutes_youtu_be_short_link(): void {
		$source     = new Outpost_Source_YouTube();
		$ext        = new Outpost_Source_Extractor_Oembed();
		$source_url = 'https://youtu.be/EXAMPLE';

		$fetch_url = $ext->compute_fetch_url( $source_url, $source->recipe_for_url( $source_url ) );
		$this->assertSame(
			'https://www.youtube.com/oembed?url=' . rawurlencode( $source_url ) . '&format=json',
			$fetch_url
		);
	}

	// --- registry integration --------------------------------------------

	public function test_registry_finds_youtube_for_watch_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.youtube.com/watch?v=EXAMPLE' );

		$this->assertNotNull( $found );
		$this->assertSame( 'youtube', $found->capabilities()['id'] );
	}

	public function test_registry_finds_youtube_for_youtu_be_short_link(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
		$found = Outpost_Source_Registry::find_for_url( 'https://youtu.be/EXAMPLE' );

		$this->assertNotNull( $found );
		$this->assertSame( 'youtube', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_youtube_channel_url(): void {
		// Channel URLs are NOT claimed by Outpost_Source_YouTube even
		// though the host matches — matches_url's path constraint
		// rejects them. Source_Unknown's `*` wildcard catches them.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.youtube.com/channel/UCEXAMPLE' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_non_youtube_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
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
					'id'               => 'youtube',
					'label'            => 'Custom YouTube Label',
					'host_patterns'    => array( 'youtube.com' ),
					'ambiguity'        => 'unambiguous',
					'mode'             => 'watch',
					'mode_options'     => null,
					'mode_default'     => null,
					'extractor'        => 'oembed',
					'recipe'           => array( 'endpoint' => 'https://www.youtube.com/oembed?url={url}&format=json', 'response_format' => 'json' ),
					'mapping'          => array(),
					'h_entry_property' => 'u-watch-of',
					'auth_required'    => false,
					'tags_default'     => array( 'watch' ),
					'caveats'          => array(),
				)
			);

		$source = new Outpost_Source_YouTube();
		$caps   = $source->capabilities();

		$this->assertSame( 'Custom YouTube Label', $caps['label'] );
		$this->assertSame( array( 'youtube.com' ), $caps['host_patterns'] );
	}

	public function test_filter_returning_non_array_falls_back_to_default_shape(): void {
		WP_Mock::onFilter( 'outpost_source_capabilities' )
			->withAnyArgs()
			->reply( 'not-an-array' );

		$source = new Outpost_Source_YouTube();
		$caps   = $source->capabilities();

		$this->assertSame( 'YouTube', $caps['label'] );
		$this->assertSame( 'oembed', $caps['extractor'] );
	}
}
