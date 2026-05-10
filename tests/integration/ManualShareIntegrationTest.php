<?php
/**
 * F9 — integration test: Outpost_Manual_Share_Controller end-to-end.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (9 of 9). Phase 3
 * cluster #4 of the overnight queue.
 *
 * Pre-readiness check (a/b/c/d):
 *   (a) N/A — controller-level test
 *   (b) F9 controller + 10 default platform configs + chips_for_mode
 *       all concrete and shipped
 *   (c) Docblocks reference real F9 shipped behavior
 *   (d) No fetches; pure REST endpoint dispatch
 *
 * Three structural constraints honored:
 *
 *   Rule 2 (auth-gate absence-of-side-effects) — test 3 (401)
 *   uses get_status check (no transient seeds in this controller's
 *   intent path; F10 stub returns status='stub' string only).
 *
 *   Rule 3 (custom registrations must not persist) — tests 8 + 9
 *   hook filters in setUp/test-body and remove in finally blocks
 *   so filter callbacks don't leak across tests.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Manual_Share_Platform_Registry;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class ManualShareIntegrationTest extends TestCase {

	private int $test_user_id = 0;
	private int $test_post_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->test_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'manualshare_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'manualshare_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->test_user_id );

		$this->test_post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Manual share test post',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => $this->test_user_id,
			),
			true
		);

		// Fix 3: clear Platform_Registry's static $resolved cache so any
		// per-test `outpost_manual_share_platforms` filter actually fires.
		// Without this reset, the first call to `all_platforms()` in any
		// test populates the cache; subsequent tests' filter registrations
		// see the cached value and never re-evaluate.
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
	}

	protected function tearDown(): void {
		// Fix 3: clear Platform_Registry cache so any filter registrations
		// in this test don't leak into the next test class.
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();

		if ( $this->test_post_id > 0 ) {
			wp_delete_post( $this->test_post_id, true );
		}
		if ( $this->test_user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->test_user_id );
			$this->test_user_id = 0;
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Manual_Share_Controller' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	private function intent_request( int $post_id, string $platform_id ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/outpost/v1/manual-share/intent' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'post_id'     => $post_id,
					'platform_id' => $platform_id,
				)
			)
		);
		return $req;
	}

	/**
	 * Test 1: known platform → stub response per F9 contract.
	 *
	 * @test
	 */
	public function intent_endpoint_returns_stub_response_for_known_platform(): void {
		$response = rest_get_server()->dispatch( $this->intent_request( $this->test_post_id, 'instagram-feed' ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'stub', $data['status'] ?? null );
		$this->assertSame( 'instagram-feed', $data['platform_id'] ?? null );
		$this->assertSame( $this->test_post_id, $data['post_id'] ?? null );
		// Fix 1: F10 has shipped — desktop platform path now returns a
		// "not yet implemented for this platform (desktop)" message
		// (see Outpost_Manual_Share_Intent_Payload_Builder::build_stub_response).
		// Original assertion referenced "F10" in the message but that
		// reference is gone post-F10-ship. Match the current SUT shape.
		$this->assertStringContainsString(
			'not yet implemented',
			$data['message'] ?? '',
			'Desktop stub response should describe the unimplemented state.'
		);
		$this->assertStringContainsString(
			'instagram-feed',
			$data['message'] ?? '',
			'Stub response message should reference the requested platform_id.'
		);
	}

	/**
	 * Test 2: unknown platform → 400 with known_ids list.
	 *
	 * @test
	 */
	public function intent_endpoint_rejects_unknown_platform_with_400(): void {
		$response = rest_get_server()->dispatch( $this->intent_request( $this->test_post_id, 'totally-fake' ) );

		$this->assertTrue( is_wp_error( $response ) || 400 === $response->get_status() );
		if ( is_wp_error( $response ) ) {
			$this->assertSame( 'unknown_platform_id', $response->get_error_code() );
			$error_data = $response->get_error_data();
			$this->assertIsArray( $error_data['known_ids'] ?? null );
			$this->assertGreaterThanOrEqual(
				10,
				count( $error_data['known_ids'] ),
				'known_ids list must contain all 10 default platforms.'
			);
		}
	}

	/**
	 * Test 3: unauthenticated POST → 401 rest_forbidden.
	 *
	 * Per Rule 2 (auth-gate): this controller's intent endpoint has
	 * no synchronous side effects beyond the audit-log entry creation.
	 * The 401 path returns BEFORE handle_request runs, so the
	 * meaningful absence assertion is the response status itself.
	 *
	 * @test
	 */
	public function intent_endpoint_rejects_unauthenticated_request(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( $this->intent_request( $this->test_post_id, 'instagram-feed' ) );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Auth gate must reject unauthenticated POSTs.'
		);
	}

	/**
	 * Test 4: chips endpoint for mode=photo returns 10 default platforms.
	 *
	 * @test
	 */
	public function chips_endpoint_for_photo_returns_10_default_platforms(): void {
		$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
		$req->set_param( 'mode', 'photo' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['chips'] ?? null );
		$this->assertCount( 10, $data['chips'] );

		foreach ( $data['chips'] as $chip ) {
			$this->assertArrayHasKey( 'id', $chip );
			$this->assertArrayHasKey( 'label', $chip );
			$this->assertTrue( $chip['detected'] ?? false );
			$this->assertArrayHasKey( 'manual_share', $chip );
			$this->assertArrayHasKey( 'icon', $chip['manual_share'] );
		}
	}

	/**
	 * Test 5: chips endpoint for mode=listen returns empty (none of
	 * the 10 default platforms accept Listen).
	 *
	 * @test
	 */
	public function chips_endpoint_for_listen_returns_empty_array(): void {
		$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
		$req->set_param( 'mode', 'listen' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array(), $data['chips'] ?? null );
	}

	/**
	 * Test 6: unknown mode fails open to all chips.
	 *
	 * @test
	 */
	public function chips_endpoint_unknown_mode_fails_open_to_all_chips(): void {
		$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
		$req->set_param( 'mode', 'totally-fake' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['mode_recognized'] ?? true );
		// Fix 2: `assertNull( $x ?? 'set' )` is structurally broken —
		// `null ?? 'set'` returns 'set' (PHP null-coalescing semantics),
		// so the assertion can never pass regardless of $data['mode_applied'].
		// Split into key-existence + null-value checks; both must hold.
		$this->assertArrayHasKey( 'mode_applied', $data );
		$this->assertNull( $data['mode_applied'] );
		$this->assertGreaterThanOrEqual( 10, count( $data['chips'] ?? array() ) );
	}

	/**
	 * Test 7: chips endpoint server-filters to manual_share entries —
	 * AP chip never appears in the response. Holds whether the AP
	 * plugin is loaded or not (manual_share key gates the inclusion).
	 *
	 * @test
	 */
	public function chips_endpoint_does_not_include_activitypub_chip(): void {
		$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
		$req->set_param( 'mode', 'photo' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = array_column( $data['chips'] ?? array(), 'id' );
		$this->assertNotContains(
			'activitypub',
			$ids,
			'manual-share-chips endpoint must server-filter to manual_share entries.'
		);
	}

	/**
	 * Test 8: bridgy defer filter hides reddit-manual + flickr-manual.
	 *
	 * Per Rule 3: filter hook scoped to this test method via
	 * try/finally; defer filter MUST NOT persist into other tests.
	 *
	 * @test
	 */
	public function chips_endpoint_hides_reddit_flickr_when_bridgy_filter_returns_true(): void {
		$defer_filter = static fn (): bool => true;
		add_filter( 'outpost_manual_share_defer_to_bridgy', $defer_filter );

		try {
			$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
			$req->set_param( 'mode', 'photo' );
			$response = rest_get_server()->dispatch( $req );
			$this->assertSame( 200, $response->get_status() );

			$data = $response->get_data();
			$ids  = array_column( $data['chips'] ?? array(), 'id' );
			$this->assertNotContains( 'reddit-manual', $ids, 'reddit-manual must drop when bridgy defer filter is true.' );
			$this->assertNotContains( 'flickr-manual', $ids, 'flickr-manual must drop when bridgy defer filter is true.' );
			$this->assertCount( 8, $data['chips'], 'Should be 10 - 2 = 8 chips when bridgy defer is on.' );
		} finally {
			remove_filter( 'outpost_manual_share_defer_to_bridgy', $defer_filter );
		}
	}

	/**
	 * Test 9: platforms filter can register a custom platform.
	 *
	 * Per Rule 3: filter hook scoped to test method via try/finally.
	 *
	 * @test
	 */
	public function platforms_filter_can_register_custom_platform_for_chip_listing(): void {
		// Fix 3.5: mock platform config must use schema-valid enum values.
		// Original mock had `caption_via => 'manual'` and `after_share =>
		// 'capture-prompt'`; Platform_Config validates the two enum-
		// constrained required fields strictly:
		//   VALID_CAPTION_VIA  = [intent, clipboard, web_intent]
		//   VALID_AFTER_SHARE  = [mark_done, prompt_for_silo_url, silent]
		// Fix 3 (Platform_Registry cache reset) made the filter fire and
		// the validation run for the first time, exposing the bad mock.
		// Schema audit confirmed these are the ONLY two enum-constrained
		// required fields — the remaining 4 required fields (id, label,
		// icon, accepts_modes) accept any non-empty string / non-empty
		// array, and the 10 optional fields silently default when absent
		// or wrong-typed. After this fix no more invalid values can hide.
		// Chose `clipboard` because VSCO is a photo-share app where a
		// manual-paste caption flow matches 4 of the 10 default platforms
		// (instagram-feed, instagram-stories, threads, tiktok all use
		// `clipboard`). Chose `prompt_for_silo_url` for after_share to
		// match the test's existing intent (capture silo URL after share).
		$platforms_filter = static function ( array $platforms ): array {
			$platforms[] = array(
				'id'             => 'custom-vsco',
				'label'          => 'VSCO (custom)',
				'icon'           => 'vsco',
				'accepts_modes'  => array( 'photo' ),
				'caption_via'    => 'clipboard',
				'after_share'    => 'prompt_for_silo_url',
			);
			return $platforms;
		};
		add_filter( 'outpost_manual_share_platforms', $platforms_filter );

		try {
			$req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share-chips' );
			$req->set_param( 'mode', 'photo' );
			$response = rest_get_server()->dispatch( $req );
			$this->assertSame( 200, $response->get_status() );

			$data = $response->get_data();
			$ids  = array_column( $data['chips'] ?? array(), 'id' );
			$this->assertContains( 'custom-vsco', $ids, 'Custom platform must surface via the platforms filter.' );
		} finally {
			remove_filter( 'outpost_manual_share_platforms', $platforms_filter );
		}
	}
}
