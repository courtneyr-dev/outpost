<?php
/**
 * Server-side mf2 preview endpoint.
 *
 * Phase B2. The composer's Reply mode (Phase C1) needs to fetch a target URL
 * and surface citation context (title, author) before the user sends their
 * reply. Doing this client-side fails on CORS for external sites; doing it
 * server-side requires SSRF defenses because the URL is user-supplied.
 *
 * The endpoint accepts a POST with `{ "url": "https://..." }` and returns
 * `{ "html": "...", "finalUrl": "...", "contentType": "..." }` after:
 *
 *   1. Authenticating the request via the IndieAuth bearer token (handled
 *      transparently by the IndieAuth plugin's REST middleware — Outpost
 *      just calls `current_user_can( 'edit_posts' )`).
 *   2. Validating the URL (http(s) scheme only).
 *   3. Rate-limiting per user (30 requests/minute via transient).
 *   4. Fetching with `wp_safe_remote_get` (auto-blocks loopback + private
 *      networks per the `http_request_host_is_external` filter chain).
 *   5. Capping response size at 5 MB.
 *   6. Validating Content-Type against an allowlist (text/html,
 *      application/xhtml+xml).
 *   7. Stripping `<script>`, `<iframe>`, `<object>`, `<embed>` and
 *      event-handler attributes from the response HTML before returning.
 *
 * Per `docs/security/PHP-SURFACE-CHECKLIST.md` B2 section. The client
 * (Reply mode in `pwa/src/lib/preview.ts`) extracts the page title via
 * regex; richer microformats parsing (h-card author, e-content) lands
 * client-side when reply variants beyond plain-Reply (Like, Repost,
 * Bookmark, RSVP, Follow) need it.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Preview_Endpoint {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/preview';

	/** Allowed URL schemes — http(s) only. Defends against javascript:/data:/file:. */
	private const ALLOWED_SCHEMES = array( 'http', 'https' );

	/** Allowed Content-Type prefixes on the upstream response. */
	private const ALLOWED_CONTENT_TYPE_PREFIXES = array( 'text/html', 'application/xhtml+xml' );

	/** Hard cap on response body size (defense against pathological responses). */
	private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

	/** Per-user rate limit (requests per minute). */
	private const RATE_LIMIT_PER_MINUTE = 30;

	/** HTTP request timeout in seconds — short for snappier error fallback. */
	private const HTTP_TIMEOUT = 3;

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * `show_in_index => false` keeps this endpoint out of `/wp-json/`'s
	 * public route index — the AI Engine CVE-2025-11749 vulnerability
	 * worth defending against.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'url' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Relies on the IndieAuth plugin's REST middleware
	 * to translate the bearer token into a WordPress current_user, so the
	 * standard capability check works regardless of whether auth came from
	 * a cookie session or a bearer token.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost preview requires an authenticated user with posting capability.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Handle the preview request: validate URL, rate-limit, fetch, sanitize,
	 * return.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );

		$validation = self::validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$rate_limit = self::check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => implode( ', ', self::ALLOWED_CONTENT_TYPE_PREFIXES ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				__( 'Could not fetch the target URL.', 'outpost' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Target URL returned HTTP %d.', 'outpost' ), $status ),
				array( 'status' => 502 )
			);
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! self::content_type_is_allowed( $content_type ) ) {
			return new WP_Error(
				'unsupported_content_type',
				__( 'Target URL did not return HTML.', 'outpost' ),
				array(
					'status'      => 415,
					'contentType' => $content_type,
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'response_too_large',
				__( 'Target URL response exceeded the 5 MB cap.', 'outpost' ),
				array( 'status' => 413 )
			);
		}

		$sanitized = self::strip_dangerous_html( $body );

		return rest_ensure_response(
			array(
				'html'        => $sanitized,
				'finalUrl'    => $url,
				'contentType' => $content_type,
			)
		);
	}

	/**
	 * Validate the URL — reject non-http(s) schemes and malformed inputs.
	 *
	 * `wp_safe_remote_get` blocks loopback + private networks at the HTTP
	 * layer, so we don't re-implement IP validation here. Scheme validation
	 * up-front is faster and gives a clearer error.
	 *
	 * @return true|WP_Error
	 */
	private static function validate_url( string $url ) {
		if ( '' === trim( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'A url is required.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The url could not be parsed.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		// Scheme allowlist before host check: gives `file://...` a clearer
		// error than the host-missing path would.
		if ( ! in_array( strtolower( (string) $parts['scheme'] ), self::ALLOWED_SCHEMES, true ) ) {
			return new WP_Error(
				'invalid_scheme',
				__( 'Only http and https URLs are allowed.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $parts['host'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The URL must include a host.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Per-user rate limit via transient. 30 requests/minute. Returns a
	 * WP_Error with status 429 when the limit is exceeded.
	 *
	 * Anonymous fallback: keyed on `0` (the WP guest user ID). Permission
	 * callback rejects unauthenticated requests before this runs, so guest
	 * keying only matters in test scaffolding.
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate_limit() {
		$user_id = (int) get_current_user_id();
		$key     = "outpost_preview_rl_{$user_id}";
		$count   = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many preview requests. Try again in a minute.', 'outpost' ),
				array(
					'status'     => 429,
					'retryAfter' => 60,
				)
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Check that the response Content-Type starts with one of the allowed
	 * prefixes. Charset suffixes (`text/html; charset=utf-8`) are accepted.
	 */
	private static function content_type_is_allowed( string $content_type ): bool {
		$lower = strtolower( $content_type );
		foreach ( self::ALLOWED_CONTENT_TYPE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip `<script>`, `<iframe>`, `<object>`, `<embed>` blocks and
	 * `on*=` event-handler attributes from the response HTML.
	 *
	 * Defense in depth: the client never executes returned HTML (it parses
	 * for mf2 / extracts `<title>` via regex), but a future code path that
	 * naively renders should not be a security regression.
	 */
	private static function strip_dangerous_html( string $html ): string {
		// Remove script/iframe/object/embed tag blocks (with content).
		$html = preg_replace( '/<(script|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html ) ?? $html;
		// Remove self-closing or void variants.
		$html = preg_replace( '/<(script|iframe|object|embed)\b[^>]*\/?>/is', '', $html ) ?? $html;
		// Strip event handler attributes (onclick, onload, etc.).
		$html = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^>\s]+)/i', '', $html ) ?? $html;
		// Strip javascript: / data: hrefs and srcs.
		$html = preg_replace( '/\s(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\'|"data:[^"]*"|\'data:[^\']*\')/i', '', $html ) ?? $html;
		return $html;
	}
}
