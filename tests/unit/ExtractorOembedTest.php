<?php
/**
 * Unit tests for Outpost_Source_Extractor_Oembed.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Extractor_Oembed;
use WP_Mock;

final class ExtractorOembedTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_id_is_oembed(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->assertSame( 'oembed', $ext->id() );
	}

	public function test_expected_content_types_is_application_json_only(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->assertSame( array( 'application/json' ), $ext->expected_content_types() );
	}

	public function test_compute_fetch_url_substitutes_url_token(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$got = $ext->compute_fetch_url(
			'https://example.com/track/123',
			array( 'endpoint' => 'https://example.com/oembed?url={url}' )
		);
		// rawurlencode preserves alphanumerics, encodes / and :
		$this->assertSame(
			'https://example.com/oembed?url=https%3A%2F%2Fexample.com%2Ftrack%2F123',
			$got
		);
	}

	public function test_compute_fetch_url_throws_when_endpoint_missing(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'endpoint' );
		$ext->compute_fetch_url( 'https://example.com/', array() );
	}

	public function test_compute_fetch_url_throws_when_placeholder_missing(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '{url}' );
		$ext->compute_fetch_url(
			'https://example.com/',
			array( 'endpoint' => 'https://example.com/oembed' )
		);
	}

	public function test_compute_fetch_url_throws_when_endpoint_not_string(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->expectException( \InvalidArgumentException::class );
		$ext->compute_fetch_url(
			'https://example.com/',
			array( 'endpoint' => 12345 )
		);
	}

	public function test_parse_handles_canonical_oembed_response(): void {
		$ext  = new Outpost_Source_Extractor_Oembed();
		// Canonical oEmbed shape per spec — generic example.com value.
		$body = json_encode(
			array(
				'version'         => '1.0',
				'type'            => 'rich',
				'provider_name'   => 'Example Provider',
				'provider_url'    => 'https://example.com',
				'title'           => 'Example Track',
				'thumbnail_url'   => 'https://example.com/thumb.jpg',
				'thumbnail_width' => 300,
				'thumbnail_height' => 300,
				'html'            => '<iframe src="https://example.com/embed/123"></iframe>',
			)
		);
		$out = $ext->parse( $body, array() );
		$this->assertSame( 'rich', $out['type'] );
		$this->assertSame( 'Example Track', $out['title'] );
		$this->assertSame( 'https://example.com/thumb.jpg', $out['thumbnail_url'] );
	}

	public function test_parse_rejects_non_json_body(): void {
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'JSON' );
		$ext->parse( '<html>not json</html>', array() );
	}

	public function test_parse_rejects_json_array_shape(): void {
		// JSON arrays are valid JSON but not valid oEmbed responses.
		$ext = new Outpost_Source_Extractor_Oembed();
		$this->expectException( \RuntimeException::class );
		$ext->parse( json_encode( array( 'a', 'b', 'c' ) ), array() );
	}

	public function test_parse_rejects_oversized_body(): void {
		$ext  = new Outpost_Source_Extractor_Oembed();
		$body = str_repeat( 'x', 1024 * 1024 + 1 );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '1 MB' );
		$ext->parse( $body, array() );
	}

	public function test_parse_accepts_empty_object(): void {
		// Edge case: an empty JSON object. Technically valid JSON; the
		// oEmbed spec doesn't forbid it. Source's mapping handles
		// missing fields, so we accept and let the mapping drop them.
		$ext = new Outpost_Source_Extractor_Oembed();
		$out = $ext->parse( '{}', array() );
		$this->assertSame( array(), $out );
	}
}
