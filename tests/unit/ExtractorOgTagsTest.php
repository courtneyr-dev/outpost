<?php
/**
 * Unit tests for Outpost_Source_Extractor_Og_Tags (F16).
 *
 * F5 stubbed the extractor with a Not_Implemented throw; F16 ships
 * the concrete parser. These tests cover:
 *
 *   - Standard OG tags are extracted with og: prefix preserved
 *   - HTML entity decoding at parse time (load-bearing F16 contract)
 *   - Attribute-order variants (content before property)
 *   - Quote-style variants (double, single, unquoted)
 *   - `name=` tolerance for badly-coded sites
 *   - Multiple og:image collapse to first
 *   - Empty content values skipped
 *   - Self-closing XHTML <meta /> variants
 *   - Body without <head> still parses
 *   - Oversized body throws RuntimeException
 *   - Unrelated meta tags ignored
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class ExtractorOgTagsTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function parse( string $body ): array {
		$ext = new Outpost_Source_Extractor_Og_Tags();
		return $ext->parse( $body, array() );
	}

	// --- identity --------------------------------------------------------

	public function test_id_is_og_tags(): void {
		$ext = new Outpost_Source_Extractor_Og_Tags();
		$this->assertSame( 'og_tags', $ext->id() );
	}

	public function test_expected_content_types_includes_html_and_xhtml(): void {
		$ext = new Outpost_Source_Extractor_Og_Tags();
		$this->assertSame(
			array( 'text/html', 'application/xhtml+xml' ),
			$ext->expected_content_types()
		);
	}

	public function test_compute_fetch_url_returns_source_url_verbatim(): void {
		$ext = new Outpost_Source_Extractor_Og_Tags();
		$this->assertSame(
			'https://example.com/page',
			$ext->compute_fetch_url( 'https://example.com/page', array() )
		);
	}

	// --- happy path ------------------------------------------------------

	public function test_parses_standard_og_tags_from_snipd_fixture(): void {
		$body = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-snip-success', 'html' );

		$result = $this->parse( $body );

		$this->assertSame( 'Sample Snip Title — A Highlighted Moment', $result['og:title'] );
		$this->assertStringContainsString( 'auto-generated summary', $result['og:description'] );
		$this->assertSame( 'https://share-static.snipd.com/snip/example/cover.jpg', $result['og:image'] );
		$this->assertSame( 'https://share.snipd.com/snip/00000000-aaaa-bbbb-cccc-000000000000', $result['og:url'] );
		$this->assertSame( 'Snipd', $result['og:site_name'] );
		$this->assertSame( 'article', $result['og:type'] );
	}

	public function test_parses_twitch_vod_fixture(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'twitch', 'og-vod-success', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'Sample VOD Title', $result['og:title'] );
		$this->assertSame( 'video.other', $result['og:type'] );
	}

	public function test_parses_pinterest_fixture(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'pinterest', 'og-pin-success', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'Sample Pin Title', $result['og:title'] );
		$this->assertSame( 'https://i.pinimg.com/example-pin-image.jpg', $result['og:image'] );
	}

	// --- entity decoding (F16 #2 contract) -------------------------------

	public function test_decodes_named_entities_in_content_values(): void {
		$body = '<html><head>'
			. '<meta property="og:title" content="Tom &amp; Jerry: A Tale of &quot;Friendship&quot;">'
			. '</head><body></body></html>';

		$result = $this->parse( $body );

		$this->assertSame( 'Tom & Jerry: A Tale of "Friendship"', $result['og:title'] );
	}

	public function test_decodes_html5_named_entities(): void {
		$body = '<html><head><meta property="og:title" content="Article Title&hellip; with em &mdash; dash"></head></html>';

		$result = $this->parse( $body );

		$this->assertSame( 'Article Title… with em — dash', $result['og:title'] );
	}

	public function test_decodes_numeric_character_references(): void {
		$body = '<html><head><meta property="og:title" content="Smart &#8220;quoted&#8221; text"></head></html>';

		$result = $this->parse( $body );

		$this->assertSame( 'Smart “quoted” text', $result['og:title'] );
	}

	public function test_decodes_apos_entity(): void {
		$body = '<html><head><meta property="og:title" content="It&#39;s working"></head></html>';

		$result = $this->parse( $body );

		$this->assertSame( "It's working", $result['og:title'] );
	}

	// --- attribute / quote variants from edge-case fixture ---------------

	public function test_attribute_order_reversed_content_before_property(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'Reversed-Attribute Title', $result['og:title'] );
	}

	public function test_single_quoted_attribute_values_parse(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertStringContainsString( 'ampersand', $result['og:description'] );
		$this->assertStringContainsString( '"quoted phrase"', $result['og:description'] );
	}

	public function test_name_attribute_tolerated_when_property_absent(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'https://example.com/edge-case-image.jpg', $result['og:image'] );
	}

	public function test_multiple_og_image_collapses_to_first(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		// First og:image (the one with `name=`) should win — second
		// has 'SECOND' in its URL and must not be the resolved value.
		$this->assertStringNotContainsString( 'SECOND', $result['og:image'] );
	}

	public function test_self_closing_meta_variant(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'Edge Case Suite', $result['og:site_name'] );
	}

	public function test_whitespace_around_attr_spec(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'en_US', $result['og:locale'] );
	}

	public function test_empty_content_filtered(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertArrayNotHasKey( 'og:audio', $result );
	}

	public function test_decodes_named_entity_in_url_field(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		// `&amp;` in og:url decodes to `&` per the contract; consumers
		// that want the raw URL form can re-encode at render time.
		$this->assertSame( 'https://example.com/path?key=value&other=more', $result['og:url'] );
	}

	public function test_numeric_reference_in_type_field(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-edge-cases', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'article—long-form', $result['og:type'] );
	}

	// --- no head fragment ------------------------------------------------

	public function test_body_without_head_still_parses(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-no-head', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( 'Headless Title', $result['og:title'] );
		// Fixture uses entity-escaped `&lt;head&gt;` per HTML5; parser
		// decodes back to literal `<head>`.
		$this->assertStringContainsString( '<head>', $result['og:description'] );
		$this->assertSame( 'https://example.com/no-head-image.jpg', $result['og:image'] );
	}

	public function test_no_og_tags_at_all_returns_empty_array(): void {
		$body   = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-empty', 'html' );
		$result = $this->parse( $body );

		$this->assertSame( array(), $result );
	}

	// --- non-og meta tags ignored ----------------------------------------

	public function test_unrelated_meta_tags_ignored(): void {
		$body = '<html><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width">'
			. '<meta name="description" content="page description but not og:">'
			. '<meta property="og:title" content="Real OG Title">'
			. '</head></html>';

		$result = $this->parse( $body );

		$this->assertSame( 'Real OG Title', $result['og:title'] );
		$this->assertCount( 1, $result, 'Non-og meta tags must not appear in extracted output.' );
	}

	public function test_twitter_card_meta_ignored(): void {
		$body = '<html><head>'
			. '<meta name="twitter:card" content="summary_large_image">'
			. '<meta name="twitter:title" content="Twitter title">'
			. '<meta property="og:title" content="OG title">'
			. '</head></html>';

		$result = $this->parse( $body );

		$this->assertSame( 'OG title', $result['og:title'] );
		$this->assertArrayNotHasKey( 'twitter:title', $result );
	}

	// --- size cap --------------------------------------------------------

	public function test_oversized_body_throws_runtime_exception(): void {
		// 2 MB + 1 byte should trip the cap.
		$body = str_repeat( 'A', 2_097_153 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '2 MB' );
		$this->parse( $body );
	}

	public function test_body_exactly_at_cap_passes(): void {
		// 2 MB = 2_097_152 bytes. Wrap with valid HTML at the start
		// so the parser has something to work with.
		$head = '<html><head><meta property="og:title" content="At the cap"></head><body>';
		$pad  = str_repeat( 'A', 2_097_152 - strlen( $head ) - strlen( '</body></html>' ) );
		$body = $head . $pad . '</body></html>';

		$this->assertSame( 2_097_152, strlen( $body ) );

		$result = $this->parse( $body );
		$this->assertSame( 'At the cap', $result['og:title'] );
	}

	// --- recipe is unused ------------------------------------------------

	public function test_recipe_is_not_consulted_during_parse(): void {
		// The og_tags recipe is purely a fetch-side declaration; parse
		// must work the same regardless of recipe contents.
		$body = '<html><head><meta property="og:title" content="Recipe Independent"></head></html>';
		$ext  = new Outpost_Source_Extractor_Og_Tags();

		$with_recipe = $ext->parse( $body, array( 'fetch_url' => 'unrelated' ) );
		$no_recipe   = $ext->parse( $body, array() );

		$this->assertSame( $with_recipe, $no_recipe );
	}
}
