<?php
/**
 * Unit tests for the F17 source-adapter batch:
 * Vimeo, SoundCloud, Apple Music, Apple Podcasts, Bandcamp,
 * Substack, Medium, Reddit, Mastodon, Bluesky.
 *
 * Per-adapter coverage focuses on capabilities() shape + URL pattern
 * matching (positive + negative cases). Detailed mapping tests are
 * covered by the og_tags / oembed extractor's own test suites; this
 * batch verifies adapter SHAPE only.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Vimeo;
use Outpost_Source_SoundCloud;
use Outpost_Source_AppleMusic;
use Outpost_Source_ApplePodcasts;
use Outpost_Source_Bandcamp;
use Outpost_Source_Substack;
use Outpost_Source_Medium;
use Outpost_Source_Reddit;
use Outpost_Source_Mastodon;
use Outpost_Source_Bluesky;
use Outpost_Source_Registry;
use WP_Mock;

final class SourceF17BatchTest extends \WP_Mock\Tools\TestCase {

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

	// --- Vimeo ----------------------------------------------------------

	public function test_vimeo_capabilities(): void {
		$caps = ( new Outpost_Source_Vimeo() )->capabilities();
		$this->assertSame( 'vimeo', $caps['id'] );
		$this->assertSame( 'watch', $caps['mode'] );
		$this->assertSame( 'oembed', $caps['extractor'] );
		$this->assertSame( 'u-watch-of', $caps['h_entry_property'] );
		$this->assertStringContainsString( 'vimeo.com/api/oembed.json', $caps['recipe']['endpoint'] );
	}

	public function test_vimeo_url_matches(): void {
		$source = new Outpost_Source_Vimeo();
		$this->assertTrue( $source->matches_url( 'https://vimeo.com/123456789' ) );
		$this->assertTrue( $source->matches_url( 'https://player.vimeo.com/video/123456789' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/video/1' ) );
	}

	// --- SoundCloud -----------------------------------------------------

	public function test_soundcloud_capabilities(): void {
		$caps = ( new Outpost_Source_SoundCloud() )->capabilities();
		$this->assertSame( 'soundcloud', $caps['id'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'oembed', $caps['extractor'] );
		$this->assertSame( 'u-listen-of', $caps['h_entry_property'] );
	}

	public function test_soundcloud_url_matches(): void {
		$source = new Outpost_Source_SoundCloud();
		$this->assertTrue( $source->matches_url( 'https://soundcloud.com/example-user/example-track' ) );
		$this->assertTrue( $source->matches_url( 'https://on.soundcloud.com/abcdef' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/' ) );
	}

	// --- Apple Music ----------------------------------------------------

	public function test_apple_music_capabilities(): void {
		$caps = ( new Outpost_Source_AppleMusic() )->capabilities();
		$this->assertSame( 'apple-music', $caps['id'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
	}

	public function test_apple_music_url_matches(): void {
		$source = new Outpost_Source_AppleMusic();
		$this->assertTrue( $source->matches_url( 'https://music.apple.com/us/album/example/000000000' ) );
		$this->assertTrue( $source->matches_url( 'https://music.apple.com/us/song/example/000000000' ) );
		$this->assertFalse( $source->matches_url( 'https://podcasts.apple.com/us/podcast/example/id000' ) );
	}

	// --- Apple Podcasts -------------------------------------------------

	public function test_apple_podcasts_capabilities(): void {
		$caps = ( new Outpost_Source_ApplePodcasts() )->capabilities();
		$this->assertSame( 'apple-podcasts', $caps['id'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertContains( 'podcast', $caps['tags_default'] );
	}

	public function test_apple_podcasts_url_matches(): void {
		$source = new Outpost_Source_ApplePodcasts();
		$this->assertTrue( $source->matches_url( 'https://podcasts.apple.com/us/podcast/example/id000' ) );
		$this->assertFalse( $source->matches_url( 'https://music.apple.com/us/album/example/000' ) );
	}

	// --- Bandcamp -------------------------------------------------------

	public function test_bandcamp_capabilities(): void {
		$caps = ( new Outpost_Source_Bandcamp() )->capabilities();
		$this->assertSame( 'bandcamp', $caps['id'] );
		$this->assertSame( 'listen', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
	}

	public function test_bandcamp_subdomain_matches_apex_does_not(): void {
		$source = new Outpost_Source_Bandcamp();
		$this->assertTrue( $source->matches_url( 'https://example-artist.bandcamp.com/track/example-track' ) );
		$this->assertTrue( $source->matches_url( 'https://example-artist.bandcamp.com/album/example-album' ) );
		// Apex falls through (no subdomain).
		$this->assertFalse( $source->matches_url( 'https://bandcamp.com/' ) );
	}

	// --- Substack -------------------------------------------------------

	public function test_substack_capabilities(): void {
		$caps = ( new Outpost_Source_Substack() )->capabilities();
		$this->assertSame( 'substack', $caps['id'] );
		$this->assertSame( 'read', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-read-of', $caps['h_entry_property'] );
	}

	public function test_substack_subdomain_matches_apex_does_not(): void {
		$source = new Outpost_Source_Substack();
		$this->assertTrue( $source->matches_url( 'https://example-pub.substack.com/p/example-post-slug' ) );
		$this->assertFalse( $source->matches_url( 'https://substack.com/' ) );
	}

	// --- Medium ---------------------------------------------------------

	public function test_medium_capabilities(): void {
		$caps = ( new Outpost_Source_Medium() )->capabilities();
		$this->assertSame( 'medium', $caps['id'] );
		$this->assertSame( 'read', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
	}

	public function test_medium_url_matches(): void {
		$source = new Outpost_Source_Medium();
		$this->assertTrue( $source->matches_url( 'https://medium.com/@example/example-title-deadbeef' ) );
		$this->assertTrue( $source->matches_url( 'https://example-pub.medium.com/example-title-deadbeef' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/' ) );
	}

	// --- Reddit ---------------------------------------------------------

	public function test_reddit_capabilities(): void {
		$caps = ( new Outpost_Source_Reddit() )->capabilities();
		$this->assertSame( 'reddit', $caps['id'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertSame( 'u-bookmark-of', $caps['h_entry_property'] );
	}

	public function test_reddit_post_matches_homepage_does_not(): void {
		$source = new Outpost_Source_Reddit();
		$this->assertTrue( $source->matches_url( 'https://www.reddit.com/r/example/comments/abc123/example_post/' ) );
		$this->assertTrue( $source->matches_url( 'https://reddit.com/r/example/comments/abc123/' ) );
		$this->assertTrue( $source->matches_url( 'https://old.reddit.com/r/example/comments/abc123/' ) );
		$this->assertTrue( $source->matches_url( 'https://redd.it/abc123' ) );
		$this->assertFalse( $source->matches_url( 'https://reddit.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://reddit.com/r/example' ) );
		$this->assertFalse( $source->matches_url( 'https://reddit.com/user/example' ) );
	}

	// --- Mastodon -------------------------------------------------------

	public function test_mastodon_capabilities(): void {
		$caps = ( new Outpost_Source_Mastodon() )->capabilities();
		$this->assertSame( 'mastodon', $caps['id'] );
		$this->assertSame( 'reply', $caps['mode'] );
		$this->assertSame( 'u-in-reply-to', $caps['h_entry_property'] );
	}

	public function test_mastodon_allowlist_match(): void {
		$source = new Outpost_Source_Mastodon();
		// Allowlisted instances should match.
		$this->assertTrue( $source->matches_url( 'https://mastodon.social/@example/123456789' ) );
		$this->assertTrue( $source->matches_url( 'https://hachyderm.io/@example/123456789' ) );
		$this->assertTrue( $source->matches_url( 'https://fosstodon.org/@example/123456789' ) );
	}

	public function test_mastodon_suffix_heuristic_match(): void {
		$source = new Outpost_Source_Mastodon();
		// Suffix-heuristic instances (.social / .cloud / .online / .network).
		$this->assertTrue( $source->matches_url( 'https://example.social/@example/123456789' ) );
		$this->assertTrue( $source->matches_url( 'https://example.cloud/@example/123456789' ) );
	}

	public function test_mastodon_non_post_path_does_not_match(): void {
		$source = new Outpost_Source_Mastodon();
		// Profile pages (no numeric post id at the end) don't match.
		$this->assertFalse( $source->matches_url( 'https://mastodon.social/@example' ) );
		// Random hosts without @user pattern don't match.
		$this->assertFalse( $source->matches_url( 'https://example.com/users/example/posts/1' ) );
	}

	// --- Bluesky --------------------------------------------------------

	public function test_bluesky_capabilities(): void {
		$caps = ( new Outpost_Source_Bluesky() )->capabilities();
		$this->assertSame( 'bluesky', $caps['id'] );
		$this->assertSame( 'reply', $caps['mode'] );
		$this->assertSame( 'u-in-reply-to', $caps['h_entry_property'] );
	}

	public function test_bluesky_post_url_matches_homepage_does_not(): void {
		$source = new Outpost_Source_Bluesky();
		$this->assertTrue( $source->matches_url( 'https://bsky.app/profile/example.bsky.social/post/abcdef0123' ) );
		$this->assertFalse( $source->matches_url( 'https://bsky.app/' ) );
		$this->assertFalse( $source->matches_url( 'https://bsky.app/profile/example.bsky.social' ) );
	}
}
