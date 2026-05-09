<?php
/**
 * F7 — integration test: Spotify end-to-end inbound flow.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (9 of 9 tests). Cluster
 * #4 of the G99 stub migration. First cluster to exercise:
 *
 *   - Outpost_Share_Target_Controller (POST /post/share-target)
 *   - wp_redirect filter capture as the assertion seam
 *   - Source_Detector dispatch end-to-end with a concrete source
 *   - oEmbed extractor fetch through the mock-server filter
 *
 * Pipeline being verified (per F7 / F6 docs):
 *
 *     POST /post/share-target { url=https://open.spotify.com/track/... }
 *           ↓
 *     Outpost_Share_Target_Controller -> Outpost_Source_Detector::dispatch
 *           ↓
 *     Source_Detector::find_source -> Outpost_Source_Spotify (unambiguous)
 *           ↓
 *     303 redirect to /post/?mode=listen&source=spotify&url=...&cached_for=...
 *
 *     POST /wp-json/outpost/v1/preview { url, source_id: spotify }
 *           ↓
 *     Preview endpoint -> handle_via_source -> Extractor_Oembed
 *           ↓
 *     wp_safe_remote_get( https://open.spotify.com/oembed?url=... )
 *           ↓ (rewritten to WireMock by Outpost_Mock_Server_Filter)
 *     Mapped extracted shape: p-name, u-photo, p-publication, u-listen-of
 *
 * Passed pre-migration readiness check (a/b/c/d):
 *
 *   (a) Spotify declares extractor='oembed' — concrete in F5.
 *   (b) Dispatch + extractor + remote_get all reach concrete code.
 *   (c) Test docblocks reference real F7-shipped behavior, not
 *       speculative future shape.
 *   (d) open.spotify.com IS in REWRITABLE_HOSTS.
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
final class SpotifyEndToEndTest extends TestCase {

	private const SPOTIFY_TRACK_URL    = 'https://open.spotify.com/track/0000000000000000000000';
	private const SPOTIFY_ALBUM_URL    = 'https://open.spotify.com/album/1111111111111111111111';
	private const SPOTIFY_EPISODE_URL  = 'https://open.spotify.com/episode/2222222222222222222222';
	private const SPOTIFY_INTL_URL     = 'https://open.spotify.com/intl-de/track/3333333333333333333333';
	private const SPOTIFY_LINK_URL     = 'https://spotify.link/abc123def456';
	private const SPOTIFY_ARTIST_URL   = 'https://open.spotify.com/artist/4444444444444444444444';
	private const SPOTIFY_404_URL      = 'https://open.spotify.com/track/9999999999999999999999';
	private const NON_SPOTIFY_URL      = 'https://example.com/article';

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
				'user_login' => 'spotify_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'spotify_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->test_user_id, 'Failed to create test user.' );
		wp_set_current_user( $this->test_user_id );

		$this->reset_request_globals();
		$this->captured_redirects = array();

		// Capture redirect URLs via the controller's
		// `set_redirect_callback_for_tests` seam. The previous
		// `add_filter('wp_redirect', ...)` pattern was a no-op under
		// `OUTPOST_TESTING_PWA_SHELL` because `wp_safe_redirect()` is
		// skipped — see `docs/dev/integration-test-gotchas.md` § gotcha #10
		// and `integration_suite_was_always_red_lesson.md` for the
		// 2026-05-09 review-theater discovery.
		Outpost_Share_Target_Controller::set_redirect_callback_for_tests(
			function ( $location, $status ) {
				$this->captured_redirects[] = array(
					'url'    => (string) $location,
					'status' => (int) $status,
				);
			}
		);
	}

	protected function tearDown(): void {
		Outpost_Share_Target_Controller::set_redirect_callback_for_tests( null );
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
			&& class_exists( 'Outpost_Source_Spotify' )
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

	/**
	 * Drive the share-target controller as if a Web Share Target Level 2
	 * POST arrived. Returns the captured redirect URL or null when no
	 * redirect was issued (e.g. share-text-only fallback ran or auth
	 * failed).
	 */
	private function dispatch_share_target_post( string $url ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['url']              = $url;
		Outpost_Share_Target_Controller::handle_request();
		if ( empty( $this->captured_redirects ) ) {
			return null;
		}
		return $this->captured_redirects[0]['url'];
	}

	/**
	 * Build a preview-endpoint request as the test user. Cookie auth
	 * flows from wp_set_current_user() in setUp().
	 */
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
	 * Track URL routes through Source_Detector to Source_Spotify, lands
	 * the user in Listen mode without a picker.
	 *
	 * @test
	 */
	public function track_url_routes_to_listen_mode_with_spotify_source(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_TRACK_URL );

		$this->assertNotNull( $redirect_url, 'Share-target should issue a 303 redirect.' );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
		$this->assertStringNotContainsString( 'picker=', $redirect_url );
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
	}

	/**
	 * Preview endpoint with explicit source_id=spotify dispatches through
	 * the oEmbed extractor and returns the F7 mapped h-entry shape.
	 *
	 * @test
	 */
	public function preview_endpoint_returns_mapped_h_entry_for_spotify_source(): void {
		Outpost_Mock_Server::stub_from_fixture( 'spotify/oembed-track-success.json' );

		$request  = $this->build_preview_request( self::SPOTIFY_TRACK_URL, 'spotify' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'spotify', $data['source_id'] ?? null );
		$this->assertSame( self::SPOTIFY_TRACK_URL, $data['source_url'] ?? null );

		$extracted = $data['extracted'] ?? array();
		$this->assertSame( 'Sample Track Title', $extracted['p-name'] ?? null );
		$this->assertSame( 'Spotify', $extracted['p-publication'] ?? null );
		$this->assertSame( self::SPOTIFY_TRACK_URL, $extracted['u-listen-of'] ?? null );
		$this->assertNotEmpty( $extracted['u-photo'] ?? '' );

		$raw = $data['raw'] ?? array();
		$this->assertSame( 'rich', $raw['type'] ?? null );
		$this->assertSame( 'Spotify', $raw['provider_name'] ?? null );
		$this->assertSame( array(), $data['warnings'] ?? null );
	}

	/**
	 * Album path matches host-only host_patterns the same way track does.
	 *
	 * @test
	 */
	public function album_url_also_routes_to_listen_mode(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_ALBUM_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
	}

	/**
	 * Podcast episode URLs route to Listen mode same as tracks (Spotify
	 * treats episodes and tracks as the same kind of consumption from
	 * the share-target's point of view).
	 *
	 * @test
	 */
	public function podcast_episode_url_routes_to_listen_mode(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_EPISODE_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
	}

	/**
	 * intl-{lang} regional URL variant matches Spotify's host-only
	 * pattern. The regional segment is in the path so doesn't affect
	 * host_patterns matching.
	 *
	 * @test
	 */
	public function intl_regional_url_matches_via_host_only_pattern(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_INTL_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
	}

	/**
	 * spotify.link short URLs dispatch to Source_Spotify because
	 * spotify.link is in the source's host_patterns. End-to-end oEmbed
	 * resolution (which would follow the redirect to open.spotify.com)
	 * is verified live per F7 acceptance #9; this test asserts the
	 * dispatch decision only.
	 *
	 * @test
	 */
	public function spotify_link_short_url_dispatches_to_spotify_source(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_LINK_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
	}

	/**
	 * /artist/ URLs match Spotify today because Source_Base patterns are
	 * host-only — no path constraint distinguishes /artist/ from /track/.
	 * User can switch modes from the Listen composer if they actually
	 * wanted Bookmark; F7 docblock notes the recipe_for_url override
	 * follow-up.
	 *
	 * @test
	 */
	public function artist_url_also_matches_spotify_pattern_today(): void {
		$redirect_url = $this->dispatch_share_target_post( self::SPOTIFY_ARTIST_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringContainsString( 'source=spotify', $redirect_url );
		$this->assertStringContainsString( 'mode=listen', $redirect_url );
	}

	/**
	 * oEmbed 404 surfaces as fetch_failed status 502 from the preview
	 * endpoint. Composer falls back to manual entry; u-listen-of is set
	 * client-side from the URL even though the preview failed.
	 *
	 * @test
	 */
	public function oembed_404_response_returns_502_with_url_preserved(): void {
		Outpost_Mock_Server::stub_from_fixture( 'spotify/oembed-404.json' );

		$request  = $this->build_preview_request( self::SPOTIFY_404_URL, 'spotify' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( is_wp_error( $response ) || 502 === $response->get_status() );
		if ( is_wp_error( $response ) ) {
			$this->assertSame( 'fetch_failed', $response->get_error_code() );
			$error_data = $response->get_error_data();
			$this->assertSame( 502, $error_data['status'] ?? null );
		}
	}

	/**
	 * Non-Spotify URL falls through to Source_Unknown's picker route.
	 * The Detector's find_source walks registered sources in
	 * registration order; Source_Unknown is the trailing fallback.
	 *
	 * @test
	 */
	public function non_spotify_url_does_not_route_through_spotify(): void {
		$redirect_url = $this->dispatch_share_target_post( self::NON_SPOTIFY_URL );

		$this->assertNotNull( $redirect_url );
		$this->assertStringNotContainsString( 'source=spotify', $redirect_url );
		// Source_Unknown's ambiguity is 'ambiguous' → picker route.
		$this->assertTrue(
			str_contains( $redirect_url, 'picker=' )
				|| str_contains( $redirect_url, 'source=unknown' ),
			'Non-Spotify URL should route to picker or Source_Unknown, got: ' . $redirect_url
		);
	}
}
