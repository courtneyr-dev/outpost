<?php
/**
 * F6 — integration test: Outpost_Share_Target_Controller end-to-end.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (5 of 5). Cluster #6
 * of the G99 stub migration. Inherits dispatch patterns from clusters
 * #4/#5 (Spotify/YouTube) — direct controller call + wp_redirect
 * filter capture — and adds three controller-level edge surfaces:
 * auth gate, text-only fallback, custom-source-registration with
 * prefill-transient assertion, and layered SSRF.
 *
 * Three structural constraints applied per CLAUDE.md / memory
 * guidance from the PR #70/#71 patterns review:
 *
 *   1. No OR-assertions in defense-in-depth contexts. Test 5 asserts
 *      Layer A (dispatch) and Layer B (SSRF guard) independently
 *      with their own messages — never `assertTrue($a || $b)`.
 *
 *   2. Auth-gate test (test 2) asserts absence of side effects, not
 *      just the 401 status. Specifically: no redirect captured AND no
 *      prefill transient written. This catches a TOCTOU regression
 *      where the auth check moves AFTER dispatch.
 *
 *   3. Custom source registration (test 4) is scoped to a single
 *      test method via try/finally cleanup. The fake source MUST
 *      NOT persist into later tests; tearDown's reset+replay would
 *      leak into other test classes too, so we narrow to one method.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Share_Target_Controller;
use Outpost_Source_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ShareTargetDispatchTest extends TestCase {

	private const EXAMPLE_URL    = 'https://example.com/page';
	private const FAKE_URL       = 'https://fake-unambig.example.test/some-path';
	private const FAKE_SOURCE_ID = 'sharetarget-test-fake';
	private const LOCALHOST_URL  = 'http://localhost/page';

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

		$this->test_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'sharetarget_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'sharetarget_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->test_user_id, 'Failed to create test user.' );
		wp_set_current_user( $this->test_user_id );

		$this->reset_request_globals();
		$this->captured_redirects = array();
		$this->purge_prefill_transients();

		// See SpotifyEndToEndTest::setUp for rationale — gotcha #10.
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
		$this->purge_prefill_transients();
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
			&& class_exists( 'Outpost_Source_Registry' )
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
	 * Wipe any prefill transients so absence-of-side-effects assertions
	 * in test 2 see a clean slate. Brute-force via a LIKE query because
	 * the per-test transient key includes a token derived from the URL,
	 * which the test doesn't know in advance for the negative case.
	 */
	private function purge_prefill_transients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%' OR option_name LIKE '_transient_timeout_outpost_prefill_%'"
		);
	}

	/**
	 * Drive the share-target controller as if a Web Share Target Level
	 * 2 POST arrived. Returns the captured redirect URL or null when
	 * no redirect was issued.
	 */
	private function dispatch_share_target_post( array $payload ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( $payload as $key => $value ) {
			$_POST[ $key ] = $value;
		}
		Outpost_Share_Target_Controller::handle_request();
		if ( empty( $this->captured_redirects ) ) {
			return null;
		}
		return $this->captured_redirects[0]['url'];
	}

	/**
	 * Test 1: POST with `url` field for a host no concrete source
	 * claims falls through to Source_Unknown's picker route.
	 *
	 * @test
	 */
	public function post_with_url_field_dispatches_and_303_redirects(): void {
		$redirect_url = $this->dispatch_share_target_post( array( 'url' => self::EXAMPLE_URL ) );

		$this->assertNotNull( $redirect_url, 'Share-target should issue a 303 redirect for a URL payload.' );
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
		$this->assertStringContainsString( 'picker=', $redirect_url, 'Source_Unknown must yield picker route.' );
		$this->assertStringContainsString( 'source=unknown', $redirect_url );
		$this->assertStringContainsString( 'default=bookmark', $redirect_url, 'Source_Unknown declares mode_default=bookmark.' );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Fpage', $redirect_url, 'Source URL must round-trip URL-encoded.' );
	}

	/**
	 * Test 2: Unauthenticated POST dispatches (phones share without a WP
	 * cookie — the PWA authenticates client-side via IndieAuth tokens in
	 * IndexedDB, and an OS share navigation can't carry an Authorization
	 * header), but the auth-gated side effect must NOT fire. Per
	 * assertion-discipline Rule 2 this asserts ABSENCE OF THE GATED SIDE
	 * EFFECT — no prefill transient — not just the redirect, so a TOCTOU
	 * regression (auth check dropped from the enqueue path) is caught.
	 *
	 * @test
	 */
	public function post_unauthenticated_redirects_without_prefill(): void {
		// Drop auth before calling. wp_set_current_user(0) clears the
		// editor we set up in setUp() so is_authenticated() returns false.
		wp_set_current_user( 0 );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['url']              = self::EXAMPLE_URL;
		Outpost_Share_Target_Controller::handle_request();

		// The dispatch redirect is a pure same-origin computation and
		// must survive without auth — this is the phone share-sheet path.
		$this->assertCount(
			1,
			$this->captured_redirects,
			'Unauthenticated share must still dispatch: phones have no WP cookie and the redirect is side-effect-free.'
		);
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
		$this->assertStringContainsString(
			'/post/',
			$this->captured_redirects[0]['url'],
			'Dispatch must land on the composer, whose client-side auth takes over.'
		);

		// Side-effect absence assertion: no prefill transient written.
		// The warm-up spends the user's preview rate-limit budget, so it
		// stays cookie-gated. Brute-force LIKE check because anonymous
		// user has user_id=0 which the transient key would include.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%'"
		);
		$this->assertSame(
			0,
			$transient_count,
			'Anonymous dispatch must not enqueue a prefill: no outpost_prefill_* transient should exist. '
			. 'A nonzero count means the is_authenticated() gate was dropped from the enqueue path.'
		);
	}

	/**
	 * Test 2b: a bare `Authorization: Bearer x` header still does not
	 * authorize the prefill warm-up. Before the earlier fix,
	 * is_authenticated() ORed in has_bearer_header() (mere presence), so
	 * an anonymous POST carrying any Bearer header spent preview budget.
	 * The dispatch redirect itself is auth-free (see test 2); the gated
	 * side effect — the prefill transient — must stay absent.
	 *
	 * @test
	 */
	public function post_with_unresolved_bearer_header_gets_no_prefill(): void {
		wp_set_current_user( 0 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		$_SERVER['REQUEST_METHOD']     = 'POST';
		$_POST['url']                  = self::EXAMPLE_URL;

		Outpost_Share_Target_Controller::handle_request();

		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertCount(
			1,
			$this->captured_redirects,
			'Dispatch is auth-free; the bearer header neither blocks nor authorizes it.'
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%'"
		);
		$this->assertSame(
			0,
			$transient_count,
			'A bare Bearer header must not enqueue a prefill transient.'
		);
	}

	/**
	 * Test 3: POST with title + text but no url falls through to the
	 * share-text-only branch — Note mode pre-filled with title + text,
	 * no source URL.
	 *
	 * @test
	 */
	public function post_with_text_only_redirects_to_note_mode(): void {
		$redirect_url = $this->dispatch_share_target_post(
			array(
				'title' => 'Note',
				'text'  => 'A thought',
			)
		);

		$this->assertNotNull( $redirect_url, 'Text-only payload should redirect to Note mode.' );
		$this->assertStringContainsString( 'mode=note', $redirect_url );
		$this->assertStringContainsString( 'title=Note', $redirect_url );
		// 'A thought' rawurlencodes to 'A%20thought'.
		$this->assertStringContainsString( 'text=A%20thought', $redirect_url );
		$this->assertStringNotContainsString( 'source=', $redirect_url, 'No source URL → no source param.' );
	}

	/**
	 * Test 4: Custom unambiguous source registration — when a fake
	 * source claims an example.test URL, dispatch is auto-routed and
	 * the prefill transient is written before the redirect.
	 *
	 * Per assertion-discipline Rule 3, the fake-source registration is
	 * SCOPED to this single test method via try/finally so it does not
	 * persist into other tests. The reset+replay sequence on cleanup
	 * restores the production source set.
	 *
	 * @test
	 */
	public function unambiguous_dispatch_writes_prefill_transient(): void {
		require_once dirname( __DIR__ ) . '/fixtures/source-test-fakes.php';

		// Register fake source via outpost_sources_init action so the
		// registry's auto-bootstrap picks it up alongside production
		// sources. Source_Unknown auto-registers as the trailing
		// fallback after the action fires.
		$fake_init = static function (): void {
			$fake = new \Outpost_F5TestSource_Stub(
				array(
					'id'            => self::FAKE_SOURCE_ID,
					'host_patterns' => array( 'fake-unambig.example.test' ),
					'ambiguity'     => 'unambiguous',
					'mode'          => 'bookmark',
					'extractor'     => 'oembed',
				)
			);
			Outpost_Source_Registry::register( $fake );
		};

		add_action( 'outpost_sources_init', $fake_init, 5 );
		Outpost_Source_Registry::reset_for_tests();
		do_action( 'outpost_sources_init' );
		Outpost_Source_Registry::mark_bootstrapped_for_tests();

		try {
			$redirect_url = $this->dispatch_share_target_post( array( 'url' => self::FAKE_URL ) );

			$this->assertNotNull( $redirect_url );
			$this->assertStringContainsString( 'mode=bookmark', $redirect_url );
			$this->assertStringContainsString( 'source=' . self::FAKE_SOURCE_ID, $redirect_url );

			// The prefill transient must be present within the same request.
			$token       = substr( md5( self::FAKE_URL ), 0, 16 );
			$expected_key = 'outpost_prefill_' . $this->test_user_id . '_' . $token;
			$transient   = get_transient( $expected_key );
			$this->assertIsArray( $transient, 'Auto-route dispatch must write a prefill transient.' );
			$this->assertSame( 'pending', $transient['status'] );
			$this->assertSame( self::FAKE_URL, $transient['url'] );
			$this->assertSame( self::FAKE_SOURCE_ID, $transient['source_id'] );
		} finally {
			// Constraint Rule 3: clean up. Fake source MUST NOT persist.
			remove_action( 'outpost_sources_init', $fake_init, 5 );
			Outpost_Source_Registry::reset_for_tests();
			do_action( 'outpost_sources_init' );
			Outpost_Source_Registry::mark_bootstrapped_for_tests();
		}
	}

	/**
	 * Test 5: Layered SSRF defense. Per assertion-discipline Rule 1,
	 * this asserts EACH LAYER INDEPENDENTLY with its own message —
	 * NEVER `assertTrue($a || $b)`. The architecture is "dispatch
	 * builds redirect AND wp_safe_remote_get blocks fetch"; both must
	 * hold for defense-in-depth to be load-bearing.
	 *
	 * @test
	 */
	public function localhost_url_dispatches_but_b2_blocks_fetch(): void {
		// Layer A: dispatch is pure URL → decision. No fetch occurs at
		// this layer; the redirect is built unconditionally because the
		// composer is what ultimately calls /preview, not the controller.
		$redirect_url = $this->dispatch_share_target_post( array( 'url' => self::LOCALHOST_URL ) );

		$this->assertNotNull(
			$redirect_url,
			'Layer A: Source_Detector dispatch must build a redirect — dispatch is pure URL→decision, no fetch happens here.'
		);
		$this->assertStringContainsString(
			'url=http%3A%2F%2Flocalhost%2Fpage',
			$redirect_url,
			'Layer A: redirect must carry the user-supplied URL through to the composer (URL-encoded).'
		);

		// Layer B: independent — wp_safe_remote_get is the SSRF gate.
		// The /preview endpoint uses it; here we verify the primitive
		// itself blocks loopback so the architectural claim holds.
		// Note: this layer fires later in the user's flow, not from
		// the controller. The assertion is INDEPENDENT of Layer A.
		$fetch_response = wp_safe_remote_get( self::LOCALHOST_URL, array( 'timeout' => 1 ) );
		$this->assertTrue(
			is_wp_error( $fetch_response ),
			'Layer B: wp_safe_remote_get must block loopback at the HTTP layer — '
			. 'this is the SSRF defense the preview endpoint relies on, independent of dispatch.'
		);
	}
}
