<?php
/**
 * F6 — integration test: Outpost_Shortcut_Controller end-to-end.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (5 of 5). Cluster #7
 * of the G99 stub migration. JSON-API counterpart to cluster #6's
 * ShareTargetDispatchTest — same dispatch logic, JSON body in /
 * 303 redirect out instead of $_POST in / 303 out.
 *
 * Pre-readiness check + gotcha #10 resolution:
 *
 *   The Shortcut controller previously hard-coded
 *   `file_get_contents('php://input')` with no test seam — gotcha #10
 *   surfaced during the cluster #7 first attempt. Resolved by the
 *   PR-stacked-upstream `Outpost_Shortcut_Controller::set_payload_source_for_tests`
 *   seam (PR #74). Tests inject JSON bodies via the seam; tearDown
 *   clears the seam back to null.
 *
 * Three structural constraints applied per memory's
 * outpost_test_assertion_discipline.md:
 *
 *   Rule 1 (no OR-assertions in defense-in-depth) — N/A here
 *   (no defense-in-depth shape in this cluster).
 *
 *   Rule 2 (auth-gate absence-of-side-effects) — tests 2 (GET 405),
 *   3 (unauthenticated 401), and 4 (malformed JSON 400) are all
 *   gate-style. Each asserts no redirect was captured AND no
 *   prefill transient written, catching TOCTOU regressions.
 *
 *   Rule 3 (custom registrations must not persist) — N/A
 *   (no fake source registration in this cluster).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Shortcut_Controller;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ShortcutDispatchTest extends TestCase {

	private const EXAMPLE_URL = 'https://example.com/article';

	/** @var array{url:string,status:int}[] */
	private array $captured_redirects = array();

	private int $test_user_id = 0;

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
				'user_login' => 'shortcut_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'shortcut_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->test_user_id, 'Failed to create test user.' );
		wp_set_current_user( $this->test_user_id );

		$this->reset_request_globals();
		$this->captured_redirects = array();
		$this->purge_prefill_transients();

		// See SpotifyEndToEndTest::setUp for rationale — gotcha #10.
		Outpost_Shortcut_Controller::set_redirect_callback_for_tests(
			function ( $location, $status ) {
				$this->captured_redirects[] = array(
					'url'    => (string) $location,
					'status' => (int) $status,
				);
			}
		);
	}

	protected function tearDown(): void {
		// Gotcha #10 hygiene: clear the payload-source seam so it
		// doesn't leak into other tests. Production never sets it,
		// but a setUp-only set without tearDown clear could persist
		// across test classes.
		Outpost_Shortcut_Controller::set_payload_source_for_tests( null );

		Outpost_Shortcut_Controller::set_redirect_callback_for_tests( null );
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
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Shortcut_Controller' )
			&& method_exists( 'Outpost_Shortcut_Controller', 'set_payload_source_for_tests' )
			&& defined( 'OUTPOST_TESTING_PWA_SHELL' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	private function reset_request_globals(): void {
		$_POST   = array();
		$_GET    = array();
		$_FILES  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	private function purge_prefill_transients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%' OR option_name LIKE '_transient_timeout_outpost_prefill_%'"
		);
	}

	/**
	 * Drive the Shortcut controller as if an iOS Shortcut JSON POST
	 * arrived. Body injected via the gotcha #10 seam. Returns the
	 * captured redirect URL or null when no redirect was issued.
	 *
	 * @param string $method REQUEST_METHOD to set ('POST' / 'GET').
	 * @param string $body   Raw body to inject via the seam.
	 */
	private function dispatch_shortcut( string $method, string $body ): ?string {
		$_SERVER['REQUEST_METHOD'] = $method;
		Outpost_Shortcut_Controller::set_payload_source_for_tests(
			static fn (): string => $body
		);
		Outpost_Shortcut_Controller::handle_request();
		if ( empty( $this->captured_redirects ) ) {
			return null;
		}
		return $this->captured_redirects[0]['url'];
	}

	/**
	 * Test 1: POST with JSON `{ url }` dispatches through
	 * Source_Detector to Source_Unknown picker (example.com isn't
	 * claimed by any concrete source).
	 *
	 * @test
	 */
	public function post_json_with_url_dispatches_and_303_redirects(): void {
		$body = wp_json_encode( array( 'url' => self::EXAMPLE_URL ) );
		$this->assertIsString( $body );

		$redirect_url = $this->dispatch_shortcut( 'POST', $body );

		$this->assertNotNull( $redirect_url, 'JSON POST should issue a 303 redirect.' );
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
		$this->assertStringContainsString( 'picker=', $redirect_url );
		$this->assertStringContainsString( 'source=unknown', $redirect_url );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Farticle', $redirect_url );
	}

	/**
	 * Test 2: GET method is rejected with 405. Per Rule 2, asserts
	 * absence of side effects rather than just "no redirect captured."
	 *
	 * @test
	 */
	public function get_method_returns_405(): void {
		// GET → is_post() returns false → send_status(405) → halt().
		// status_header is no-op under OUTPOST_TESTING_PWA_SHELL, so we
		// observe the gate via absence of dispatch + transient.
		$redirect_url = $this->dispatch_shortcut( 'GET', wp_json_encode( array( 'url' => self::EXAMPLE_URL ) ) );

		$this->assertNull(
			$redirect_url,
			'Method gate must block dispatch: no redirect should be issued for GET requests. '
			. 'A captured redirect here means is_post() check was skipped.'
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%'"
		);
		$this->assertSame(
			0,
			$transient_count,
			'Method gate must block prefill enqueue: no transient should exist after a GET request.'
		);
	}

	/**
	 * Test 3: Unauthenticated POST is blocked by `is_authenticated()`.
	 * Per Rule 2, asserts NO redirect AND NO transient — catches
	 * TOCTOU regression where auth check moves after dispatch.
	 *
	 * @test
	 */
	public function unauthenticated_returns_401(): void {
		wp_set_current_user( 0 );

		$body         = wp_json_encode( array( 'url' => self::EXAMPLE_URL ) );
		$redirect_url = $this->dispatch_shortcut( 'POST', $body );

		$this->assertNull(
			$redirect_url,
			'Auth gate must block dispatch: no redirect should be issued for unauthenticated POST.'
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%'"
		);
		$this->assertSame(
			0,
			$transient_count,
			'Auth gate must block prefill enqueue: no transient should exist after unauthenticated POST.'
		);
	}

	/**
	 * Test 3b: a bare `Authorization: Bearer x` header no longer authorizes.
	 * Before the fix, is_authenticated() ORed in has_bearer_header() (mere
	 * presence), so an anonymous POST with any Bearer header bypassed the gate.
	 *
	 * @test
	 */
	public function post_with_unresolved_bearer_header_is_blocked(): void {
		wp_set_current_user( 0 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential

		$body         = wp_json_encode( array( 'url' => self::EXAMPLE_URL ) );
		$redirect_url = $this->dispatch_shortcut( 'POST', $body );

		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertNull(
			$redirect_url,
			'A bare Bearer header must not authorize shortcut dispatch: no redirect should be issued.'
		);
	}

	/**
	 * Test 4: Malformed (non-JSON) body returns 400. Per Rule 2,
	 * asserts NO redirect AND NO transient — gates fire before dispatch.
	 *
	 * @test
	 */
	public function malformed_json_body_returns_400(): void {
		// `read_json_payload()` returns null when json_decode fails;
		// controller sends 400 + halts. No dispatch.
		$redirect_url = $this->dispatch_shortcut( 'POST', 'not even close to json {{' );

		$this->assertNull(
			$redirect_url,
			'JSON-parse gate must block dispatch: no redirect should be issued for malformed bodies.'
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_prefill_%'"
		);
		$this->assertSame(
			0,
			$transient_count,
			'JSON-parse gate must block prefill enqueue: no transient should exist after malformed body.'
		);
	}

	/**
	 * Test 5: `shared_text` field is mapped to `text` per the iOS
	 * Shortcut bridge contract (controller line 62). The
	 * Source_Detector's `extract_url_from_payload` priority chain
	 * pulls a URL out of the text field even when no `url` field
	 * is provided.
	 *
	 * @test
	 */
	public function shared_text_field_routes_through_text_extraction(): void {
		// shared_text contains an embedded URL; text-contains regex
		// extracts the http(s):// chunk.
		$body = wp_json_encode(
			array(
				'shared_text' => 'Read this https://example.com/x',
			)
		);
		$this->assertIsString( $body );

		$redirect_url = $this->dispatch_shortcut( 'POST', $body );

		$this->assertNotNull( $redirect_url, 'shared_text URL extraction should produce a redirect.' );
		$this->assertSame( 303, $this->captured_redirects[0]['status'] );
		$this->assertStringContainsString(
			'url=https%3A%2F%2Fexample.com%2Fx',
			$redirect_url,
			'Extracted URL from shared_text must be carried through URL-encoded.'
		);
	}
}
