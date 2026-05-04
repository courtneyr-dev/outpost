<?php
/**
 * Unit tests for Outpost_Source_Unknown — the universal fallback.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Unknown;
use WP_Mock;

final class SourceUnknownTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_capabilities_returns_universal_fallback_shape(): void {
		$source = new Outpost_Source_Unknown();
		$caps   = $source->capabilities();

		$this->assertSame( 'unknown', $caps['id'] );
		$this->assertSame( array( '*' ), $caps['host_patterns'] );
		$this->assertSame( 'ambiguous', $caps['ambiguity'] );
		$this->assertNull( $caps['mode'] );
		$this->assertSame( array( 'reply', 'like', 'repost', 'bookmark' ), $caps['mode_options'] );
		$this->assertSame( 'bookmark', $caps['mode_default'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertNull( $caps['h_entry_property'] );
		$this->assertFalse( $caps['auth_required'] );
		$this->assertSame( array(), $caps['tags_default'] );
		$this->assertNotEmpty( $caps['caveats'] );
		foreach ( $caps['caveats'] as $caveat ) {
			$this->assertIsString( $caveat );
		}
	}

	public function test_matches_url_matches_any_url(): void {
		$source = new Outpost_Source_Unknown();
		$this->assertTrue( $source->matches_url( 'https://example.com/' ) );
		$this->assertTrue( $source->matches_url( 'https://anything.example.test/path/123' ) );
		$this->assertTrue( $source->matches_url( 'http://localhost/' ) );
	}

	public function test_mode_for_url_returns_bookmark_default(): void {
		$source = new Outpost_Source_Unknown();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://example.com/' ) );
	}

	public function test_outpost_source_capabilities_filter_can_modify_shape(): void {
		WP_Mock::onFilter( 'outpost_source_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'               => 'unknown',
					'label'            => 'Custom Generic Label',
					'host_patterns'    => array( '*' ),
					'ambiguity'        => 'ambiguous',
					'mode'             => null,
					'mode_options'     => array( 'bookmark' ),
					'mode_default'     => 'bookmark',
					'extractor'        => 'og_tags',
					'recipe'           => array( 'fetch_url' => '@source_url' ),
					'mapping'          => array(),
					'h_entry_property' => null,
					'auth_required'    => false,
					'tags_default'     => array(),
					'caveats'          => array(),
				)
			);

		$source = new Outpost_Source_Unknown();
		$caps   = $source->capabilities();

		$this->assertSame( 'Custom Generic Label', $caps['label'] );
		$this->assertSame( array( 'bookmark' ), $caps['mode_options'] );
	}

	public function test_outpost_source_capabilities_filter_falls_back_on_non_array(): void {
		WP_Mock::onFilter( 'outpost_source_capabilities' )
			->withAnyArgs()
			->reply( 'oops, returned a string' );

		$source = new Outpost_Source_Unknown();
		$caps   = $source->capabilities();

		// Adapter falls back to its own shape rather than emit malformed data.
		$this->assertSame( 'unknown', $caps['id'] );
	}

	public function test_map_extracted_handles_og_field_shape(): void {
		// Even though the og_tags parser is stubbed, the source's
		// mapping logic itself is exercisable with a fake raw shape.
		$source = new Outpost_Source_Unknown();
		$out    = $source->map_extracted(
			array(
				'og:title'       => 'Page Title',
				'og:description' => 'A description',
				'og:image'       => 'https://example.com/image.jpg',
				'og:irrelevant'  => 'dropped',
			),
			'https://example.com/page'
		);
		$this->assertSame(
			array(
				'p-name'    => 'Page Title',
				'p-summary' => 'A description',
				'u-photo'   => 'https://example.com/image.jpg',
			),
			$out
		);
	}
}
