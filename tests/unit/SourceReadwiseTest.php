<?php
/**
 * Unit tests for Outpost_Source_Readwise (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Readwise;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceReadwiseTest extends \WP_Mock\Tools\TestCase {

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
		$source = new Outpost_Source_Readwise();
		$caps   = $source->capabilities();

		$this->assertSame( 'readwise', $caps['id'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertSame( 'u-bookmark-of', $caps['h_entry_property'] );
	}

	public function test_description_routes_to_e_content(): void {
		// Per Doc 2 §3.8: the highlight text IS the post body, not a
		// summary of it. Readwise mapping routes og:description to
		// e-content, NOT p-summary like other adapters.
		$source = new Outpost_Source_Readwise();
		$caps   = $source->capabilities();

		$this->assertSame( 'e-content', $caps['mapping']['og:description'] );
		$this->assertArrayNotHasKey( 'og:description', array_flip( $caps['mapping'] ), 'no value should be p-summary' );
	}

	public function test_highlight_url_matches(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertTrue( $source->matches_url( 'https://readwise.io/highlights/000000000' ) );
	}

	public function test_bookreview_url_matches(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertTrue( $source->matches_url( 'https://readwise.io/bookreview/000000' ) );
	}

	public function test_reader_document_url_matches(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertTrue( $source->matches_url( 'https://read.readwise.io/read/abc123' ) );
	}

	public function test_profile_url_does_not_match(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertFalse( $source->matches_url( 'https://readwise.io/@example-handle' ) );
	}

	public function test_library_url_does_not_match(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertFalse( $source->matches_url( 'https://readwise.io/library' ) );
	}

	public function test_mode_is_bookmark(): void {
		$source = new Outpost_Source_Readwise();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://readwise.io/highlights/123' ) );
	}

	public function test_mapping_for_highlight_fixture(): void {
		$source     = new Outpost_Source_Readwise();
		$body       = SourceFixtureLoader::load_raw_fixture( 'readwise', 'og-highlight-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://readwise.io/highlights/000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertStringContainsString( 'Sample Book Title', $mapped['p-name'] );
		$this->assertStringContainsString( 'highlight text', $mapped['e-content'] );
		$this->assertSame( $source_url, $mapped['u-bookmark-of'] );
		$this->assertArrayNotHasKey( 'p-summary', $mapped );
	}

	public function test_registry_finds_readwise_for_highlight_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Readwise() );
		$found = Outpost_Source_Registry::find_for_url( 'https://readwise.io/highlights/123' );

		$this->assertNotNull( $found );
		$this->assertSame( 'readwise', $found->capabilities()['id'] );
	}
}
