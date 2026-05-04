<?php
/**
 * Integration test stub for the YouTube end-to-end inbound flow
 * (F15). Skipped until wp-env is wired up; documents the assertions
 * the future session will flesh out.
 *
 * Pipeline being verified:
 *
 *     POST /post/share-target { url=https://www.youtube.com/watch?v=... }
 *           ↓
 *     Outpost_Share_Target_Controller -> Outpost_Source_Detector
 *           ↓
 *     Source_Detector::find_source -> Outpost_Source_YouTube
 *           ↓
 *     Auto-route decision (unambiguous Watch)
 *           ↓
 *     303 Location: /post/?mode=watch&source=youtube&url=...&cached_for=...
 *           ↓
 *     Composer reads query params, fetches /preview?cached_for=...
 *           ↓
 *     /preview hits YouTube oEmbed via Extractor_Oembed
 *           ↓
 *     Composer pre-fill: p-name, u-photo, p-author, p-publication, u-watch-of
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class YouTubeEndToEndTest extends TestCase {

	/**
	 * @test
	 */
	public function watch_url_routes_to_watch_mode_with_youtube_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: ' .
			'log in as a test user; POST multipart to /post/share-target ' .
			'with url=https://www.youtube.com/watch?v=EXAMPLE. ' .
			'Assert response is 303 with Location header matching ' .
			'/post/?mode=watch&source=youtube&url=...&cached_for=... ' .
			'(NOT picker= because YouTube is unambiguous).'
		);
	}

	/**
	 * @test
	 */
	public function preview_endpoint_returns_mapped_h_entry_for_youtube_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: POST /wp-json/outpost/v1/preview ' .
			'{ url: "https://www.youtube.com/watch?v=EXAMPLE", source_id: "youtube" }. ' .
			'Mock the wp_safe_remote_get response with the F15 oembed-watch-success fixture. ' .
			'Assert response is { source_url, source_id: "youtube", extracted: ' .
			'{ p-name: ..., u-photo: ..., p-author: ..., p-publication: "YouTube", u-watch-of: ... }, ' .
			'raw: <full oembed shape>, warnings: [] }.'
		);
	}

	/**
	 * @test
	 */
	public function shorts_url_routes_to_watch_mode(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /post/share-target with ' .
			'url=https://www.youtube.com/shorts/SHORTS_EXAMPLE. ' .
			'Assert mode=watch (Shorts are videos, same composer ' .
			'as canonical /watch URLs).'
		);
	}

	/**
	 * @test
	 */
	public function youtu_be_short_link_dispatches_to_youtube_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with https://youtu.be/EXAMPLE. ' .
			'Assert source=youtube. Live-device verification (acceptance #9) ' .
			'covers the upstream redirect — YouTube oEmbed follows the ' .
			'youtu.be redirect to the canonical www.youtube.com/watch URL.'
		);
	}

	/**
	 * @test
	 */
	public function youtube_music_url_routes_to_watch_mode_not_listen(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with ' .
			'https://music.youtube.com/watch?v=MUSIC_EXAMPLE. Assert ' .
			'mode=watch (NOT listen). Per CLAUDE.md F15 #1 / Doc 2 §3.3: ' .
			'YouTube Music URLs are music videos, still videos. Outpost ' .
			'Listen mode is for audio-only platforms (Spotify, Last.fm).'
		);
	}

	/**
	 * @test
	 */
	public function mobile_youtube_url_dispatches_to_youtube_source(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with https://m.youtube.com/watch?v=EXAMPLE. ' .
			'Assert source=youtube — m.youtube.com is in host_patterns ' .
			'and the path /watch passes the matches_url constraint.'
		);
	}

	/**
	 * @test
	 */
	public function watch_url_with_timestamp_param_dispatches_correctly(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with ' .
			'https://www.youtube.com/watch?v=EXAMPLE&t=42s. Verify the ' .
			'preview endpoint passes the full URL (including t= param) ' .
			'to YouTube oEmbed — composer should pre-fill at the timestamp ' .
			'the user shared, since YouTube oEmbed honors the t param in ' .
			'the embed iframe url. Composer-side handling of the param ' .
			'into the rendered watch_of link is a follow-up.'
		);
	}

	/**
	 * @test
	 */
	public function channel_url_routes_to_unknown_not_youtube(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with ' .
			'https://www.youtube.com/channel/UCEXAMPLE. Assert source=unknown ' .
			'(picker mode), NOT youtube. Channel URLs are NOT claimed by ' .
			'Outpost_Source_YouTube — matches_url path constraint rejects ' .
			'them; Source_Unknown wildcard catches them.'
		);
	}

	/**
	 * @test
	 */
	public function handle_url_routes_to_unknown_not_youtube(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with https://www.youtube.com/@example-handle. ' .
			'Assert source=unknown (picker mode). Same rationale as channel URLs.'
		);
	}

	/**
	 * @test
	 */
	public function playlist_only_url_routes_to_unknown_not_youtube(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST with ' .
			'https://www.youtube.com/playlist?list=PLEXAMPLE. Assert ' .
			'source=unknown — pure playlist URL is a list, not a single ' .
			'watch event. Watch URLs with `&list=` ARE claimed because ' .
			'they have a v= parameter; pure /playlist?list= URLs are not.'
		);
	}

	/**
	 * @test
	 */
	public function oembed_404_response_returns_502_with_url_preserved(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /preview with valid YouTube URL ' .
			'but mock the oEmbed fetch to return HTTP 404 with the F15 ' .
			'oembed-404 fixture body. Assert preview returns WP_Error ' .
			'fetch_failed status 502. Composer falls back to manual entry; ' .
			'u-watch-of is set client-side from the URL even though the ' .
			'preview failed.'
		);
	}

	/**
	 * @test
	 */
	public function oembed_503_non_json_body_returns_502(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /preview with valid YouTube URL ' .
			'but mock the oEmbed fetch to return HTTP 503 with the F15 ' .
			'oembed-503 fixture (nginx HTML body). Assert preview returns ' .
			'WP_Error fetch_failed status 502 — Extractor_Oembed::parse ' .
			'rejects non-JSON bodies with RuntimeException; preview ' .
			'endpoint catches and translates to 502.'
		);
	}
}
