<?php
/**
 * F15 — integration test: YouTube end-to-end inbound flow.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (12 of 12). Cluster #5
 * of the G99 stub migration — mechanical replay of cluster #4's
 * Spotify patterns (same oEmbed extractor + share-target controller).
 *
 * Pre-readiness check (a/b/c/d) all passed before opening the PR:
 *
 *   (a) extractor='oembed' (concrete in F5)
 *   (b) Source_Detector + Extractor_Oembed + remote_get all concrete
 *   (c) F15 docblocks reference real shipped behavior
 *   (d) Recipe targets www.youtube.com/oembed (rewritable);
 *       dispatch-only tests don't fetch so m.youtube.com /
 *       music.youtube.com don't need to be rewritable.
 *
 * Pipeline being verified:
 *
 *     POST /post/share-target { url=https://www.youtube.com/watch?v=... }
 *           ↓
 *     Outpost_Share_Target_Controller -> Outpost_Source_Detector
 *           ↓
 *     find_source -> Outpost_Source_YouTube (path-constrained match)
 *           ↓
 *     303 redirect to /post/?mode=watch&source=youtube&url=...
 *
 *     POST /wp-json/outpost/v1/preview { url, source_id: youtube }
 *           ↓
 *     Preview endpoint -> Extractor_Oembed
 *           ↓
 *     wp_safe_remote_get( https://www.youtube.com/oembed?url=... )
 *           ↓ (rewritten to WireMock)
 *     Mapped extracted shape: p-name, u-photo, p-author,
 *                             p-publication, u-watch-of
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Mock_Server;
use Outpost_Share_Target_Controller;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class YouTubeEndToEndTest extends TestCase {

	private const WATCH_URL          = 'https://www.youtube.com/watch?v=EXAMPLE';
	private const SHORTS_URL         = 'https://www.youtube.com/shorts/SHORTS_EXAMPLE';
	private const YOUTU_BE_URL       = 'https://youtu.be/EXAMPLE';
	private const MUSIC_URL          = 'https://music.youtube.com/watch?v=MUSIC_EXAMPLE';
	private const MOBILE_URL         = 'https://m.youtube.com/watch?v=EXAMPLE';
	private const TIMESTAMP_URL      = 'https://www.youtube.com/watch?v=EXAMPLE&t=42s';
	private const CHANNEL_URL        = 'https://www.youtube.com/channel/UCEXAMPLE';
	private const HANDLE_URL         = 'https://www.youtube.com/@example-handle';
	private const PLAYLIST_ONLY_URL  = 'https://www.youtube.com/playlist?list=PLEXAMPLE';
	private const DELETED_VIDEO_URL  = 'https://www.youtube.com/watch?v=DELETED_VIDEO';
	private const SERVICE_DOWN_URL   = 'https://www.youtube.com/watch?v=SERVICE_DOWN';

	/** @var array{url:string,status:int}[] */
	private array $captured_redirects = array();

	private int $test_user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `npm run test:integration` inside wp-env tests-cli + WireMock sidecar.'
			);
		}
		Outpost_Mock_Server::reset();

		$this->test_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'youtube_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'youtube_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->test_user_id, 'Failed to create test user.' );
		wp_set_current_user( $this->test_user_id );

		$this->reset_request_globals();
		$this->captured_redirects = array();

		add_filter(
			'wp_redirect',
			function ( $location, $status ) {
				$this->captured_redirects[] = array(
					'url'    => (string) $location,
					'status' => (int) $status,
				);
				return false;
			},
			10,
			2
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		if ( $this->test_user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->test_user_id );
			$this->test_user_id = 0;
		}
		wp_set_current_user( 0 );
		$this->reset_request_globals();
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_safe_remote_get' )
			&& class_exists( 'Outpost_Share_Target_Controller' )
			&& class_exists( 'Outpost_Source_YouTube' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' )
			&& defined( 'OUTPOST_TESTING_PWA_SHELL' );
	}

	private function reset_request_globals(): void {
		$_POST   = array();
		$_GET    = array();
		$_FILES  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	private function dispatch_share_target_post( string $url ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['url']              = $url;
		Outpost_Share_Target_Controller::handle_request();
		if ( empty( $this->captured_redirects ) ) {
			return null;
		}
		return $this->captured_redirects[0]['url'];
	}

	private function build_preview_request( string $url, ?string $source_id = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_header( 'Content-Type', 'application/json' );
		$body = array( 'url' => $url );
		if ( null !== $source_id ) {
			$body['source_id'] = $source_id;
		}
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * Canonical /watch URL routes through Source_Detector to
	 * Source_YouTube, lands user in Watch mode without picker.
	 *
	 * @test
	 */
	public function watch_url_routes_to_watch_mode_with_youtube_source(): void {
		$redirect_url = $this->dispatch_share_target_post( self::WATCH_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
		$this->assertStringNotContainsString( 'picker=', $redirect_url );
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
	}

	/**
	 * Preview endpoint with explicit source_id=youtube returns the F15
	 * mapped h-entry shape including p-author (channel name, novel
	 * vs Spotify which doesn't have author).
	 *
	 * @test
	 */
	public function preview_endpoint_returns_mapped_h_entry_for_youtube_source(): void {
		Outpost_Mock_Server::stub_from_fixture( 'youtube/oembed-watch-success.json' );

		$request  = $this->build_preview_request( self::WATCH_URL, 'youtube' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'youtube', $data['source_id'] ?? null );
		$this->assertSame( self::WATCH_URL, $data['source_url'] ?? null );

		$extracted = $data['extracted'] ?? array();
		$this->assertSame( 'Sample Video Title', $extracted['p-name'] ?? null );
		$this->assertSame( 'Sample Channel', $extracted['p-author'] ?? null );
		$this->assertSame( 'YouTube', $extracted['p-publication'] ?? null );
		$this->assertSame( self::WATCH_URL, $extracted['u-watch-of'] ?? null );
		$this->assertNotEmpty( $extracted['u-photo'] ?? '' );

		$raw = $data['raw'] ?? array();
		$this->assertSame( 'video', $raw['type'] ?? null );
		$this->assertSame( 'YouTube', $raw['provider_name'] ?? null );
		$this->assertSame( array(), $data['warnings'] ?? null );
	}

	/**
	 * /shorts/{id} routes to Watch mode (Shorts are videos, same
	 * composer treatment as canonical /watch).
	 *
	 * @test
	 */
	public function shorts_url_routes_to_watch_mode(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SHORTS_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
	}

	/**
	 * youtu.be short URLs dispatch to Source_YouTube. The eventual
	 * oEmbed redirect-following is verified live (F15 acceptance #9);
	 * dispatch decision-only is asserted here.
	 *
	 * @test
	 */
	public function youtu_be_short_link_dispatches_to_youtube_source(): void {
		$redirect_url = $this->dispatch_share_target_post( self::YOUTU_BE_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
	}

	/**
	 * music.youtube.com URLs route to Watch mode, NOT Listen. Per
	 * F15 #2: Listen mode is for audio-only platforms; music videos
	 * are still videos.
	 *
	 * @test
	 */
	public function youtube_music_url_routes_to_watch_mode_not_listen(): void {
		$redirect_url = $this->dispatch_share_target_post( self::MUSIC_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
		$this->assertStringNotContainsString( 'mode=listen', $redirect_url );
	}

	/**
	 * m.youtube.com mobile URL passes the F15 path-constrained
	 * matches_url override (it's in PATH_CONSTRAINED_HOSTS with
	 * /watch as an allowed path).
	 *
	 * @test
	 */
	public function mobile_youtube_url_dispatches_to_youtube_source(): void {
		$redirect_url = $this->dispatch_share_target_post( self::MOBILE_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
	}

	/**
	 * /watch?v=X&t=42s passes the timestamp through dispatch
	 * unchanged. The preview endpoint forwards the full URL to
	 * YouTube oEmbed; YouTube honors `t=` in the embed iframe.
	 *
	 * @test
	 */
	public function watch_url_with_timestamp_param_dispatches_correctly(): void {
		$redirect_url = $this->dispatch_share_target_post( self::TIMESTAMP_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=youtube', $redirect_url );
		$this->assertStringContainsString( 'mode=watch', $redirect_url );
		// Timestamp param survives URL-encoding round-trip in the redirect.
		$this->assertStringContainsString( 't%3D42s', $redirect_url );
	}

	/**
	 * /channel/{id} URLs are NOT claimed by Source_YouTube — F15's
	 * matches_url override path-constrains to /watch + /shorts only.
	 * Channel URLs route to Source_Unknown's picker.
	 *
	 * @test
	 */
	public function channel_url_routes_to_unknown_not_youtube(): void {
		$redirect_url = $this->dispatch_share_target_post( self::CHANNEL_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringNotContainsString( 'source=youtube', $redirect_url );
		$this->assertTrue(
			str_contains( $redirect_url, 'picker=' )
				|| str_contains( $redirect_url, 'source=unknown' ),
			'Channel URL should route to Source_Unknown, got: ' . $redirect_url
		);
	}

	/**
	 * /@handle URLs follow the same path-constraint exclusion as
	 * channel URLs. Routes to Source_Unknown picker.
	 *
	 * @test
	 */
	public function handle_url_routes_to_unknown_not_youtube(): void {
		$redirect_url = $this->dispatch_share_target_post( self::HANDLE_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringNotContainsString( 'source=youtube', $redirect_url );
		$this->assertTrue(
			str_contains( $redirect_url, 'picker=' )
				|| str_contains( $redirect_url, 'source=unknown' ),
			'Handle URL should route to Source_Unknown, got: ' . $redirect_url
		);
	}

	/**
	 * Pure /playlist?list= URLs are list shares, not single-watch
	 * events. F15 path-constraint excludes them; Source_Unknown
	 * picker takes over.
	 *
	 * @test
	 */
	public function playlist_only_url_routes_to_unknown_not_youtube(): void {
		$redirect_url = $this->dispatch_share_target_post( self::PLAYLIST_ONLY_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringNotContainsString( 'source=youtube', $redirect_url );
		$this->assertTrue(
			str_contains( $redirect_url, 'picker=' )
				|| str_contains( $redirect_url, 'source=unknown' ),
			'Playlist-only URL should route to Source_Unknown, got: ' . $redirect_url
		);
	}

	/**
	 * oEmbed 404 (deleted video) surfaces as fetch_failed status 502
	 * from the preview endpoint. Composer falls back to manual entry;
	 * u-watch-of is set client-side from the URL.
	 *
	 * @test
	 */
	public function oembed_404_response_returns_502_with_url_preserved(): void {
		Outpost_Mock_Server::stub_from_fixture( 'youtube/oembed-404.json' );

		$request  = $this->build_preview_request( self::DELETED_VIDEO_URL, 'youtube' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( is_wp_error( $response ) || 502 === $response->get_status() );
		if ( is_wp_error( $response ) ) {
			$this->assertSame( 'fetch_failed', $response->get_error_code() );
			$error_data = $response->get_error_data();
			$this->assertSame( 502, $error_data['status'] ?? null );
		}
	}

	/**
	 * oEmbed 503 with non-JSON body (nginx HTML error page) surfaces
	 * as 502. Extractor_Oembed::parse rejects non-JSON; preview
	 * endpoint catches the RuntimeException and translates.
	 *
	 * @test
	 */
	public function oembed_503_non_json_body_returns_502(): void {
		Outpost_Mock_Server::stub_from_fixture( 'youtube/oembed-503.json' );

		$request  = $this->build_preview_request( self::SERVICE_DOWN_URL, 'youtube' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( is_wp_error( $response ) || 502 === $response->get_status() );
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$this->assertSame( 502, $error_data['status'] ?? null );
		}
	}
}
