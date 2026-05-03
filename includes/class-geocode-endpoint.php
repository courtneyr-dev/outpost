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

	/** Per-user rate limit (requests per minute). Tighter than preview because
	 * Nominatim's own usage policy is "1 req/sec per IP, cache aggressively." */
	private const RATE_LIMIT_PER_MINUTE = 20;

	/** HTTP request timeout in seconds. */
	private const HTTP_TIMEOUT = 5;

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
				'methods'             => 'GET',
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
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
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
	 * Bearer-header presence check. Same trade-off as preview: the rate
	 * limiter and external API guard the surface area, so we accept a bearer
	 * header without local validation.
	 */
	private static function has_bearer_header(): bool {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+\S+/i', $header ) ) {
			return true;
		}
		// Query-string fallback for managed-WP hosts that strip Authorization.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['_o_token'] ) && is_string( $_GET['_o_token'] ) ) {
			return true;
		}
		// Also accept the spec-compliant `access_token` parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['access_token'] ) && is_string( $_GET['access_token'] ) ) {
			return true;
		}
		return false;
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
				/* translators: 1: minimum length, 2: maximum length */
				sprintf(
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
					'results'    => $cached,
					'cached'     => true,
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
				'results'    => $results,
				'cached'     => false,
				'attribution' => 'Data © OpenStreetMap contributors',
			)
		);
	}

	/**
	 * Per-user rate limit. Keys on user_id when logged in, IP otherwise.
	 *
	 * @return true|WP_Error true on success; WP_Error 429 when exceeded.
	 */
	private static function check_rate_limit() {
		$key = is_user_logged_in()
			? 'outpost_geocode_rl_u_' . get_current_user_id()
			: 'outpost_geocode_rl_ip_' . md5( self::client_ip() );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
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
		return true;
	}

	/**
	 * Resolve the client IP address. Honors Cloudflare's CF-Connecting-IP
	 * (production setup) before falling back to X-Forwarded-For chain
	 * left-most entry, then REMOTE_ADDR.
	 */
	private static function client_ip(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
			$first = trim( explode( ',', $chain )[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
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
