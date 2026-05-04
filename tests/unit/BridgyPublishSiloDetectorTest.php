<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Silo_Detector (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Silo_Detector;
use WP_Mock;

final class BridgyPublishSiloDetectorTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static fn ( string $u ) => parse_url( $u )
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_bluesky_app_detected(): void {
		$this->assertSame(
			'bluesky',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://bsky.app/profile/example.com/post/abc' )
		);
	}

	public function test_bluesky_social_detected(): void {
		$this->assertSame(
			'bluesky',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://bsky.social/foo' )
		);
	}

	public function test_flickr_detected(): void {
		$this->assertSame(
			'flickr',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://www.flickr.com/photos/user/123' )
		);
		$this->assertSame(
			'flickr',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://flickr.com/photos/user/123' )
		);
	}

	public function test_github_detected(): void {
		$this->assertSame(
			'github',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://github.com/example/repo/issues/1' )
		);
	}

	public function test_reddit_detected_for_main_domain(): void {
		$this->assertSame(
			'reddit',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://www.reddit.com/r/example/comments/1/' )
		);
		$this->assertSame(
			'reddit',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://old.reddit.com/r/example' )
		);
	}

	public function test_reddit_detected_for_redd_it_short(): void {
		$this->assertSame(
			'reddit',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://redd.it/abc123' )
		);
	}

	public function test_mastodon_detected_for_dot_social_suffix(): void {
		$this->assertSame(
			'mastodon',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://mastodon.social/@user/123' )
		);
		$this->assertSame(
			'mastodon',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://example.social/@anyone' )
		);
	}

	public function test_mastodon_detected_via_filter_supplied_host(): void {
		WP_Mock::onFilter( 'outpost_bridgy_mastodon_hosts' )
			->withAnyArgs()
			->reply( array( 'custom.example' ) );

		$this->assertSame(
			'mastodon',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://custom.example/@user' )
		);
	}

	public function test_unknown_url_returns_null(): void {
		$this->assertNull(
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://example.com/some/page' )
		);
	}

	public function test_malformed_url_returns_null(): void {
		$this->assertNull(
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'not-a-url' )
		);
	}

	public function test_empty_string_returns_null(): void {
		$this->assertNull(
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( '' )
		);
	}

	public function test_subdomains_match_correctly(): void {
		// Subdomain of github.com.
		$this->assertSame(
			'github',
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://gist.github.com/example/abc' )
		);
	}

	public function test_does_not_misclassify_reddit_for_arbitrary_subdomain(): void {
		// `subreddit.example.com` is not Reddit; ensure suffix matching
		// requires the ACTUAL reddit.com or redd.it host.
		$this->assertNull(
			Outpost_Bridgy_Publish_Silo_Detector::detect_silo( 'https://subreddit.example.com/' )
		);
	}
}
