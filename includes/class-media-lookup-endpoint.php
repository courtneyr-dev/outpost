<?php
/**
 * Media metadata lookup proxy.
 *
 * The Doing composer modes (Watch, Read, Listen, Jam, Play, Game, Checkin)
 * can pre-fill title / cover / creator / year from Post Kinds for IndieWeb's
 * lookup APIs — the same data source behind Post Kinds' "Fetch from TMDB"
 * button, surfaced inside Outpost's PWA for every media kind.
 *
 * Post Kinds exposes `/post-kinds-indieweb/v1/lookup/{video,book,music,game,
 * venue}` (permission: `current_user_can('edit_posts')`). Outpost does NOT
 * call those routes directly from the browser: managed-WP hosts (GoDaddy's
 * Apache) strip the `Authorization` header, so a bearer-in-header request from
 * the PWA authenticates as anonymous and the lookup 403s. Instead this
 * endpoint is a same-site proxy — the PWA sends the bearer in the request
 * body (Micropub-spec compliant, the same pattern `Outpost_Preview_Endpoint`
 * uses), and Outpost dispatches to Post Kinds internally via `rest_do_request`
 * so the call is server-side and same-site.
 *
 * Contract:
 *   POST /wp-json/outpost/v1/lookup   { kind, q, type?, access_token? }
 *   → 200 { results: [ {title,cover,creator,year,external_id,url}, … ],
 *           kind, notConfigured: bool }
 *   → 501 post_kinds_inactive   when Post Kinds isn't active
 *   → 400 invalid_kind / invalid_query
 *   → 429 rate_limited
 *   → 401 unauthorized          (incl. inner-dispatch 401/403 — the GoDaddy
 *                                bearer-in-body case that never reached WP)
 *
 * The `notConfigured` flag is the graceful "this provider needs an API key in
 * Post Kinds → API Connections" signal (music / book / game / venue only
 * return results once their keys are set). It is NOT an error — the client
 * surfaces it as a friendly hint.
 *
 * Permission callback mirrors `Outpost_Preview_Endpoint` /
 * `Outpost_Geocode_Endpoint`: bearer-or-cookie, `show_in_index => false`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Media_Lookup_Endpoint {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/lookup';

	/** Post Kinds for IndieWeb REST namespace we proxy to. */
	private const PKIW_NAMESPACE = 'post-kinds-indieweb/v1';

	/** Per-caller rate limit (requests per minute). Matches the preview
	 * endpoint — the inner Post Kinds calls hit third-party APIs (TMDB, etc.)
	 * with their own quotas, so a modest cap protects both. */
	private const RATE_LIMIT_PER_MINUTE = 30;

	/** Min/max query length. Post Kinds providers reject empty or pathological
	 * queries; filtering at the edge gives cleaner errors. */
	private const QUERY_MIN_LENGTH = 2;
	private const QUERY_MAX_LENGTH = 200;

	/** Cap on normalized rows returned to the client. */
	private const MAX_RESULTS = 10;

	/** Content types Post Kinds' video lookup accepts for the `type` param. */
	private const VIDEO_TYPES = array( 'movie', 'tv', 'multi' );

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		// Managed-WP hosts (GoDaddy's Apache) strip the Authorization header, so
		// the PWA sends the IndieAuth token in the request body. This endpoint
		// dispatches internally to Post Kinds, which needs an authenticated user
		// — so restore the header from the body token BEFORE IndieAuth's own
		// determine_current_user callback (priority 10) runs, letting it validate
		// the token and set the user. Scoped to this route inside the callback.
		add_filter( 'determine_current_user', array( self::class, 'reinject_bearer_from_body' ), 1 );
	}

	/**
	 * Restore a stripped Authorization header from the request body so IndieAuth
	 * can authenticate the token and set the current user before this endpoint's
	 * internal Post Kinds dispatch runs.
	 *
	 * Managed-WP hosts (GoDaddy) drop HTTP_AUTHORIZATION; the PWA falls back to
	 * sending the token in the body. Unlike preview/geocode (which fetch
	 * externally and need no WP user), the lookup proxy calls rest_do_request
	 * against Post Kinds' edit_posts-gated route, so the user must be set.
	 *
	 * Hooked at priority 1 on determine_current_user, ahead of IndieAuth
	 * (priority 10/20). Returns the incoming value unchanged — it only restores
	 * the header, it never authenticates on its own, and it only acts when the
	 * header was actually missing (so it can't create a cookie/nonce collision).
	 *
	 * @param int|false|null $user User id resolved so far.
	 * @return int|false|null
	 */
	public static function reinject_bearer_from_body( $user ) {
		if ( ! empty( $user ) ) {
			return $user;
		}
		// Only for this endpoint's route (pretty or plain-permalink form).
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, self::ROUTE_NAMESPACE . self::ROUTE_PATH ) ) {
			return $user;
		}
		// Header present (host didn't strip it) — nothing to restore.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return $user;
		}
		$token = self::body_access_token();
		if ( '' !== $token ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		return $user;
	}

	/**
	 * The `access_token` from the request body (form-encoded $_POST or a JSON
	 * body), sanitized, or '' when absent.
	 */
	private static function body_access_token(): string {
		// Bearer-token auth path; nonces don't apply to token-authenticated
		// requests, and managed-WP hosts strip the header so the token rides
		// in the body.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$post_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
		if ( '' !== $post_token ) {
			return $post_token;
		}
		$raw = file_get_contents( 'php://input' );
		if ( false !== $raw && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['access_token'] ) && is_string( $decoded['access_token'] ) ) {
				return sanitize_text_field( $decoded['access_token'] );
			}
		}
		return '';
	}

	/**
	 * Register the REST route.
	 *
	 * `show_in_index => false` keeps the endpoint out of `/wp-json/`'s public
	 * route index (the AI Engine CVE-2025-11749 reconnaissance class).
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
					'kind' => array(
						'required' => true,
						'type'     => 'string',
					),
					'q'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'type' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Mirrors preview/geocode: bearer-or-cookie, with a
	 * filter override for site admins who want to lock it down.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
		/**
		 * Override the media-lookup permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_media_lookup_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost media lookup requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Bearer-presence check. Same trade-off as preview: the endpoint is
	 * rate-limited and only proxies read-only lookups, so accepting a bearer
	 * without local validation is acceptable. Reads the header first, then
	 * the request body (`access_token`) for managed-WP hosts that strip the
	 * Authorization header.
	 */
	private static function has_bearer_header(): bool {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+\S+/i', $header ) ) {
			return true;
		}
		// Spec-compliant body fallback (GoDaddy strips HTTP_AUTHORIZATION).
		// Bodies don't leak through access logs / history / CDN cache keys.
		return '' !== self::body_access_token();
	}

	/**
	 * Handle the lookup request: validate, gate on Post Kinds, rate-limit,
	 * dispatch internally to Post Kinds, normalize, return.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$kind  = sanitize_key( (string) $request->get_param( 'kind' ) );
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$query = trim( $query );

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

		$category = self::category_for_kind( $kind );
		if ( null === $category ) {
			return new WP_Error(
				'invalid_kind',
				__( 'Unknown lookup kind.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		// Gate on Post Kinds. Returns a clean 501 BEFORE any inner dispatch
		// so the client can render an "install Post Kinds" hint.
		if ( 'active' !== Outpost_Companion_Detector::is_post_kinds_active() ) {
			return new WP_Error(
				'post_kinds_inactive',
				__( 'Post Kinds for IndieWeb must be active to look up media.', 'outpost' ),
				array( 'status' => 501 )
			);
		}

		$rate_limit = self::check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$inner = new WP_REST_Request( 'GET', '/' . self::PKIW_NAMESPACE . '/lookup/' . $category );
		$inner->set_param( 'q', $query );
		if ( 'video' === $category ) {
			$type_raw = sanitize_key( (string) $request->get_param( 'type' ) );
			$inner->set_param( 'type', in_array( $type_raw, self::VIDEO_TYPES, true ) ? $type_raw : 'movie' );
		}

		$response = rest_do_request( $inner );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'lookup_failed',
				__( 'The metadata lookup service could not be reached.', 'outpost' ),
				array( 'status' => 502 )
			);
		}

		$status = (int) $response->get_status();
		$data   = $response->get_data();

		// Inner 401/403 is the GoDaddy bearer-in-body case: the token rode in
		// the body, IndieAuth never saw it, the internal dispatch authenticated
		// as anonymous and Post Kinds' edit_posts gate rejected it. Surface as
		// 401 so the client prompts a re-auth.
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'unauthorized',
				__( 'The lookup was not authorized. Sign out and back in to refresh your token.', 'outpost' ),
				array( 'status' => 401 )
			);
		}

		if ( $status >= 400 ) {
			$code = ( is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ) ? $data['code'] : '';
			// Provider not configured (missing API key) — graceful, not an error.
			if ( str_ends_with( $code, '_not_configured' ) ) {
				return self::success_response( $kind, array(), true );
			}
			// No match — return empty results rather than an error.
			if ( 404 === $status ) {
				return self::success_response( $kind, array(), false );
			}
			return new WP_Error(
				'lookup_failed',
				__( 'The metadata lookup service returned an error.', 'outpost' ),
				array( 'status' => 502 )
			);
		}

		$results = array();
		foreach ( self::extract_rows( $data ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$results[] = self::normalize_row( $row );
			if ( count( $results ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		return self::success_response( $kind, $results, false );
	}

	/**
	 * Build the success response envelope.
	 *
	 * @param string                              $kind          Requested kind.
	 * @param array<int, array<string, string>>   $results       Normalized rows.
	 * @param bool                                $not_configured Provider needs an API key.
	 * @return WP_REST_Response
	 */
	private static function success_response( string $kind, array $results, bool $not_configured ) {
		return rest_ensure_response(
			array(
				'results'       => $results,
				'kind'          => $kind,
				'notConfigured' => $not_configured,
			)
		);
	}

	/**
	 * Map a composer kind (or a Post Kinds category alias) to the Post Kinds
	 * lookup category. Filterable so sites can add kinds (e.g. a recipe
	 * companion) without editing core.
	 */
	private static function category_for_kind( string $kind ): ?string {
		$map = self::kind_map();
		return ( '' !== $kind && isset( $map[ $kind ] ) && is_string( $map[ $kind ] ) ) ? $map[ $kind ] : null;
	}

	/**
	 * The kind → Post Kinds category map. Keys include both the composer
	 * variant names (watch/read/listen/jam/play/game/checkin) and the Post
	 * Kinds category names (video/book/music/game/venue), so either vocabulary
	 * resolves. `game` is deliberately both a variant and a category — it maps
	 * to `game` either way.
	 *
	 * @return array<string, string>
	 */
	private static function kind_map(): array {
		$map = array(
			'watch'   => 'video',
			'video'   => 'video',
			'read'    => 'book',
			'book'    => 'book',
			'listen'  => 'music',
			'jam'     => 'music',
			'music'   => 'music',
			'play'    => 'game',
			'game'    => 'game',
			'checkin' => 'venue',
			'venue'   => 'venue',
		);
		/**
		 * Filter the kind → Post Kinds lookup category map.
		 *
		 * @param array<string, string> $map Kind slug → PKIW category.
		 */
		$filtered = apply_filters( 'outpost_media_lookup_kind_map', $map );
		return is_array( $filtered ) ? $filtered : $map;
	}

	/**
	 * Per-caller rate limit. Authenticated callers key on user id; bearer-only
	 * callers key on IP.
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate_limit() {
		$is_authed = is_user_logged_in();
		$key       = $is_authed
			? 'outpost_lookup_rl_u_' . get_current_user_id()
			: 'outpost_lookup_rl_ip_' . md5( self::client_ip() );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many lookups. Try again in a minute.', 'outpost' ),
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
	 * Resolve the client IP for rate-limit keying. Defaults to REMOTE_ADDR
	 * (the only value the web server sets and an attacker can't forge).
	 */
	private static function client_ip(): string {
		return (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	}

	/**
	 * Coerce a Post Kinds lookup response into a flat list of row arrays.
	 *
	 * Providers return a bare list of rows; a few wrap them under `results`;
	 * a direct-id lookup can return a single associative row. Handle all three.
	 *
	 * @param mixed $data
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_rows( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( array_key_exists( 'results', $data ) && is_array( $data['results'] ) ) {
			$data = $data['results'];
		}
		if ( array_is_list( $data ) ) {
			return array_values( array_filter( $data, 'is_array' ) );
		}
		// A non-empty associative array here is a single row (e.g. a direct-id
		// lookup) — wrap it. Empty arrays are lists, so they never reach here.
		return array( $data );
	}

	/**
	 * Normalize one provider row to the stable client shape. Providers differ
	 * (TMDB `title/poster/year/tmdb_id`, OpenLibrary `title/author/cover/
	 * first_publish_year`, MusicBrainz `title/artist/id/date`, Foursquare
	 * `name/…`), so each field maps a union of candidate keys.
	 *
	 * The adapter is declarative — values pass through as-is (no HTML
	 * decoding or truncation); the composer inputs and `esc_*` at render
	 * handle sanitization.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function normalize_row( array $row ): array {
		return array(
			'title'       => self::first_string( $row, array( 'title', 'name', 'track', 'album', 'display_name' ) ),
			'cover'       => self::first_string( $row, array( 'cover', 'poster', 'image', 'thumbnail', 'photo', 'cover_url', 'album_art' ) ),
			'creator'     => self::first_creator( $row, array( 'creator', 'author', 'authors', 'artist', 'artists', 'director', 'directors', 'byline' ) ),
			'year'        => self::first_year( $row, array( 'year', 'first_publish_year', 'release_date', 'first_air_date', 'date', 'first_publish_date', 'published' ) ),
			'external_id' => self::first_string( $row, array( 'external_id', 'tmdb_id', 'id', 'mbid', 'key', 'bgg_id', 'rawg_id', 'guid', 'fsq_id' ) ),
			'url'         => self::first_string( $row, array( 'url', 'link', 'permalink', 'external_url', 'uri' ) ),
		);
	}

	/**
	 * First candidate key whose scalar value is non-empty, as a string.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $keys
	 */
	private static function first_string( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}
			$value = $row[ $key ];
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * First candidate key resolving to a non-empty creator string. Handles a
	 * plain string, a list of strings, and a list of `{name}` objects.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $keys
	 */
	private static function first_creator( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}
			$resolved = self::creator_to_string( $row[ $key ] );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}
		return '';
	}

	/**
	 * @param mixed $value
	 */
	private static function creator_to_string( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			$names = array();
			foreach ( $value as $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) ) {
					$names[] = trim( $item );
				} elseif ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) && '' !== trim( $item['name'] ) ) {
					$names[] = trim( $item['name'] );
				}
			}
			// A single associative `{name}` (not a list of them).
			if ( empty( $names ) && isset( $value['name'] ) && is_string( $value['name'] ) ) {
				return trim( $value['name'] );
			}
			return implode( ', ', $names );
		}
		return '';
	}

	/**
	 * First candidate key resolving to a 4-digit year.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $keys
	 */
	private static function first_year( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}
			$value = $row[ $key ];
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( is_string( $value ) && 1 === preg_match( '/\b(\d{4})\b/', $value, $matches ) ) {
				return $matches[1];
			}
		}
		return '';
	}
}
