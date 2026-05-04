<?php
/**
 * Unit tests for Outpost_Source_Goodreads (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Goodreads;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceGoodreadsTest extends \WP_Mock\Tools\TestCase {

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
		$source = new Outpost_Source_Goodreads();
		$caps   = $source->capabilities();

		$this->assertSame( 'goodreads', $caps['id'] );
		$this->assertSame( 'read', $caps['mode'] );
		$this->assertSame( 'u-read-of', $caps['h_entry_property'] );
	}

	public function test_book_show_url_matches(): void {
		$source = new Outpost_Source_Goodreads();
		$this->assertTrue( $source->matches_url( 'https://www.goodreads.com/book/show/0000000-sample-book-title' ) );
	}

	public function test_review_show_url_matches(): void {
		$source = new Outpost_Source_Goodreads();
		$this->assertTrue( $source->matches_url( 'https://www.goodreads.com/review/show/000000000' ) );
	}

	public function test_user_shelf_url_does_not_match(): void {
		$source = new Outpost_Source_Goodreads();
		$this->assertFalse( $source->matches_url( 'https://www.goodreads.com/user/show/0000000-example-user' ) );
	}

	public function test_search_url_does_not_match(): void {
		$source = new Outpost_Source_Goodreads();
		$this->assertFalse( $source->matches_url( 'https://www.goodreads.com/search?q=test' ) );
	}

	public function test_mode_is_read(): void {
		$source = new Outpost_Source_Goodreads();
		$this->assertSame( 'read', $source->mode_for_url( 'https://www.goodreads.com/book/show/123' ) );
	}

	public function test_mapping_for_book_fixture(): void {
		$source     = new Outpost_Source_Goodreads();
		$body       = SourceFixtureLoader::load_raw_fixture( 'goodreads', 'og-book-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.goodreads.com/book/show/0000000-sample-book-title';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Book Title', $mapped['p-name'] );
		// Description fixture deliberately includes &amp; to verify
		// entity decoding flows through Source_Base mapping.
		$this->assertStringContainsString( '&', $mapped['p-summary'] );
		$this->assertStringNotContainsString( '&amp;', $mapped['p-summary'] );
		$this->assertSame( $source_url, $mapped['u-read-of'] );
	}

	public function test_registry_finds_goodreads_for_book_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Goodreads() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.goodreads.com/book/show/123' );

		$this->assertNotNull( $found );
		$this->assertSame( 'goodreads', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_user_shelf(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Goodreads() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.goodreads.com/user/show/123' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}
}
