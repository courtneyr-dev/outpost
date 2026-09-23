<?php
/**
 * Outpost_Shortcut_Controller
 *
 * iOS Shortcut bridge endpoint at `/post/shortcut`. The companion
 * Web Share Target API never landed in iOS Safari (WebKit bug
 * 194593), so iOS users hit
 * `Outpost_IOS_Shortcut_REST_Controller` at
 * `/wp-json/outpost/v1/shortcut` (Bearer token) instead — that REST
 * endpoint is the supported iOS Shortcut path. Same dispatch logic as
 * the Web Share Target controller; just JSON in / 303 redirect out.
 *
 * (H7, 1.0.22) This route now requires a WP cookie session,
 * `edit_posts`, and a valid `outpost_shortcut` nonce (see
 * `is_authenticated()` below) — no client is issued that nonce today,
 * so the route is effectively closed and is expected to be retired in
 * a follow-up. See `docs/decisions/session-fx-ios-shortcut.md` point 17.
 *
 * Request shape:
 *
 *     POST /post/shortcut
 *       Content-Type: application/json
 *       Cookie: <wp session>
 *       Body: { "url": string, "shared_text"?: string, "_wpnonce": string }
 *
 * The Shortcut .plist generation is a separate session deliverable
 * (Phase E or later); F6 ships only the receiving endpoint so the
 * Shortcut author has a fixed contract to target.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Shortcut_Controller {

	/**
	 * Handle the inbound iOS Shortcut JSON POST.
	 */
	public static function handle_request(): void {
		if ( ! self::is_post() ) {
			self::send_status( 405 );
			Outpost_PWA_Shell::halt();
			return;
		}
		if ( ! self::is_authenticated() ) {
			// A logged-in user who failed on capability or nonce is
			// authenticated but forbidden (403); everyone else (no session
			// at all) is simply not authenticated (401) — mirrors core's
			// own rest_authorization_required_code() convention.
			self::send_status( is_user_logged_in() ? 403 : 401 );
			Outpost_PWA_Shell::halt();
			return;
		}

		$payload = self::read_json_payload();
		if ( null === $payload ) {
			self::send_status( 400 );
			Outpost_PWA_Shell::halt();
			return;
		}

		// Normalize Shortcut JSON to the same payload shape the share-target
		// extractor consumes. `shared_text` maps to `text` per the iOS
		// Shortcut bridge research (concepts/capture-inbound-may-2026.md §6).
		// The shared-field sanitizers keep %XX octets, which
		// sanitize_text_field() deletes, and return '' for a non-string.
		$normalized = array(
			'url'   => Outpost_Source_Detector::sanitize_shared_url( $payload['url'] ?? '' ),
			'text'  => Outpost_Source_Detector::sanitize_shared_text( $payload['shared_text'] ?? '' ),
			'title' => Outpost_Source_Detector::sanitize_shared_text( $payload['title'] ?? '' ),
		);

		$url = Outpost_Source_Detector::extract_url_from_payload( $normalized );
		if ( null === $url ) {
			self::send_status( 400 );
			Outpost_PWA_Shell::halt();
			return;
		}

		$context  = array(
			'method'    => 'POST',
			'platform'  => 'ios',
			'shortcut'  => true,
			'has_files' => false,
			'origin'    => 'shortcut',
		);
		$decision = Outpost_Source_Detector::dispatch( $url, $context );

		if ( 'auto' === $decision['route_type'] && ! empty( $decision['prefill_token'] ) ) {
			Outpost_Share_Target_Controller::enqueue_preview_transient( $url, (string) $decision['prefill_token'], (string) $decision['source_id'] );
		}

		self::redirect( (string) $decision['redirect_url'] );
	}

	/**
	 * @return bool
	 */
	private static function is_post(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( Outpost_Request_Headers::server_string( 'REQUEST_METHOD' ) );
	}

	/**
	 * Test-only override for the JSON body source. When non-null,
	 * `read_json_payload()` calls this instead of
	 * `file_get_contents('php://input')`. Production never sets this;
	 * integration tests use it to inject JSON bodies without
	 * `stream_wrapper_register` hacks (which would replace ALL
	 * `php://*` paths and risk breaking PHP/WP internals).
	 *
	 * Pattern is reusable for any future controller that reads
	 * `php://input` directly. See
	 * `docs/dev/integration-test-gotchas.md` § gotcha #10.
	 *
	 * @var callable|null
	 */
	private static $payload_source_for_tests = null;

	/**
	 * Test seam: override the JSON body source. Pass null to clear
	 * (must be cleared in tearDown to avoid leakage across tests).
	 * Production code MUST NOT call this method.
	 *
	 * @param callable|null $reader Callable returning the raw body string.
	 */
	public static function set_payload_source_for_tests( ?callable $reader ): void {
		self::$payload_source_for_tests = $reader;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function read_json_payload(): ?array {
		if ( null !== self::$payload_source_for_tests ) {
			$raw = (string) call_user_func( self::$payload_source_for_tests );
		} else {
			$raw = (string) file_get_contents( 'php://input' );
		}
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Cookie-session gate. The REST endpoint at
	 * `Outpost_IOS_Shortcut_REST_Controller` (Bearer token, `edit_posts`)
	 * is the supported iOS Shortcut path. This direct cookie route
	 * requires `edit_posts` plus a valid `outpost_shortcut` nonce, the
	 * same pair a real wp-admin session carries — but no client is
	 * issued that nonce today, so the route is effectively closed. See
	 * the file header for the retirement note.
	 *
	 * @return bool
	 */
	private static function is_authenticated(): bool {
		return is_user_logged_in()
			&& current_user_can( 'edit_posts' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'outpost_shortcut' );
	}

	/**
	 * @param int $status HTTP status code.
	 */
	private static function send_status( int $status ): void {
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			status_header( $status );
		}
	}

	/**
	 * Test-only override for the redirect side-effect. Mirrors the
	 * pattern on `Outpost_Share_Target_Controller`. See
	 * `docs/dev/integration-test-gotchas.md` § gotcha #10.
	 *
	 * @var callable|null
	 */
	private static $redirect_callback_for_tests = null;

	/**
	 * Test seam: override the redirect side-effect. Pass null to clear
	 * (must be cleared in tearDown to avoid leakage across tests).
	 * Production code MUST NOT call this method.
	 *
	 * @param callable|null $callback Callable receiving (string $url, int $status).
	 */
	public static function set_redirect_callback_for_tests( ?callable $callback ): void {
		self::$redirect_callback_for_tests = $callback;
	}

	/**
	 * @param string $url Target URL.
	 */
	private static function redirect( string $url ): void {
		if ( null !== self::$redirect_callback_for_tests ) {
			call_user_func( self::$redirect_callback_for_tests, $url, 303 );
		} elseif ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			wp_safe_redirect( $url, 303 );
		}
		Outpost_PWA_Shell::halt();
	}
}
