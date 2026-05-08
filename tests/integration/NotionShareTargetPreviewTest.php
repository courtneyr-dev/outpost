<?php
/**
 * G8b — integration test: Notion API in share-target preview.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (3 of 6 tests). Third
 * per-cluster migration after G4b Og_Inbound (PR #65) and G4b
 * Composite_Inbound (PRs #66/#67). First cluster to exercise:
 *
 *   - Outpost_Mock_Server_Filter rewriting (api.notion.com → WireMock)
 *   - Outpost_Credentials_Store-backed OAuth token mock
 *   - REST endpoint dispatch via rest_get_server() with cookie auth
 *   - Real WP user creation + edit_posts capability gate
 *
 * 4th gotcha discovered (filed as follow-up):
 *
 *   REWRITABLE_HOSTS in Outpost_Mock_Server_Filter only includes
 *   upstream API hosts (api.notion.com), not the user-shared canonical
 *   URL hosts (notion.so / www.notion.so / *.notion.site). The
 *   `auth_required` source's fallback-to-extractor path (disconnected
 *   user → og:title scrape on www.notion.so) cannot migrate end-to-end
 *   without either expanding REWRITABLE_HOSTS (production change) or
 *   adding a per-test http_request_host_is_external escape hatch with
 *   matching WireMock stubs at the canonical-URL path.
 *
 *   Affected stubs (still skipped with explicit reasoning below):
 *     - disconnected_user_falls_through_to_og_title
 *     - anonymous_request_falls_through_to_og_title
 *     - notion_transport_failure_falls_through
 *
 *   The pattern surfaces in any future cluster whose source declares
 *   auth_required=true AND has a fallback-to-public-extractor branch.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Mock_Server;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class NotionShareTargetPreviewTest extends TestCase {

	private const NOTION_PAGE_ID  = 'aaaaaaaa11112222333344445555aaaa';
	private const NOTION_PAGE_URL = 'https://www.notion.so/Workspace-aaaaaaaa11112222333344445555aaaa';

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

		// Wipe Notion page-fetch transient between tests so cache state
		// doesn't bleed across cases. Keyed on the dashless page ID.
		delete_transient( 'outpost_notion_page_' . self::NOTION_PAGE_ID );

		// Real WP user with edit_posts capability — REST permission_callback
		// gates on this. Direct wp_insert_user keeps tests independent of
		// WP_UnitTestCase factory inheritance (matches PR #65/#66 pattern
		// of extending plain TestCase).
		$this->test_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'g8b_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'g8b_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->test_user_id, 'Failed to create test user.' );
		wp_set_current_user( $this->test_user_id );
	}

	protected function tearDown(): void {
		if ( $this->test_user_id > 0 ) {
			Outpost_Credentials_Store::delete( 'notion', $this->test_user_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->test_user_id );
			$this->test_user_id = 0;
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_safe_remote_get' )
			&& class_exists( 'Outpost_Source_Notion' )
			&& class_exists( 'Outpost_Credentials_Store' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * Build a WP_REST_Request matching what the PWA composer sends to
	 * /wp-json/outpost/v1/preview. The body carries the shared URL;
	 * cookie auth flows from wp_set_current_user() in setUp().
	 */
	private function build_preview_request( string $url ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'url' => $url ) ) );
		return $request;
	}

	/**
	 * Connected user shares a Notion URL; integration has access.
	 * Asserts the structured authenticated_source='notion' shape with
	 * page title, icon, and block-count from the WireMock-backed API.
	 *
	 * @test
	 */
	public function connected_user_gets_structured_notion_preview(): void {
		Outpost_Mock_Server::stub_from_fixture( 'notion/page-success-full.json' );
		Outpost_Mock_Server::stub_from_fixture( 'notion/blocks-success.json' );

		$persisted = Outpost_Credentials_Store::set(
			'notion',
			array( 'access_token' => 'mock-access-token-g8b' ),
			$this->test_user_id
		);
		$this->assertTrue( $persisted, 'Failed to persist mock Notion credentials.' );

		$request  = $this->build_preview_request( self::NOTION_PAGE_URL );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'notion', $data['authenticated_source'] ?? null );
		$this->assertSame( 'ok', $data['authenticated_status'] ?? null );

		$extracted = $data['extracted'] ?? array();
		$this->assertIsArray( $extracted );
		$this->assertSame( 'Sample Notion Page', $extracted['p-name'] );
		$this->assertSame( '📝', $extracted['notion-icon'] );
		$this->assertGreaterThanOrEqual( 1, (int) ( $extracted['notion-block-count'] ?? 0 ) );
	}

	/**
	 * Connected user shares a Notion page the integration hasn't been
	 * granted access to. Notion API returns 404; the endpoint responds
	 * with HTTP 200 + authenticated_status='page_not_shared' so the
	 * composer can render a hint instead of a hard error.
	 *
	 * @test
	 */
	public function connected_user_unshared_page_surfaces_notice_and_falls_through(): void {
		Outpost_Mock_Server::stub_from_fixture( 'notion/page-not-found.json' );

		$persisted = Outpost_Credentials_Store::set(
			'notion',
			array( 'access_token' => 'mock-access-token-g8b' ),
			$this->test_user_id
		);
		$this->assertTrue( $persisted );

		$request  = $this->build_preview_request( self::NOTION_PAGE_URL );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'page_not_shared', $data['authenticated_status'] ?? null );
		$this->assertNotEmpty( $data['authenticated_message'] ?? '' );

		$warnings = $data['warnings'] ?? array();
		$this->assertIsArray( $warnings );
		$this->assertNotEmpty( $warnings );
		$this->assertSame( 'outpost_notion_page_not_shared', $warnings[0]['code'] ?? null );
	}

	/**
	 * Non-Notion URL (Spotify) shared while the user happens to have
	 * Notion credentials persisted. The preview endpoint must dispatch
	 * to the Spotify source path, NOT the Notion authenticated branch.
	 * Asserts source_id='spotify' and absent authenticated_* fields.
	 *
	 * @test
	 */
	public function non_notion_url_routes_unchanged(): void {
		Outpost_Mock_Server::stub_from_fixture( 'notion/spotify-oembed-track.json' );

		// Notion creds exist but should be ignored — Spotify is unauthenticated.
		Outpost_Credentials_Store::set(
			'notion',
			array( 'access_token' => 'mock-access-token-g8b' ),
			$this->test_user_id
		);

		$spotify_url = 'https://open.spotify.com/track/0000000000000000000000';
		$request     = $this->build_preview_request( $spotify_url );
		$response    = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'spotify', $data['source_id'] ?? null );
		$this->assertArrayNotHasKey( 'authenticated_source', $data );
		$this->assertArrayNotHasKey( 'authenticated_status', $data );

		$extracted = $data['extracted'] ?? array();
		$this->assertIsArray( $extracted );
		$this->assertSame( 'Sample Track', $extracted['p-name'] ?? null );
	}

	/**
	 * SKIPPED — cluster #3 4th gotcha. Disconnected user falls through
	 * to og_tags extractor which fetches www.notion.so directly. That
	 * host is NOT in REWRITABLE_HOSTS, so the request escapes the
	 * mock-server and hits the real internet. Migration requires
	 * either expanding REWRITABLE_HOSTS or adding a per-test
	 * http_request_host_is_external escape hatch with matching
	 * WireMock stubs at the canonical-URL path.
	 *
	 * @test
	 */
	public function disconnected_user_falls_through_to_og_title(): void {
		$this->markTestSkipped(
			'Cluster #3 gotcha #4: REWRITABLE_HOSTS does not include www.notion.so. '
			. 'Disconnected fallback-to-og_tags path needs rewriter expansion or '
			. 'a test-time host_is_external escape hatch. Filed as follow-up.'
		);
	}

	/**
	 * SKIPPED — same gotcha #4. Anonymous request also falls through
	 * to the og_tags extractor on www.notion.so.
	 *
	 * @test
	 */
	public function anonymous_request_falls_through_to_og_title(): void {
		$this->markTestSkipped(
			'Cluster #3 gotcha #4: REWRITABLE_HOSTS does not include www.notion.so. '
			. 'Anonymous fallback path shares the disconnected-user blocker.'
		);
	}

	/**
	 * SKIPPED — same gotcha #4. Notion transport failure (api.notion.com
	 * returns 5xx) is rewritable, but the resulting fall-through hits
	 * the og_tags extractor at www.notion.so — which is NOT.
	 *
	 * @test
	 */
	public function notion_transport_failure_falls_through(): void {
		$this->markTestSkipped(
			'Cluster #3 gotcha #4: api.notion.com 5xx falls through to og_tags '
			. 'extractor on www.notion.so, which is not rewritable. Same blocker '
			. 'as the disconnected-user case.'
		);
	}
}
