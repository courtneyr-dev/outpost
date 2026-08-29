<?php
/**
 * Unit tests for Outpost_Source_Ifixit (G14a).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Ifixit;
use Outpost_Source_Registry;
use WP_Mock;

final class G14aIfixitTest extends \WP_Mock\Tools\TestCase {

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
		$caps = ( new Outpost_Source_Ifixit() )->capabilities();
		$this->assertSame( 'ifixit', $caps['id'] );
		$this->assertSame( 'iFixit', $caps['label'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-bookmark-of', $caps['h_entry_property'] );
		$this->assertContains( 'repair', $caps['tags_default'] );
	}

	public function test_capabilities_caveats_include_license_note(): void {
		$caps  = ( new Outpost_Source_Ifixit() )->capabilities();
		$found = false;
		foreach ( (array) $caps['caveats'] as $caveat ) {
			if ( false !== strpos( (string) $caveat, 'CC BY-NC-SA' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'iFixit caveats should mention CC BY-NC-SA license attribution.' );
	}

	public function test_capabilities_caveats_mention_g14b(): void {
		$caps  = ( new Outpost_Source_Ifixit() )->capabilities();
		$found = false;
		foreach ( (array) $caps['caveats'] as $caveat ) {
			if ( false !== stripos( (string) $caveat, 'G14b' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'iFixit caveats should mention deferred G14b api_json path.' );
	}

	public function test_guide_url_with_apex_matches(): void {
		$source = new Outpost_Source_Ifixit();
		$this->assertTrue( $source->matches_url( 'https://ifixit.com/Guide/iPhone+12+Battery/137359' ) );
	}

	public function test_guide_url_with_www_matches(): void {
		$source = new Outpost_Source_Ifixit();
		$this->assertTrue( $source->matches_url( 'https://www.ifixit.com/Guide/iPhone+12+Battery/137359' ) );
	}

	public function test_wiki_url_does_not_match(): void {
		$source = new Outpost_Source_Ifixit();
		// Wiki pages have a different API endpoint shape; v1 doesn't claim them.
		$this->assertFalse( $source->matches_url( 'https://www.ifixit.com/Wiki/iPhone_12' ) );
	}

	public function test_homepage_does_not_match(): void {
		$source = new Outpost_Source_Ifixit();
		$this->assertFalse( $source->matches_url( 'https://www.ifixit.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://ifixit.com/' ) );
	}

	public function test_other_hosts_do_not_match(): void {
		$source = new Outpost_Source_Ifixit();
		$this->assertFalse( $source->matches_url( 'https://example.com/Guide/something/123' ) );
	}

	public function test_attribution_html_contains_source_url_and_license(): void {
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			static function ( $u ) {
				return $u;
			}
		);
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			static function ( $s ) {
				return $s;
			}
		);

		$out = Outpost_Source_Ifixit::attribution_html( 'https://www.ifixit.com/Guide/iPhone/123' );
		$this->assertStringContainsString( 'https://www.ifixit.com/Guide/iPhone/123', $out );
		$this->assertStringContainsString( 'CC BY-NC-SA', $out );
	}

	public function test_attribution_html_filter_can_override(): void {
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			static function ( $u ) {
				return $u;
			}
		);
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			static function ( $s ) {
				return $s;
			}
		);
		WP_Mock::onFilter( 'outpost_ifixit_attribution_html' )
			->withAnyArgs()
			->reply( '<p>Custom attribution</p>' );

		$out = Outpost_Source_Ifixit::attribution_html( 'https://www.ifixit.com/Guide/x/1' );
		$this->assertSame( '<p>Custom attribution</p>', $out );
	}
}
