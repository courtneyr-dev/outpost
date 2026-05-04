<?php
/**
 * Expanded coverage for Outpost_Source_Spotify (F8 Part A).
 *
 * F7 covered the happy path + 4 degraded paths. F8 hardens the long
 * tail: unusual-but-valid oEmbed responses (extra fields, HTML
 * entities, UTF-8 multilingual, missing thumbnail, very long titles),
 * defensive cases (script injection, javascript: URLs, zero-width
 * unicode), URL variants not in F7 (query strings, fragments,
 * trailing slashes), and mapping edge cases (all-missing, null vs
 * missing, empty title).
 *
 * The adapter is deliberately data-shape declarative — it doesn't
 * sanitize, doesn't truncate, doesn't decode HTML entities. Sanitization
 * lives at the preview endpoint (script stripping) and at composer
 * pre-fill (entity decoding via the browser when assigning to input
 * value). These tests prove the adapter is correctly transparent.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Spotify;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceSpotifyExpandedTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// =====================================================================
	// A1. Unusual but valid oEmbed responses
	// =====================================================================

	public function test_a1_extra_oembed_fields_are_ignored_by_mapping(): void {
		// F7's track fixture already includes `iframe`, `version`,
		// `width`, `height`, `html`. The mapping declares only title,
		// thumbnail_url, provider_name, @source_url — extras drop.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-track-success' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertArrayHasKey( 'p-name', $mapped );
		$this->assertArrayHasKey( 'u-photo', $mapped );
		$this->assertArrayHasKey( 'p-publication', $mapped );
		$this->assertArrayHasKey( 'u-listen-of', $mapped );
		$this->assertArrayNotHasKey( 'iframe_url', $mapped );
		$this->assertArrayNotHasKey( 'version', $mapped );
		$this->assertArrayNotHasKey( 'width', $mapped );
		$this->assertArrayNotHasKey( 'height', $mapped );
		$this->assertArrayNotHasKey( 'html', $mapped );
	}

	public function test_a1_html_entities_in_title_pass_through_verbatim(): void {
		// Adapter does NOT decode HTML entities. The composer's
		// JavaScript pre-fill assigns to <input value="..."> via the
		// DOM, where the browser handles decoding. Server-side decoding
		// would double-decode if Spotify's response is already decoded.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-html-entities' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame(
			'Rock &amp; Roll &#39;n&#39; Soul &quot;Best Of&quot;',
			$mapped['p-name']
		);
	}

	public function test_a1_utf8_korean_title_preserved_through_pipeline(): void {
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-utf8-korean' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( '아리랑 (Arirang)', $mapped['p-name'] );
	}

	public function test_a1_utf8_arabic_title_preserved_through_pipeline(): void {
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-utf8-arabic' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( 'موسيقى العود الكلاسيكية', $mapped['p-name'] );
	}

	public function test_a1_utf8_cyrillic_title_preserved_through_pipeline(): void {
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-utf8-cyrillic' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( 'Калинка (Kalinka)', $mapped['p-name'] );
	}

	public function test_a1_very_long_title_passes_through_without_truncation(): void {
		// Adapter does NOT truncate. Composer pre-fill code may apply
		// CSS `text-overflow: ellipsis` at render time; the model layer
		// keeps the full string so user editing has the original to
		// work with.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-very-long-title' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertGreaterThan( 300, strlen( $mapped['p-name'] ) );
		$this->assertSame( $raw['title'], $mapped['p-name'] );
	}

	public function test_a1_scdn_thumbnail_url_preserved_verbatim(): void {
		// Spotify CDN URLs (i.scdn.co) flow through unchanged.
		// Future privacy-conscious feature might proxy these through a
		// cache; that's not the adapter's concern.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-track-success' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( 'https://i.scdn.co/image/example-cover-art-thumb', $mapped['u-photo'] );
		$this->assertStringStartsWith( 'https://i.scdn.co/', $mapped['u-photo'] );
	}

	public function test_a1_missing_thumbnail_url_results_in_omitted_u_photo(): void {
		// Critical: u-photo MUST be omitted, not set to empty string.
		// A composer that sees `u-photo => ''` would render an empty
		// img tag; one that sees the key absent skips photo rendering
		// entirely.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-no-thumbnail' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/episode/0000000000000000000000' );

		$this->assertArrayNotHasKey( 'u-photo', $mapped );
		// Other fields still populate.
		$this->assertSame( 'Sample Episode Without Thumbnail', $mapped['p-name'] );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
	}

	// =====================================================================
	// A2. Defensive cases — verify the adapter is correctly transparent
	// =====================================================================

	public function test_a2_script_in_title_passes_through_at_adapter_layer(): void {
		// Layered defense: the adapter does NOT strip scripts. The
		// preview endpoint's existing strip-scripts step (B2 / F5)
		// handles script bodies in HTML responses; oEmbed JSON
		// titles flow through to the composer where assignment to
		// <input value> never executes. Storing the raw response lets
		// users see what Spotify returned and decide; adapter
		// sanitization would silently transform user-visible data.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-script-injection' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertStringContainsString( '<script>', $mapped['p-name'] );
		$this->assertSame( 'Sample <script>alert(1)</script> Track', $mapped['p-name'] );
	}

	public function test_a2_javascript_url_in_thumbnail_passes_through_at_adapter_layer(): void {
		// Same layered-defense story. The adapter doesn't validate
		// scheme; the composer's image rendering uses esc_url() which
		// rejects javascript: scheme and produces no src. The data
		// flows through so the composer can decide.
		$source = new Outpost_Source_Spotify();
		$raw    = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-javascript-url' );
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( 'javascript:alert(1)', $mapped['u-photo'] );
	}

	public function test_a2_file_scheme_thumbnail_passes_through_at_adapter_layer(): void {
		// Constructed inline rather than as a fixture; same layered-
		// defense story as javascript:. file:// URLs are rejected by
		// esc_url() at composer render time; adapter is transparent.
		$source = new Outpost_Source_Spotify();
		$raw    = array(
			'title'         => 'Sample',
			'thumbnail_url' => 'file:///etc/passwd',
			'provider_name' => 'Spotify',
		);
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( 'file:///etc/passwd', $mapped['u-photo'] );
	}

	public function test_a2_zero_width_unicode_in_provider_name_passes_through(): void {
		// Zero-width joiner / zero-width-non-joiner can be used to
		// spoof brand names. The adapter does not sanitize provider
		// names — it's the platform's data, the user sees what Spotify
		// claims about itself, and platform-spoofing concerns belong
		// to the user's mental model rather than to silent adapter
		// rewrites. (If this ever becomes a real attack pattern,
		// composer-side display can flag suspicious unicode.)
		$source = new Outpost_Source_Spotify();
		// U+200B zero-width space inside "Spotify".
		$raw = array(
			'title'         => 'Sample',
			'provider_name' => "Spo\xE2\x80\x8Btify",
		);
		$mapped = $source->map_extracted( $raw, 'https://open.spotify.com/track/0000000000000000000000' );

		$this->assertSame( "Spo\xE2\x80\x8Btify", $mapped['p-publication'] );
		$this->assertNotSame( 'Spotify', $mapped['p-publication'] );
	}

	// =====================================================================
	// A3. URL variants not in F7
	// =====================================================================

	public function test_a3_url_with_si_tracking_parameter_still_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue(
			$source->matches_url(
				'https://open.spotify.com/track/0000000000000000000000?si=abcdef1234567890'
			)
		);
	}

	public function test_a3_url_with_fragment_still_matches(): void {
		// e.g. #t=42 for timestamp jumping; pattern matcher ignores
		// fragments per HTTP semantics.
		$source = new Outpost_Source_Spotify();
		$this->assertTrue(
			$source->matches_url( 'https://open.spotify.com/track/0000000000000000000000#t=42' )
		);
	}

	public function test_a3_url_with_intl_prefix_and_query_string(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue(
			$source->matches_url(
				'https://open.spotify.com/intl-de/track/0000000000000000000000?si=tracking'
			)
		);
	}

	public function test_a3_url_without_trailing_slash_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue(
			$source->matches_url( 'https://open.spotify.com/track/0000000000000000000000' )
		);
	}

	public function test_a3_url_with_trailing_slash_matches(): void {
		$source = new Outpost_Source_Spotify();
		$this->assertTrue(
			$source->matches_url( 'https://open.spotify.com/track/0000000000000000000000/' )
		);
	}

	public function test_a3_oembed_fetch_url_preserves_query_parameters(): void {
		// If the user shares a URL with ?si= tracking, the oEmbed call
		// passes the full URL (URL-encoded) to Spotify's endpoint. The
		// response is identical to the untagged URL but the contract is:
		// adapter does not strip user-shared URL parameters.
		$source     = new Outpost_Source_Spotify();
		$ext        = new \Outpost_Source_Extractor_Oembed();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000?si=abcdef';
		$fetch_url  = $ext->compute_fetch_url( $source_url, $source->recipe_for_url( $source_url ) );

		$this->assertSame(
			'https://open.spotify.com/oembed?url=' . rawurlencode( $source_url ),
			$fetch_url
		);
		$this->assertStringContainsString( 'si%3Dabcdef', $fetch_url );
	}

	// =====================================================================
	// A4. Mapping edge cases
	// =====================================================================

	public function test_a4_all_mapped_fields_missing_yields_listen_of_only(): void {
		// Empty raw response (e.g. oEmbed returned `{}`). u-listen-of
		// always sets from @source_url; everything else absent.
		$source     = new Outpost_Source_Spotify();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';
		$mapped     = $source->map_extracted( array(), $source_url );

		$this->assertSame( $source_url, $mapped['u-listen-of'] );
		$this->assertArrayNotHasKey( 'p-name', $mapped );
		$this->assertArrayNotHasKey( 'u-photo', $mapped );
		$this->assertArrayNotHasKey( 'p-publication', $mapped );
		$this->assertCount( 1, $mapped );
	}

	public function test_a4_null_field_treated_identically_to_missing(): void {
		// `Source_Base::resolve_mapping_value` returns the raw value
		// (including null) when array_key_exists; map_extracted then
		// drops null. Net effect: null and missing produce identical
		// downstream behavior.
		$source     = new Outpost_Source_Spotify();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';
		$mapped     = $source->map_extracted(
			array(
				'title'         => null,
				'thumbnail_url' => null,
				'provider_name' => 'Spotify',
			),
			$source_url
		);

		$this->assertArrayNotHasKey( 'p-name', $mapped );
		$this->assertArrayNotHasKey( 'u-photo', $mapped );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}

	public function test_a4_empty_string_title_preserved_as_empty_string(): void {
		// Adapter contract: empty string is NOT the same as missing.
		// Composer-side code is responsible for treating "" as
		// no-title (warning + manual entry needed); this test
		// documents the layer boundary.
		$source     = new Outpost_Source_Spotify();
		$raw        = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-empty-title' );
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';
		$mapped     = $source->map_extracted( $raw, $source_url );

		$this->assertArrayHasKey( 'p-name', $mapped );
		$this->assertSame( '', $mapped['p-name'] );
		// thumbnail_url + provider_name still present.
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
	}

	public function test_a4_show_fixture_maps_correctly(): void {
		$source     = new Outpost_Source_Spotify();
		$raw        = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-show-success' );
		$source_url = 'https://open.spotify.com/show/0000000000000000000000';
		$mapped     = $source->map_extracted( $raw, $source_url );

		$this->assertSame( 'Sample Podcast Show Title', $mapped['p-name'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
	}

	public function test_a4_playlist_fixture_maps_correctly(): void {
		$source     = new Outpost_Source_Spotify();
		$raw        = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-playlist-success' );
		$source_url = 'https://open.spotify.com/playlist/0000000000000000000000';
		$mapped     = $source->map_extracted( $raw, $source_url );

		$this->assertSame( 'Sample Playlist Title', $mapped['p-name'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
	}

	public function test_a4_503_non_json_body_rejected_by_extractor(): void {
		// 503 response carries an HTML body (nginx error page).
		// Extractor_Oembed::parse must reject because the body isn't
		// JSON. Preview endpoint surfaces this as fetch_failed 502.
		$ext  = new \Outpost_Source_Extractor_Oembed();
		$body = SourceFixtureLoader::load_raw_fixture( 'spotify', 'oembed-503', 'txt' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'JSON' );
		$ext->parse( $body, array( 'endpoint' => 'https://open.spotify.com/oembed?url={url}' ) );
	}

	// =====================================================================
	// A4 (continued). Mapping-side edge cases that wouldn't fit elsewhere
	// =====================================================================

	public function test_a4_mapping_drops_null_provider_name_field(): void {
		$source     = new Outpost_Source_Spotify();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';
		$mapped     = $source->map_extracted(
			array(
				'title'         => 'Sample',
				'provider_name' => null,
			),
			$source_url
		);

		$this->assertSame( 'Sample', $mapped['p-name'] );
		$this->assertArrayNotHasKey( 'p-publication', $mapped );
	}

	public function test_a4_listen_of_always_set_even_with_empty_raw(): void {
		// u-listen-of is the load-bearing guarantee — even if oEmbed
		// returns nothing useful, the user shared a URL and that URL
		// gets stored on the post.
		$source     = new Outpost_Source_Spotify();
		$source_url = 'https://open.spotify.com/track/0000000000000000000000';
		$mapped     = $source->map_extracted( array(), $source_url );

		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}
}
