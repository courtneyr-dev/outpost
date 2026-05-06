<?php
/**
 * Unit tests for Outpost_Source_Snipd (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Snipd;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceSnipdTest extends \WP_Mock\Tools\TestCase {

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
		$source = new Outpost_Source_Snipd();
		$caps   = $source->capabilities();

		$this->assertSame( 'snipd', $caps['id'] );
		$this->assertSame( 'Snipd', $caps['label'] );
		$this->assertSame( array( 'share.snipd.com' ), $caps['host_patterns'] );
		$this->assertSame( 'unambiguous', $caps['ambiguity'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-listen-of', $caps['h_entry_property'] );
	}

	public function test_mapping_targets_h_entry_listen_properties(): void {
		$source = new Outpost_Source_Snipd();
		$caps   = $source->capabilities();

		$this->assertSame( 'p-name', $caps['mapping']['og:title'] );
		$this->assertSame( 'p-summary', $caps['mapping']['og:description'] );
		$this->assertSame( 'u-photo', $caps['mapping']['og:image'] );
		$this->assertSame( 'u-listen-of', $caps['mapping']['@source_url'] );
	}

	public function test_snip_url_matches(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertTrue( $source->matches_url( 'https://share.snipd.com/snip/00000000-aaaa-bbbb-cccc-000000000000' ) );
	}

	public function test_episode_url_matches(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertTrue( $source->matches_url( 'https://share.snipd.com/episode/00000000' ) );
	}

	public function test_show_url_matches(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertTrue( $source->matches_url( 'https://share.snipd.com/show/00000000' ) );
	}

	public function test_profile_url_does_not_match(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertFalse( $source->matches_url( 'https://share.snipd.com/user/example-user' ) );
	}

	public function test_homepage_does_not_match(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertFalse( $source->matches_url( 'https://share.snipd.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://snipd.com/' ) );
	}

	public function test_arbitrary_url_does_not_match(): void {
		$source = new Outpost_Source_Snipd();
		$this->assertFalse( $source->matches_url( 'https://example.com/article' ) );
	}

	public function test_mode_for_snip_path_is_quote(): void {
		// G15a item 1: /snip/{id} maps to Post Kind 'quote'. The snip is
		// a transcript excerpt; the timestamped link is the citation.
		$source = new Outpost_Source_Snipd();
		$this->assertSame( 'quote', $source->mode_for_url( 'https://share.snipd.com/snip/anything' ) );
	}

	public function test_mode_for_episode_path_is_listen(): void {
		// G15a item 1: /episode/{id} preserves the existing F-phase
		// listen mode — the episode itself is a single listen event.
		$source = new Outpost_Source_Snipd();
		$this->assertSame( 'listen', $source->mode_for_url( 'https://share.snipd.com/episode/abc' ) );
	}

	public function test_mode_for_show_path_is_bookmark(): void {
		// G15a item 1: /show/{id} maps to bookmark — a show is a
		// discovery target, not a single listen event.
		$source = new Outpost_Source_Snipd();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://share.snipd.com/show/abc' ) );
	}

	public function test_mode_for_unknown_path_defaults_to_bookmark(): void {
		// G15a item 1: defensive fallback for any future Snipd path
		// patterns added to CLAIMED_PATH_PREFIXES without a matching
		// PATH_TO_MODE entry. Safe default is bookmark.
		$source = new Outpost_Source_Snipd();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://share.snipd.com/totally-new-thing/abc' ) );
	}

	public function test_mapping_produces_listen_h_entry(): void {
		$source     = new Outpost_Source_Snipd();
		$body       = SourceFixtureLoader::load_raw_fixture( 'snipd', 'og-snip-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://share.snipd.com/snip/00000000-aaaa-bbbb-cccc-000000000000';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Snip Title — A Highlighted Moment', $mapped['p-name'] );
		$this->assertStringContainsString( 'auto-generated summary', $mapped['p-summary'] );
		$this->assertSame( 'https://share-static.snipd.com/snip/example/cover.jpg', $mapped['u-photo'] );
		$this->assertSame( $source_url, $mapped['u-listen-of'] );
	}

	public function test_registry_finds_snipd_for_snip_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Snipd() );
		$found = Outpost_Source_Registry::find_for_url( 'https://share.snipd.com/snip/abc' );

		$this->assertNotNull( $found );
		$this->assertSame( 'snipd', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_profile_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Snipd() );
		$found = Outpost_Source_Registry::find_for_url( 'https://share.snipd.com/user/anyone' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}
}
