<?php
/**
 * Geocode endpoint — proxies OpenStreetMap Nominatim for the Checkin variant.
 *
 * The Checkin sub-mode in the Listen-group tab needs lat/lon coordinates so a
 * post can include `location: geo:lat,lon` per the IndieWeb checkin pattern.
 * Nominatim provides free geocoding but its usage policy requires:
 *
 *   1. A descriptive User-Agent that identifies the application.
 *   2. At most 1 request/second per IP, aggressive client-side caching.
 *   3. Attribution of the data ("© OpenStreetMap contributors").
 *
 * Browsers can't set a custom User-Agent, so direct browser→Nominatim
 * requests technically violate point 1. This endpoint proxies the call:
 * sets a proper User-Agent, caches results for 24 hours per query, and
 * rate-limits per user / IP.
 *
 * Permission shape mirrors `Outpost_Preview_Endpoint`: bearer-or-cookie auth
 * with a permission filter override for sites that want to lock it down.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Geocode_Endpoint {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/geocode';

	/** Nominatim search endpoint. */
	private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

	/** Rate limit for authenticated users (requests per minute). Tighter than
	 * preview because Nominatim's own usage policy is "1 req/sec per IP, cache
	 * aggressively." */
	private const RATE_LIMIT_PER_MINUTE_AUTHED = 20;

	/** Rate limit for callers admitted without a WordPress session — only
	 * reachable when a site's `outpost_geocode_permission` filter loosens the
	 * default `current_user_can('edit_posts')` gate. Lower than the authed
	 * limit as a defense-in-depth throttle. */
	private const RATE_LIMIT_PER_MINUTE_UNAUTHED = 5;

	/** HTTP request timeout in seconds. Matches the preview-endpoint default;
	 * Nominatim's p99 is well under 2s, so 3s gives a fast failure on the
	 * user side without sacrificing realistic responses. */
	private const HTTP_TIMEOUT = 3;

	/** Cap on result count returned to the client. */
	private const MAX_RESULTS = 5;

	/** Transient TTL for cached query results. Nominatim prefers >24h caches
	 * for repeat queries; one day balances freshness against API politeness. */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/** Min/max bounds on the query string length. Nominatim rejects empty or
	 * pathologically long queries; we filter at the edge for cleaner errors. */
	private const QUERY_MIN_LENGTH = 2;
	private const QUERY_MAX_LENGTH = 200;

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register the REST route.
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
					'q' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Mirrors preview-endpoint's bearer-or-cookie pattern
	 * and exposes the same `outpost_geocode_permission` filter for site
	 * admins who want to lock it down.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		self::authenticate_bearer_token();

		$allow = current_user_can( 'edit_posts' );
		/**
		 * Override the geocode-endpoint permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_geocode_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost geocode requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Validate a bearer token (Authorization header or, on managed-WP hosts
	 * that strip the header, the request body) into a real WordPress user
	 * before the permission check runs. Mirrors the preview endpoint: mere
	 * presence of a bearer never authorizes — the token must resolve to a user
	 * via IndieAuth's determine_current_user, and current_user_can('edit_posts')
	 * is the sole gate. A token is never read from the query string (that turned
	 * the endpoint into an anonymous open proxy and leaked the token).
	 */
	private static function authenticate_bearer_token(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$token = self::bearer_token();
		if ( '' === $token ) {
			return;
		}
		// Restore a stripped Authorization header so IndieAuth's
		// determine_current_user callback can read and validate the token.
		if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) && empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		$user_id = (int) apply_filters( 'determine_current_user', false );
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * The bearer token from the Authorization header, or (on hosts that strip
	 * it) the `access_token` in a form-encoded or JSON request body. '' when absent.
	 */
	private static function bearer_token(): string {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+(\S+)/i', $header, $matches ) ) {
			return $matches[1];
		}
		// Bearer-token auth path; nonces don't apply to token-authenticated
		// requests, and managed-WP hosts strip the header so the token rides
		// in the body.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$body_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : null;
		if ( null === $body_token ) {
			$raw = file_get_contents( 'php://input' );
			if ( false !== $raw && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['access_token'] ) && is_string( $decoded['access_token'] ) ) {
					$body_token = sanitize_text_field( $decoded['access_token'] );
				}
			}
		}
		if ( is_string( $body_token ) && '' !== $body_token ) {
			return $body_token;
		}
		return '';
	}

	/**
	 * Handle the geocode request. Validate, rate-limit, cache, fetch, normalize.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$query = trim( (string) $request->get_param( 'q' ) );
		$query = sanitize_text_field( $query );

		$length = strlen( $query );
		if ( $length < self::QUERY_MIN_LENGTH || $length > self::QUERY_MAX_LENGTH ) {
			return new WP_Error(
				'invalid_query',
				sprintf(
					/* translators: 1: minimum length, 2: maximum length */
					__( 'Search query must be between %1$d and %2$d characters.', 'outpost' ),
					self::QUERY_MIN_LENGTH,
					self::QUERY_MAX_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		$rate_limit = self::check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$cache_key = 'outpost_geocode_' . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response(
				array(
					'results'     => $cached,
					'cached'      => true,
					'attribution' => 'Data © OpenStreetMap contributors',
				)
			);
		}

		$results = self::fetch_from_nominatim( $query );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		set_transient( $cache_key, $results, self::CACHE_TTL );

		return rest_ensure_response(
			array(
				'results'     => $results,
				'cached'      => false,
				'attribution' => 'Data © OpenStreetMap contributors',
			)
		);
	}

	/**
	 * Per-caller rate limit.
	 *
	 * Authenticated callers (cookie or a bearer resolved to a real user) get
	 * the higher `RATE_LIMIT_PER_MINUTE_AUTHED`. Callers without an active
	 * WordPress user shouldn't reach here because `check_permission` rejects
	 * them, but defensively we apply `RATE_LIMIT_PER_MINUTE_UNAUTHED` too.
	 *
	 * @return true|WP_Error true on success; WP_Error 429 when exceeded.
	 */
	private static function check_rate_limit() {
		$is_authed_user = is_user_logged_in();
		$key            = $is_authed_user
			? 'outpost_geocode_rl_u_' . get_current_user_id()
			: 'outpost_geocode_rl_ip_' . md5( self::client_ip() );
		$limit          = $is_authed_user
			? self::RATE_LIMIT_PER_MINUTE_AUTHED
			: self::RATE_LIMIT_PER_MINUTE_UNAUTHED;

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many geocode requests. Try again in a minute.', 'outpost' ),
				array(
					'status'     => 429,
					'retryAfter' => 60,
				)
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		// Secondary counter keyed on REMOTE_ADDR that no filter can override.
		// The primary counter above keys on `client_ip()` which respects the
		// `outpost_geocode_client_ip` filter — a hostile filter returning
		// `uniqid()` per call would defeat rate limiting entirely. The
		// secondary counter is the safety net: even if a filter sidesteps
		// the primary, the actual IP from the TCP layer still rate-limits.
		$remote_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? Outpost_Request_Headers::server_string( 'REMOTE_ADDR' ) : '0.0.0.0';
		$remote_key   = 'outpost_geocode_rl_remote_' . md5( $remote_ip );
		$remote_count = (int) get_transient( $remote_key );
		// Secondary cap is the higher of the two limits times a small
		// multiplier so it doesn't reject legitimate multi-user-on-NAT
		// deployments unfairly, only filter-based abuse.
		$remote_cap = max( self::RATE_LIMIT_PER_MINUTE_AUTHED, self::RATE_LIMIT_PER_MINUTE_UNAUTHED ) * 3;
		if ( $remote_count >= $remote_cap ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many geocode requests from this network. Try again in a minute.', 'outpost' ),
				array(
					'status'     => 429,
					'retryAfter' => 60,
				)
			);
		}
		set_transient( $remote_key, $remote_count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Resolve the client IP address.
	 *
	 * Defaults to `REMOTE_ADDR` because that's the only header the web server
	 * sets and an attacker can't forge. Sites behind Cloudflare or another
	 * trusted proxy that rewrites the source IP can opt in to honoring
	 * `CF-Connecting-IP` / `X-Forwarded-For` by defining
	 * `OUTPOST_TRUST_FORWARDED_HEADERS` in wp-config.php — without that opt-
	 * in, accepting those headers lets an attacker spoof their source IP and
	 * sidestep the rate limit (every spoofed IP gets its own counter).
	 *
	 * Filterable via `outpost_geocode_client_ip` for sites that need a
	 * different resolution path (e.g., a custom Varnish setup).
	 */
	private static function client_ip(): string {
		$default = Outpost_Request_Headers::server_string( 'REMOTE_ADDR', '0.0.0.0' );

		$trust_proxy = defined( 'OUTPOST_TRUST_FORWARDED_HEADERS' )
			&& OUTPOST_TRUST_FORWARDED_HEADERS;

		if ( $trust_proxy ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$default = Outpost_Request_Headers::server_string( 'HTTP_CF_CONNECTING_IP' );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$chain = Outpost_Request_Headers::server_string( 'HTTP_X_FORWARDED_FOR' );
				$first = trim( explode( ',', $chain )[0] );
				if ( '' !== $first ) {
					$default = $first;
				}
			}
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		}

		/**
		 * Override the resolved client IP for rate-limit keying.
		 *
		 * @param string $ip The resolved IP address.
		 */
		return (string) apply_filters( 'outpost_geocode_client_ip', $default );
	}

	/**
	 * Fetch normalized geocode results from Nominatim.
	 *
	 * Nominatim returns an array of place objects with lat, lon, display_name,
	 * type, importance, etc. We pass through the fields the UI needs and
	 * normalize the shape so future provider swaps (Pelias, MapTiler) can
	 * implement the same contract.
	 *
	 * @param string $query
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function fetch_from_nominatim( string $query ) {
		$url = add_query_arg(
			array(
				'q'              => $query,
				'format'         => 'jsonv2',
				'limit'          => self::MAX_RESULTS,
				'addressdetails' => 0,
				'extratags'      => 0,
			),
			self::NOMINATIM_URL
		);

		// User-Agent identifies the application per Nominatim usage policy.
		// Sites can override via the `outpost_geocode_user_agent` filter
		// (e.g., to add an admin contact email).
		$default_ua = sprintf(
			'Outpost/%s WordPress plugin (%s)',
			defined( 'OUTPOST_VERSION' ) ? OUTPOST_VERSION : 'unknown',
			home_url( '/' )
		);
		/**
		 * Filter the User-Agent string sent to OpenStreetMap Nominatim.
		 *
		 * @param string $default_ua The default User-Agent.
		 */
		$user_agent = (string) apply_filters( 'outpost_geocode_user_agent', $default_ua );
		// Defense against header-injection from a hostile filter. CRLF in the
		// User-Agent value can split into multiple HTTP headers on certain
		// cURL builds; truncating at 256 chars and rejecting CR/LF closes
		// that path. If the filter return is unsafe, fall back to default.
		if ( '' === $user_agent
			|| strlen( $user_agent ) > 256
			|| 1 === preg_match( '/[\r\n\0]/', $user_agent )
		) {
			$user_agent = $default_ua;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => $user_agent,
				'headers'    => array(
					'Accept'          => 'application/json',
					'Accept-Language' => self::accept_language(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'geocode_failed',
				__( 'Could not reach the geocoding service.', 'outpost' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'geocode_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Geocoding service returned HTTP %d.', 'outpost' ), $status ),
				array( 'status' => 502 )
			);
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'geocode_failed',
				__( 'Geocoding service returned invalid JSON.', 'outpost' ),
				array( 'status' => 502 )
			);
		}

		$results = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$lat          = isset( $entry['lat'] ) ? (string) $entry['lat'] : '';
			$lon          = isset( $entry['lon'] ) ? (string) $entry['lon'] : '';
			$display_name = isset( $entry['display_name'] ) ? (string) $entry['display_name'] : '';
			if ( '' === $lat || '' === $lon || '' === $display_name ) {
				continue;
			}
			// Lat/lon are returned as strings; cast to float to normalize.
			$results[] = array(
				'lat'         => (float) $lat,
				'lon'         => (float) $lon,
				'displayName' => $display_name,
				'type'        => isset( $entry['type'] ) ? (string) $entry['type'] : '',
			);
		}

		return $results;
	}

	/**
	 * Accept-Language for Nominatim — improves localization of place names.
	 * Falls back to en when get_locale isn't useful.
	 */
	private static function accept_language(): string {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		if ( '' === $locale ) {
			return 'en';
		}
		// Convert WP locale (en_US) to BCP 47 (en-US).
		return str_replace( '_', '-', $locale );
	}
}
