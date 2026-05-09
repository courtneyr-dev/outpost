<?php
/**
 * Outpost_Shortcut_Controller
 *
 * iOS Shortcut bridge endpoint at `/post/shortcut`. The companion
 * Web Share Target API never landed in iOS Safari (WebKit bug
 * 194593), so iOS users hit this JSON endpoint via an Outpost-
 * generated iOS Shortcut instead. Same dispatch logic as the
 * Web Share Target controller; just JSON in / 303 redirect out.
 *
 * Request shape:
 *
 *     POST /post/shortcut
 *       Content-Type: application/json
 *       Authorization: Bearer <token>     # OR cookie session
 *       Body: { "url": string, "shared_text"?: string }
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
			self::send_status( 401 );
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
		$normalized = array(
			'url'   => isset( $payload['url'] ) && is_string( $payload['url'] ) ? sanitize_text_field( $payload['url'] ) : '',
			'text'  => isset( $payload['shared_text'] ) && is_string( $payload['shared_text'] ) ? sanitize_text_field( $payload['shared_text'] ) : '',
			'title' => isset( $payload['title'] ) && is_string( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '',
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
			&& 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
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
	 * @return bool
	 */
	private static function is_authenticated(): bool {
		return is_user_logged_in() || self::has_bearer_header();
	}

	/**
	 * @return bool
	 */
	private static function has_bearer_header(): bool {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		return '' !== $header && 1 === preg_match( '/^\s*Bearer\s+\S+/i', $header );
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
	 * @param string $url Target URL.
	 */
	private static function redirect( string $url ): void {
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			wp_safe_redirect( $url, 303 );
		}
		Outpost_PWA_Shell::halt();
	}
}
