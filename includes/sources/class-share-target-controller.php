<?php
/**
 * Outpost_Share_Target_Controller
 *
 * Handles the existing `/post/share-target` route as a real
 * dispatcher. Web Share Target Level 2 POSTs share data here; this
 * controller extracts the URL via the priority chain, runs it
 * through `Outpost_Source_Detector::dispatch`, and 303-redirects to
 * the composer.
 *
 * GET requests with no share parameters fall through to the existing
 * `Outpost_PWA_Shell::render()` so direct navigation to /post/share-target
 * still loads the composer with its existing client-side fallback.
 *
 * Auth: dispatch runs WITHOUT requiring a WordPress cookie session. On
 * phones the PWA authenticates via IndieAuth bearer tokens in IndexedDB,
 * and an OS-initiated share navigation cannot carry an Authorization
 * header — so a cookie gate here 401'd every share fired from a phone's
 * share sheet (desktop only worked when a wp-admin cookie happened to
 * ride along). The route is a pure same-origin redirect computation:
 * it reads, writes, and fetches nothing for any user, signed in or not,
 * so the share fields are untrusted text that only shapes the redirect.
 * The composer the redirect lands on enforces its own auth and asks the
 * person before it fetches or saves anything.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Share_Target_Controller {

	/**
	 * Handle the inbound share-target request. Called from
	 * Outpost_Route_Handler::dispatch() when the route matches
	 * `/post/share-target`.
	 *
	 * Halts the WP request lifecycle on its own (via `halt()`) when
	 * it issues a redirect; returns control to the caller (which
	 * then renders the PWA shell) when no share data is present.
	 */
	public static function handle_request(): void {
		$payload = self::read_payload();
		$url     = Outpost_Source_Detector::extract_url_from_payload( $payload );

		// No URL detected anywhere AND no share text — either direct
		// navigation to /post/share-target (let the caller render the PWA
		// shell) or a photo share that reached PHP because no service
		// worker intercepted it. The file can't be kept here without a
		// session and is never read; land the composer on the Photo tab
		// so the user picks the picture again from the library.
		if ( null === $url && empty( $payload['title'] ) && empty( $payload['text'] ) ) {
			if ( self::has_shared_files() ) {
				self::redirect( Outpost_Source_Detector::COMPOSER_PATH . '?mode=photo' );
			}
			return;
		}

		if ( null === $url ) {
			// Share-text-only: open Note mode with title + text pre-filled,
			// no source URL.
			self::redirect( self::build_note_redirect( $payload ) );
			return;
		}

		$context  = self::build_context();
		$decision = Outpost_Source_Detector::dispatch( $url, $context );

		self::redirect( (string) $decision['redirect_url'] );
	}

	/**
	 * Read the share payload from the inbound request. Web Share
	 * Target Level 2 POSTs multipart/form-data with title/text/url
	 * fields; Level 1 GETs the same fields as query string. We
	 * accept both shapes and merge GET + POST so the controller is
	 * agnostic to which level the browser uses.
	 *
	 * @return array{title:string, text:string, url:string}
	 */
	private static function read_payload(): array {
		// Every superglobal read below sits directly inside wp_kses(), which is
		// a fatal TypeError on an array (`?text[]=`), so it only ever sees a
		// string. sanitize_text_field() is not used: it deletes %XX octets,
		// which rewrites an encoded link (`/a%20b` becomes `/ab`). Android
		// share sheets put the link in `text`, often mid-sentence.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Web Share Target spec; nonces don't apply to OS-initiated share intents.
		$title = isset( $_POST['title'] ) && is_string( $_POST['title'] ) ? wp_kses( wp_unslash( $_POST['title'] ), array() ) : '';
		if ( '' === $title && isset( $_GET['title'] ) && is_string( $_GET['title'] ) ) {
			$title = wp_kses( wp_unslash( $_GET['title'] ), array() );
		}
		$text = isset( $_POST['text'] ) && is_string( $_POST['text'] ) ? wp_kses( wp_unslash( $_POST['text'] ), array() ) : '';
		if ( '' === $text && isset( $_GET['text'] ) && is_string( $_GET['text'] ) ) {
			$text = wp_kses( wp_unslash( $_GET['text'] ), array() );
		}
		// The url field is usually one bare URL, but share sheets sometimes put
		// text there. It is sanitized as text here; sanitize_shared_url() then
		// decides, on the sanitized value, whether it is one bare http(s) URL
		// (esc_url_raw()) or text for the same link detection `text` gets.
		$url = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? wp_kses( wp_unslash( $_POST['url'] ), array() ) : '';
		if ( '' === $url && isset( $_GET['url'] ) && is_string( $_GET['url'] ) ) {
			$url = wp_kses( wp_unslash( $_GET['url'] ), array() );
		}
		// phpcs:enable

		// The shared-field sanitizers finish the job on the sanitized values:
		// UTF-8 check, control characters, whitespace, the `&` wp_kses()
		// encoded, and esc_url_raw() with an http/https allowlist when the url
		// field is one bare URL. The Shortcut endpoints use the same two.
		return array(
			'title' => Outpost_Source_Detector::sanitize_shared_text( $title ),
			'text'  => Outpost_Source_Detector::sanitize_shared_text( $text ),
			'url'   => Outpost_Source_Detector::sanitize_shared_url( $url ),
		);
	}

	/**
	 * Whether the request carried at least one uploaded file — the Web
	 * Share Target Level 2 `files` param. The upload itself is never
	 * touched here; this only decides which composer tab opens.
	 *
	 * @return bool
	 */
	private static function has_shared_files(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Web Share Target spec; nonces don't apply to OS-initiated share intents.
		return ! empty( $_FILES );
	}

	/**
	 * Build the per-request context array. Telemetry-only — never
	 * branches dispatch logic.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_context(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only User-Agent inspection; not authenticated state.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( Outpost_Request_Headers::server_string( 'REQUEST_METHOD' ) ) : 'GET';
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? Outpost_Request_Headers::server_string( 'HTTP_USER_AGENT' ) : '';
		// phpcs:enable
		$platform = 'unknown';
		if ( false !== stripos( $ua, 'iphone' ) || false !== stripos( $ua, 'ipad' ) ) {
			$platform = 'ios';
		} elseif ( false !== stripos( $ua, 'android' ) ) {
			$platform = 'android';
		}
		// Web Share Target spec; nonces don't apply to OS-initiated share intents.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_files = ! empty( $_FILES );
		return array(
			'method'    => 'POST' === $method ? 'POST' : 'GET',
			'platform'  => $platform,
			'shortcut'  => false,
			'has_files' => $has_files,
			'origin'    => 'share-target',
		);
	}

	/**
	 * Build a redirect URL for the share-text-only flow (Note mode
	 * with title + text pre-filled, no source URL).
	 *
	 * @param array<string,string> $payload The share payload.
	 * @return string
	 */
	private static function build_note_redirect( array $payload ): string {
		$args = array(
			'mode' => 'note',
		);
		if ( ! empty( $payload['title'] ) ) {
			$args['title'] = $payload['title'];
		}
		if ( ! empty( $payload['text'] ) ) {
			$args['text'] = $payload['text'];
		}
		$query = array();
		foreach ( $args as $key => $value ) {
			$query[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return Outpost_Source_Detector::COMPOSER_PATH . '?' . implode( '&', $query );
	}

	/**
	 * Test-only override for the redirect side-effect. When non-null,
	 * `redirect()` calls this with `(url, status)` instead of
	 * `wp_safe_redirect()`. Production never sets this; integration
	 * tests use it to capture the redirect URL without relying on the
	 * `wp_redirect` filter (which never fires under
	 * `OUTPOST_TESTING_PWA_SHELL` because `wp_safe_redirect()` is
	 * skipped — the failure mode that produced the
	 * 2026-05-09 review-theater incident).
	 *
	 * Pattern mirrors gotcha #10's `set_payload_source_for_tests` on
	 * the former `Outpost_Shortcut_Controller` (PR #74; removed in 1.0.27). See
	 * `docs/dev/integration-test-gotchas.md`.
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
	 * Send a redirect to the composer URL and halt the request.
	 *
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
