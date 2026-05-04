<?php
/**
 * Integration test stub for the Spotify end-to-end inbound flow
 * (F7). Skipped until wp-env is wired up; documents the assertions
 * the future session will flesh out.
 *
 * Pipeline being verified:
 *
 *     POST /post/share-target { url=https://open.spotify.com/track/... }
 *           ↓
 *     Outpost_Share_Target_Controller -> Outpost_Source_Detector
 *           ↓
 *     Source_Detector::find_source -> Outpost_Source_Spotify
 *           ↓
 *     Auto-route decision (unambiguous Listen)
 *           ↓
 *     303 Location: /post/?mode=listen&source=spotify&url=...&cached_for=...
 *           ↓
 *     Composer reads query params, fetches /preview?cached_for=...
 *           ↓
 *     /preview hits Spotify oEmbed via Extractor_Oembed
 *           ↓
 *     Composer pre-fill: p-name, u-photo, p-publication, u-listen-of
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SpotifyEndToEndTest extends TestCase {

	/**
	 * @test
	 */
	public function track_url_routes_to_listen_mode_with_spotify_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: ' .
			'log in as a test user; POST multipart to /post/share-target ' .
			'with url=https://open.spotify.com/track/0000000000000000000000. ' .
			'Assert response is 303 with Location header matching ' .
			'/post/?mode=listen&source=spotify&url=...&cached_for=... ' .
			'(NOT picker= because Spotify is unambiguous).'
		);
	}

	/**
	 * @test
	 */
	public function preview_endpoint_returns_mapped_h_entry_for_spotify_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: POST /wp-json/outpost/v1/preview ' .
			'{ url: "https://open.spotify.com/track/...", source_id: "spotify" }. ' .
			'Mock the wp_safe_remote_get response with the F7 success fixture. ' .
			'Assert response is { source_url, source_id: "spotify", extracted: ' .
			'{ p-name: ..., u-photo: ..., p-publication: "Spotify", u-listen-of: ... }, ' .
			'raw: <full oembed shape>, warnings: [] }.'
		);
	}

	/**
	 * @test
	 */
	public function album_url_also_routes_to_listen_mode(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Same as track URL but with /album/ path; ' .
			'verifies host_patterns is path-agnostic.'
		);
	}

	/**
	 * @test
	 */
	public function podcast_episode_url_routes_to_listen_mode(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /post/share-target with ' .
			'/episode/ URL. Assert mode=listen (Spotify treats podcast ' .
			'episodes as listens, same as music tracks).'
		);
	}

	/**
	 * @test
	 */
	public function intl_regional_url_matches_via_host_only_pattern(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with ' .
			'https://open.spotify.com/intl-de/track/... Assert dispatch ' .
			'matches Spotify (host_patterns is host-only; intl-* is in ' .
			'the path which doesn\'t affect matching).'
		);
	}

	/**
	 * @test
	 */
	public function spotify_link_short_url_dispatches_to_spotify_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with https://spotify.link/abc123. ' .
			'Assert source=spotify because spotify.link is in host_patterns. ' .
			'oEmbed should follow the redirect to the canonical open.spotify.com ' .
			'URL — verify in live device test (acceptance #9).'
		);
	}

	/**
	 * @test
	 */
	public function artist_url_also_matches_spotify_pattern_today(): void {
		$this->markTestSkipped(
			'wp-env setup pending. /artist/ URLs match host_patterns ' .
			'open.spotify.com because Source_Base patterns are host-only ' .
			'(no path constraint). User can switch modes from the Listen ' .
			'composer if they actually wanted Bookmark. Future UX research ' .
			'may justify a recipe_for_url override that returns a different ' .
			'mode for /artist/ paths — F7 docblock notes this as a follow-up.'
		);
	}

	/**
	 * @test
	 */
	public function oembed_404_response_returns_502_with_url_preserved(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /preview with valid Spotify URL ' .
			'but mock the oEmbed fetch to return HTTP 404. Assert preview ' .
			'returns WP_Error fetch_failed status 502. Composer falls ' .
			'back to manual entry; u-listen-of is set client-side from ' .
			'the URL even though the preview failed.'
		);
	}

	/**
	 * @test
	 */
	public function non_spotify_url_does_not_route_through_spotify(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /post/share-target with ' .
			'url=https://example.com/article. Assert dispatch routes to ' .
			'Source_Unknown (picker), NOT Spotify.'
		);
	}
}
