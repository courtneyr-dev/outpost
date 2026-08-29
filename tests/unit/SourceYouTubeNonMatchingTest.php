<?php
/**
 * Negative-pattern tests for Outpost_Source_YouTube (F15).
 *
 * Asserts the registry routes YouTube URLs the adapter does NOT
 * claim — channel, /c/, /@, /playlist, /feed/, homepage, watch URLs
 * with empty `v=` — through Source_Unknown's `*` fallback rather
 * than the YouTube source. Complements SourceYouTubeTest's positive
 * matches_url assertions; this file exercises the registry-level
 * dispatch that includes Source_Unknown in the candidate set.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_YouTube;
use Outpost_Source_Registry;
use WP_Mock;

final class SourceYouTubeNonMatchingTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		// F14 #10 — registry reads settings via get_option.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	private function assert_routes_to_unknown( string $url ): void {
		$found = Outpost_Source_Registry::find_for_url( $url );
		$this->assertNotNull( $found, "find_for_url returned null for {$url}" );
		$this->assertSame(
			'unknown',
			$found->capabilities()['id'],
			"URL {$url} should fall through to Source_Unknown, not be claimed by YouTube"
		);
	}

	public function test_channel_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/channel/UCEXAMPLE' );
	}

	public function test_apex_channel_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://youtube.com/channel/UCEXAMPLE' );
	}

	public function test_custom_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/c/example-channel' );
	}

	public function test_handle_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/@example-handle' );
	}

	public function test_playlist_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/playlist?list=PLEXAMPLE' );
	}

	public function test_feed_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/feed/subscriptions' );
	}

	public function test_homepage_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/' );
	}

	public function test_about_page_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://www.youtube.com/about' );
	}

	public function test_youtu_be_root_routes_to_unknown(): void {
		// Bare youtu.be/ has no video id — routes to Source_Unknown,
		// not YouTube, even though the host is in YouTube's host_patterns.
		$this->assert_routes_to_unknown( 'https://youtu.be/' );
	}

	public function test_studio_subdomain_routes_to_unknown(): void {
		// studio.youtube.com isn't in the path-constrained host list.
		$this->assert_routes_to_unknown( 'https://studio.youtube.com/channel/UCEXAMPLE/videos' );
	}

	public function test_non_youtube_url_routes_to_unknown(): void {
		$this->assert_routes_to_unknown( 'https://example.com/article' );
	}
}
