<?php
/**
 * Unit tests for Outpost_Source_Pretalx (G13a).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Pretalx;
use Outpost_Source_Registry;
use WP_Mock;

final class G13aPretalxTest extends \WP_Mock\Tools\TestCase {

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
		$caps = ( new Outpost_Source_Pretalx() )->capabilities();
		$this->assertSame( 'pretalx', $caps['id'] );
		$this->assertSame( 'quote', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-quotation-of', $caps['h_entry_property'] );
		$this->assertContains( 'conference', $caps['tags_default'] );
	}

	public function test_caveats_mention_g13b_g13c(): void {
		$caps  = ( new Outpost_Source_Pretalx() )->capabilities();
		$found = false;
		foreach ( (array) $caps['caveats'] as $caveat ) {
			if ( false !== stripos( (string) $caveat, 'G13b' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Pretalx caveats should mention deferred G13b/G13c paths.' );
	}

	public function test_talk_url_matches(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertTrue( $source->matches_url( 'https://pretalx.com/example-conf-2026/talk/ABCDEF' ) );
	}

	public function test_talk_url_with_trailing_slash_matches(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertTrue( $source->matches_url( 'https://pretalx.com/example-conf-2026/talk/ABCDEF/' ) );
	}

	public function test_schedule_url_matches(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertTrue( $source->matches_url( 'https://pretalx.com/example-conf-2026/schedule' ) );
	}

	public function test_speaker_url_matches(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertTrue( $source->matches_url( 'https://pretalx.com/example-conf-2026/speaker/abc-speaker' ) );
	}

	public function test_homepage_does_not_match(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertFalse( $source->matches_url( 'https://pretalx.com/' ) );
	}

	public function test_event_root_does_not_match(): void {
		// /{event} alone — no /talk/, /schedule/, /speaker/ — falls through.
		$source = new Outpost_Source_Pretalx();
		$this->assertFalse( $source->matches_url( 'https://pretalx.com/example-conf-2026' ) );
	}

	public function test_self_hosted_pretalx_does_not_match(): void {
		// Self-hosted instances live at custom domains (G13b territory).
		$source = new Outpost_Source_Pretalx();
		$this->assertFalse( $source->matches_url( 'https://cfp.example.com/event/talk/abc' ) );
	}

	public function test_other_hosts_do_not_match(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertFalse( $source->matches_url( 'https://example.com/something/talk/abc' ) );
	}

	public function test_talk_path_routes_to_quote_mode(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertSame( 'quote', $source->mode_for_url( 'https://pretalx.com/conf/talk/ABC' ) );
		$this->assertSame( 'quote', $source->mode_for_url( 'https://pretalx.com/conf/talk/ABC/' ) );
	}

	public function test_schedule_path_routes_to_bookmark(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://pretalx.com/conf/schedule' ) );
	}

	public function test_speaker_path_routes_to_bookmark(): void {
		$source = new Outpost_Source_Pretalx();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://pretalx.com/conf/speaker/abc' ) );
	}
}
