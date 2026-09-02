<?php
/**
 * F12 — integration test: phase-2 silo URL capture flow.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (6 of 6). Phase 3
 * cluster #3 of the overnight queue.
 *
 * Pre-readiness check (a/b/c/d) all passed:
 *
 *   (a) N/A — controller-level + admin notice tests
 *   (b) F12 controllers concrete; REST endpoints use get_param;
 *       Pending_Capture_Detector has set_candidate_resolver_for_tests
 *       seam so tests don't need to wait past the 30-second grace
 *   (c) Docblocks reference real F12 shipped behavior
 *   (d) No fetches; all assertions on post-meta / rendered HTML
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Manual_Share_Audit_Log;
use Outpost_Manual_Share_Pending_Capture_Detector;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class SyndicationCaptureFlowTest extends TestCase {

	private int $test_user_id    = 0;
	private int $other_user_id   = 0;
	private int $test_post_id    = 0;

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
				'user_login' => 'capture_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'capture_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->other_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'capture_other_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'capture_other_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->test_user_id );

		$this->test_post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Photo post for capture test',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => $this->test_user_id,
				'post_content' => '<p>Body content.</p>',
			),
			true
		);
	}

	protected function tearDown(): void {
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );

		if ( $this->test_post_id > 0 ) {
			wp_delete_post( $this->test_post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( $this->test_user_id > 0 ) {
			wp_delete_user( $this->test_user_id );
		}
		if ( $this->other_user_id > 0 ) {
			wp_delete_user( $this->other_user_id );
		}
		$this->test_user_id = $this->other_user_id = $this->test_post_id = 0;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Manual_Share_Audit_Log' )
			&& class_exists( 'Outpost_Manual_Share_Pending_Capture_Detector' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * Add an audit-log entry directly + roll its `fired_at` past the
	 * 30-second grace period so the detector picks it up without
	 * waiting in real time.
	 */
	private function seed_pending_entry( int $post_id, string $platform_id = 'instagram-feed' ): string {
		$entry        = Outpost_Manual_Share_Audit_Log::add_entry( $post_id, $platform_id, 'navigator_share' );
		// SUT returns entries keyed with 'id' (canonical, per Audit_Log class docblock).
		// The external capture-endpoint JSON contract uses 'audit_log_id' as a more
		// descriptive parameter name; the two are deliberately separate. This helper
		// reads internal storage, so it uses the 'id' key.
		$audit_log_id = (string) $entry['id'];

		// Roll fired_at into the past so detector's grace check passes.
		// Audit_Log::add_entry() writes fired_at = now; Pending_Capture_Detector
		// reads fired_at for the grace + retention checks. The previous version
		// of this helper wrote added_at, which is the canonical column on the
		// outpost_syndication_links meta (a DIFFERENT meta key) — confusing the
		// two schemas. fired_at is canonical here.
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( $post_id );
		foreach ( $entries as $i => $e ) {
			if ( ( $e['id'] ?? '' ) === $audit_log_id ) {
				$entries[ $i ]['fired_at'] = gmdate( 'c', time() - HOUR_IN_SECONDS );
			}
		}
		update_post_meta( $post_id, 'outpost_manual_share_log', $entries );

		// Force detector to surface this post (defaults rely on WP_Query
		// against meta_key existence; the seam keeps the test isolated).
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn (): array => array( $post_id )
		);

		return $audit_log_id;
	}

	/**
	 * Test 1: full capture loop — fire intent, wait past grace,
	 * pending endpoint surfaces entry, capture endpoint records
	 * silo URL, post meta updates, rendered content includes
	 * u-syndication anchor.
	 *
	 * @test
	 */
	public function full_capture_loop_writes_audit_log_and_renders_u_syndication(): void {
		$audit_log_id = $this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );

		// Pending endpoint surfaces the entry.
		$pending_req = new WP_REST_Request( 'GET', '/outpost/v1/manual-share/pending' );
		$pending     = rest_get_server()->dispatch( $pending_req );
		$this->assertSame( 200, $pending->get_status() );
		$pending_data = $pending->get_data();
		$this->assertNotEmpty( $pending_data, 'Pending endpoint should surface the seeded entry.' );

		// Capture endpoint records the silo URL.
		$capture_req = new WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' );
		$capture_req->set_header( 'Content-Type', 'application/json' );
		$capture_req->set_body(
			wp_json_encode(
				array(
					'post_id'      => $this->test_post_id,
					'audit_log_id' => $audit_log_id,
					'silo_url'     => 'https://www.instagram.com/p/abc123/',
				)
			)
		);
		$capture = rest_get_server()->dispatch( $capture_req );
		$this->assertSame( 200, $capture->get_status() );
		$capture_data = $capture->get_data();
		$this->assertSame( 'recorded', $capture_data['status'] ?? null );

		// Post meta records the silo URL.
		$links = get_post_meta( $this->test_post_id, 'outpost_syndication_links', true );
		$this->assertIsArray( $links );
		$this->assertNotEmpty( $links );
		$urls = array_column( $links, 'url' );
		$this->assertContains( 'https://www.instagram.com/p/abc123/', $urls );

		// the_content filter renders the u-syndication anchor.
		// Outpost_Syndication_Links_Renderer::append_links() reads the
		// post id via get_the_ID(), which requires $GLOBALS['post'] set
		// + setup_postdata(). Per PR-Aux-6-diagnosis cluster B-secondary-α:
		// stock WP test-authoring hygiene, not a platform gotcha.
		$GLOBALS['post'] = get_post( $this->test_post_id );
		setup_postdata( $GLOBALS['post'] );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $this->test_post_id ) );
		$this->assertStringContainsString( 'u-syndication', $content );
		$this->assertStringContainsString( 'https://www.instagram.com/p/abc123/', $content );
		wp_reset_postdata();
	}

	/**
	 * Test 2: capture endpoint rejects unauthenticated request.
	 *
	 * @test
	 */
	public function capture_endpoint_rejects_non_authenticated_request(): void {
		wp_set_current_user( 0 );

		$req = new WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'post_id'      => $this->test_post_id,
					'audit_log_id' => 'abc',
					'silo_url'     => 'https://www.instagram.com/p/abc123/',
				)
			)
		);
		$response = rest_get_server()->dispatch( $req );

		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Unauthenticated capture should return 401 (rest_forbidden) or 403.'
		);
	}

	/**
	 * Test 3: pending endpoint isolates by user. User A's posts are
	 * not surfaced when User B queries.
	 *
	 * @test
	 */
	public function pending_endpoint_only_returns_users_own_posts(): void {
		$this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );

		// Switch to User B and query — must return empty (post belongs to User A).
		wp_set_current_user( $this->other_user_id );
		// Detector seam returns the post regardless of owner; user filtering
		// happens inside the controller's per-user gate (post_author check).
		// To exercise that gate, force the resolver to return our post.
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			fn (): array => array( $this->test_post_id )
		);

		$req      = new WP_REST_Request( 'GET', '/outpost/v1/manual-share/pending' );
		$response = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$post_ids_returned = array_map(
			static fn ( $entry ): int => (int) ( $entry['post_id'] ?? 0 ),
			is_array( $data ) ? $data : array()
		);
		$this->assertNotContains(
			$this->test_post_id,
			$post_ids_returned,
			'User B must not see User A\'s pending entries.'
		);
	}

	/**
	 * Test 4: outpost_syndication_links is exposed via the WP REST
	 * post-meta API once captured.
	 *
	 * @test
	 */
	public function rest_api_exposes_outpost_syndication_links_post_meta(): void {
		$audit_log_id = $this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );

		// Capture first.
		$req = new WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'post_id'      => $this->test_post_id,
					'audit_log_id' => $audit_log_id,
					'silo_url'     => 'https://www.instagram.com/p/exposed/',
				)
			)
		);
		rest_get_server()->dispatch( $req );

		// Now GET the post via core REST.
		$post_req = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->test_post_id );
		$post_req->set_param( 'context', 'edit' );
		$post_resp = rest_get_server()->dispatch( $post_req );
		$this->assertSame( 200, $post_resp->get_status() );

		$post_data = $post_resp->get_data();
		$this->assertArrayHasKey( 'meta', $post_data );
		$this->assertArrayHasKey( 'outpost_syndication_links', $post_data['meta'] );
		$urls = array_column( $post_data['meta']['outpost_syndication_links'], 'url' );
		$this->assertContains( 'https://www.instagram.com/p/exposed/', $urls );
	}

	/**
	 * Put the request into the post-edit screen context the notice
	 * guards on, and choose which editor is active.
	 *
	 * With 'post.php', WP_Screen::get computes base = 'post'. Whether
	 * that screen reports as the block editor is what decides which of
	 * the notice's two surfaces fires, so the tests set it explicitly
	 * rather than inheriting core's default for the post type.
	 */
	private function enter_post_edit_screen( bool $block_editor ): void {
		$GLOBALS['post']    = get_post( $this->test_post_id );
		$_GET['post']       = $this->test_post_id;
		$_GET['action']     = 'edit';
		$GLOBALS['pagenow'] = 'post.php';
		$GLOBALS['typenow'] = 'post';
		set_current_screen( 'post.php' );
		get_current_screen()->is_block_editor( $block_editor );
	}

	private function leave_post_edit_screen(): void {
		unset( $_GET['post'], $_GET['action'], $GLOBALS['pagenow'], $GLOBALS['typenow'], $GLOBALS['post'] );
	}

	/**
	 * Test 5: the classic editor still gets the admin_notices markup.
	 *
	 * @test
	 */
	public function classic_post_edit_screen_shows_pending_notice(): void {
		$this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );
		$this->enter_post_edit_screen( false );

		ob_start();
		do_action( 'admin_notices' );
		$notices_html = (string) ob_get_clean();

		$this->leave_post_edit_screen();

		$this->assertNotEmpty( $notices_html );
		$this->assertMatchesRegularExpression(
			'/pending|syndication|instagram/i',
			$notices_html,
			'Admin notice must mention pending syndication.'
		);
	}

	/**
	 * Test 5b: on a block-editor screen the notice does NOT go through
	 * admin_notices, because core prints that output inside
	 * `.wrap.hide-if-js.block-editor-no-js` — the no-JS fallback
	 * container, hidden whenever the editor loads. Emitting there is
	 * indistinguishable from emitting nothing, so the class skips it
	 * and hands the data to the editor bundle instead.
	 *
	 * @test
	 */
	public function block_editor_screen_skips_the_hidden_admin_notice(): void {
		$this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );
		$this->enter_post_edit_screen( true );

		ob_start();
		do_action( 'admin_notices' );
		$notices_html = (string) ob_get_clean();

		$this->leave_post_edit_screen();

		$this->assertSame(
			'',
			$notices_html,
			'admin_notices output would land in the hidden no-JS container.'
		);
	}

	/**
	 * Test 5c: the block editor gets the same data as an inline payload
	 * on the sidebar bundle, which turns it into a core/notices notice.
	 *
	 * @test
	 */
	public function block_editor_screen_attaches_the_notice_payload(): void {
		$this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );
		$this->enter_post_edit_screen( true );

		do_action( 'enqueue_block_editor_assets' );
		$inline = wp_scripts()->get_data( \Outpost_Sidebar_Assets::HANDLE, 'before' );

		$this->leave_post_edit_screen();

		$payload = is_array( $inline ) ? implode( '', $inline ) : (string) $inline;
		$this->assertStringContainsString( 'window.outpostPendingSyndication', $payload );
		$this->assertStringContainsString( 'Instagram', $payload );
	}

	/**
	 * Test 6: the_excerpt filter also includes the u-syndication
	 * anchor (Bridgy feed-scanning paths benefit).
	 *
	 * @test
	 */
	public function the_excerpt_filter_also_includes_syndication_links(): void {
		$audit_log_id = $this->seed_pending_entry( $this->test_post_id, 'instagram-feed' );

		// Capture so meta exists.
		$req = new WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'post_id'      => $this->test_post_id,
					'audit_log_id' => $audit_log_id,
					'silo_url'     => 'https://www.instagram.com/p/excerpt/',
				)
			)
		);
		rest_get_server()->dispatch( $req );

		// Render via the_excerpt filter chain.
		// Renderer::append_links_excerpt() reads get_the_ID(); requires
		// $GLOBALS['post'] + setup_postdata(). Same fix as the F1 test
		// above. Per PR-Aux-6-diagnosis cluster B-secondary-α.
		$GLOBALS['post'] = get_post( $this->test_post_id );
		setup_postdata( $GLOBALS['post'] );
		$excerpt_in  = 'A short excerpt.';
		$excerpt_out = apply_filters( 'the_excerpt', $excerpt_in );
		$this->assertStringContainsString( 'u-syndication', $excerpt_out );
		$this->assertStringContainsString( 'https://www.instagram.com/p/excerpt/', $excerpt_out );
		wp_reset_postdata();
	}
}
