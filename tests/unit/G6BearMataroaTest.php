<?php
/**
 * Unit tests for the G6 newsletter-inbound batch:
 * Bear Blog + Mataroa.
 *
 * Coverage focuses on capabilities() shape + URL pattern matching
 * (positive + negative cases) + custom-domain filter behavior.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Bear_Blog;
use Outpost_Source_Mataroa;
use Outpost_Source_Registry;
use WP_Mock;

final class G6BearMataroaTest extends \WP_Mock\Tools\TestCase {

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

	// --- Bear Blog ------------------------------------------------------

	public function test_bear_blog_capabilities(): void {
		$caps = ( new Outpost_Source_Bear_Blog() )->capabilities();
		$this->assertSame( 'bear-blog', $caps['id'] );
		$this->assertSame( 'read', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-read-of', $caps['h_entry_property'] );
	}

	public function test_bear_blog_matches_subdomain(): void {
		$source = new Outpost_Source_Bear_Blog();
		$this->assertTrue( $source->matches_url( 'https://example-author.bearblog.dev/an-article/' ) );
	}

	public function test_bear_blog_does_not_match_apex(): void {
		$source = new Outpost_Source_Bear_Blog();
		$this->assertFalse( $source->matches_url( 'https://bearblog.dev/' ) );
	}

	public function test_bear_blog_custom_domain_via_filter(): void {
		WP_Mock::onFilter( 'outpost_bear_blog_domain_patterns' )
			->withAnyArgs()
			->reply( array( 'blog.example.com', '*.example-self-hosted.test' ) );

		$source = new Outpost_Source_Bear_Blog();
		$this->assertTrue( $source->matches_url( 'https://blog.example.com/post-1/' ) );
		$this->assertTrue( $source->matches_url( 'https://writer.example-self-hosted.test/p/1' ) );
		$this->assertFalse( $source->matches_url( 'https://other.example.com/' ) );
	}

	// --- Mataroa --------------------------------------------------------

	public function test_mataroa_capabilities(): void {
		$caps = ( new Outpost_Source_Mataroa() )->capabilities();
		$this->assertSame( 'mataroa', $caps['id'] );
		$this->assertSame( 'read', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
	}

	public function test_mataroa_matches_subdomain(): void {
		$source = new Outpost_Source_Mataroa();
		$this->assertTrue( $source->matches_url( 'https://example-pub.mataroa.blog/2026/05/something' ) );
	}

	public function test_mataroa_does_not_match_apex(): void {
		$source = new Outpost_Source_Mataroa();
		$this->assertFalse( $source->matches_url( 'https://mataroa.blog/' ) );
	}

	public function test_mataroa_custom_domain_via_filter(): void {
		WP_Mock::onFilter( 'outpost_mataroa_domain_patterns' )
			->withAnyArgs()
			->reply( array( 'self-hosted.example.test' ) );

		$source = new Outpost_Source_Mataroa();
		$this->assertTrue( $source->matches_url( 'https://self-hosted.example.test/p/1' ) );
		$this->assertFalse( $source->matches_url( 'https://other.example.test/' ) );
	}

	public function test_mataroa_mapping_routes_to_read_of(): void {
		$caps = ( new Outpost_Source_Mataroa() )->capabilities();
		$this->assertSame( 'p-name', $caps['mapping']['og:title'] );
		$this->assertSame( 'u-read-of', $caps['mapping']['@source_url'] );
	}
}
