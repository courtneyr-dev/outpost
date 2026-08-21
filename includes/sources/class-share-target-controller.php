<?php
/**
 * Outpost_Share_Target_Controller
 *
 * Handles the existing `/post/share-target` route as a real
 * dispatcher. Web Share Target Level 2 POSTs share data here; this
 * controller extracts the URL via the priority chain, runs it
 * through `Outpost_Source_Detector::dispatch`, optionally enqueues
 * the B2 preview transient for unambiguous routes, and 303-redirects
 * to the composer.
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
 * ride along). Dispatch itself is a pure same-origin redirect
 * computation: no fetch fires, nothing is written. The one auth-gated
 * side effect is the B2 preview transient warm-up (it spends the user's
 * per-user preview rate-limit budget), which stays cookie-gated below
 * and additionally self-guards inside enqueue_preview_transient().
 * The composer the redirect lands on enforces its own client-side auth.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Share_Target_Controller {

	/** Transient TTL for pre-fill preview cache (per-user, per-URL hash). */
	private const PREFILL_TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

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

		// No URL detected anywhere AND no share text — direct navigation
		// to /post/share-target. Let the caller render the PWA shell.
		if ( null === $url && empty( $payload['title'] ) && empty( $payload['text'] ) ) {
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

		// Preview warm-up is the only authenticated side effect: it spends
		// the user's per-user preview rate-limit budget, so anonymous
		// share navigations (phones — see the class docblock) skip it and
		// the composer fetches the preview itself after sign-in.
		if ( 'auto' === $decision['route_type'] && ! empty( $decision['prefill_token'] ) && self::is_authenticated() ) {
			self::enqueue_preview_transient( $url, (string) $decision['prefill_token'], (string) $decision['source_id'] );
		}

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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Web Share Target spec; nonces don't apply to OS-initiated share intents.
		$title = self::pick_string( $_POST, 'title' );
		if ( '' === $title ) {
			$title = self::pick_string( $_GET, 'title' );
		}
		$text = self::pick_string( $_POST, 'text' );
		if ( '' === $text ) {
			$text = self::pick_string( $_GET, 'text' );
		}
		$url = self::pick_string( $_POST, 'url' );
		if ( '' === $url ) {
			$url = self::pick_string( $_GET, 'url' );
		}
		// phpcs:enable

		return array(
			'title' => $title,
			'text'  => $text,
			'url'   => $url,
		);
	}

	/**
	 * Sanitize a string field from a request superglobal slice.
	 *
	 * @param array<string,mixed> $source Superglobal slice.
	 * @param string              $key    Field name.
	 * @return string
	 */
	private static function pick_string( array $source, string $key ): string {
		if ( ! isset( $source[ $key ] ) ) {
			return '';
		}
		$raw = $source[ $key ];
		if ( ! is_string( $raw ) ) {
			return '';
		}
		// `wp_unslash` reverses WP's auto-magic-quote slashing; sanitize_text_field
		// strips control bytes / multi-line attempts. URL validation runs later in
		// extract_url_from_payload via wp_parse_url.
		return sanitize_text_field( wp_unslash( $raw ) );
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
	 * Enqueue the B2 preview transient for an auto-routed dispatch.
	 * Fire-and-forget — the composer falls back to a fresh fetch if
	 * the transient isn't present when it loads. Uses a short-timeout
	 * non-blocking POST to the existing /preview endpoint.
	 *
	 * The transient itself is set by the preview endpoint when it
	 * sees `?cached_for=` on a fresh request; F6 just fires the
	 * preview ahead of the redirect so the cache is warm by the
	 * time the composer asks for it.
	 *
	 * Public so `Outpost_Shortcut_Controller` can call it directly
	 * without duplicating the transient-key + payload logic.
	 *
	 * @param string $url       Source URL.
	 * @param string $token     Pre-fill token (URL hash).
	 * @param string $source_id Source ID.
	 */
	public static function enqueue_preview_transient( string $url, string $token, string $source_id ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$key = self::transient_key( $user_id, $token );
		// Mark the slot as "fetch in flight" so the composer waits a
		// reasonable interval before falling back to its own fetch.
		// The actual preview content gets written by a follow-up
		// request from the composer. The pending marker keeps this
		// fire-and-forget — we don't synchronously hit /preview here
		// because the share-target redirect should be instant.
		set_transient(
			$key,
			array(
				'status'    => 'pending',
				'url'       => $url,
				'source_id' => $source_id,
				'enqueued'  => time(),
			),
			self::PREFILL_TRANSIENT_TTL
		);
	}

	/**
	 * Build the per-user transient key for a pre-fill cache slot.
	 *
	 * @param int    $user_id Owning user ID.
	 * @param string $token   URL hash.
	 * @return string
	 */
	public static function transient_key( int $user_id, string $token ): string {
		return 'outpost_prefill_' . $user_id . '_' . $token;
	}

	/**
	 * Whether the current request carries a WordPress cookie session.
	 *
	 * Gates only the preview-transient warm-up — never the dispatch
	 * redirect (see the class docblock for the phone share-sheet flow).
	 *
	 * @return bool
	 */
	private static function is_authenticated(): bool {
		return is_user_logged_in();
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
	 * `Outpost_Shortcut_Controller` (PR #74). See
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
