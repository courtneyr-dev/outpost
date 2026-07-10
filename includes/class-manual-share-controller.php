<?php
/**
 * Outpost_Manual_Share_Controller
 *
 * REST endpoint for manual-share intent firing (Phase F9 stub; F10
 * Android, F11 iOS). The composer POSTs `{ post_id, platform_id }`
 * after a successful Micropub submit; F9's handler returns a structured
 * stub response so the composer can render "intent firing not yet
 * implemented (F10/F11)" without requiring real intent-firing logic.
 *
 * Security posture mirrors the preview / composer-config / syndicate-
 * targets endpoints:
 *
 *   - `show_in_index => false` keeps the route out of `/wp-json/`'s
 *     public listing.
 *   - Permission accepts cookie / `edit_posts` cap / bearer presence.
 *   - No SSRF surface, no external requests, no rate limiting needed
 *     in F9 — F10/F11 may add rate limiting once real intents fire.
 *
 * Validation:
 *
 *   - `post_id` MUST be a positive integer mapping to a published or
 *     draft post the requesting user can edit. F9 stub doesn't yet
 *     check the user-can-edit constraint exhaustively (the post may
 *     not exist yet during stub testing); F10 tightens.
 *   - `platform_id` MUST match the `id` of a registered platform per
 *     {@see Outpost_Manual_Share_Platform_Registry::all_platforms()}.
 *     Unknown platform IDs return 400 with a clear error message.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Controller {

	private const ROUTE_NAMESPACE   = 'outpost/v1';
	private const ROUTE_INTENT_PATH = '/manual-share/intent';
	private const ROUTE_LOG_PATH    = '/manual-share/intent/log';
	private const ROUTE_CHIPS_PATH  = '/manual-share-chips';

	/** Platform constants returned by {@see self::detect_platform()}. */
	public const PLATFORM_ANDROID = 'android';
	public const PLATFORM_IOS     = 'ios';
	public const PLATFORM_DESKTOP = 'desktop';

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register both manual-share REST routes:
	 *
	 *   POST /wp-json/outpost/v1/manual-share/intent
	 *        — F9 stub; F10/F11 wire real intent firing.
	 *   GET  /wp-json/outpost/v1/manual-share-chips?mode=...
	 *        — Returns the per-mode platform chip list. Composer reads
	 *          this when rendering the manual-share strip for a mode.
	 *          Mirrors the F2 syndicate-targets endpoint shape.
	 *
	 * F9 prompt mentioned `?q=manual-share-chips` as a Micropub query;
	 * Outpost integrates with Micropub via the Shanske plugin's
	 * documented filter hooks (per F1 #3) and does NOT run its own
	 * Micropub server. The `?q=` extension would need either a Shanske
	 * filter or a new Micropub plugin hook neither of which exists, so
	 * F9 ships an Outpost-owned REST route exactly like
	 * {@see Outpost_Syndicate_Targets_Endpoint} did for the per-mode
	 * companion-chip listing.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_INTENT_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_id'     => array(
						'required' => true,
						'type'     => 'integer',
					),
					'platform_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'in_pwa_mode' => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_CHIPS_PATH,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_chips_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'mode' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_LOG_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_log_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_id'      => array(
						'required' => true,
						'type'     => 'integer',
					),
					'audit_log_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'outcome'      => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Same shape as syndicate-targets / preview
	 * endpoints — cookie / edit_posts cap / bearer presence. Filterable.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
		/**
		 * Override the manual-share intent endpoint permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_manual_share_intent_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost manual-share intent firing requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Bearer-header presence check. Pattern shared with preview /
	 * composer-config / syndicate-targets / geocode endpoints.
	 */
	private static function has_bearer_header(): bool {
		$header = Outpost_Request_Headers::authorization();
		return '' !== $header && 1 === preg_match( '/^\s*Bearer\s+\S+/i', $header );
	}

	/**
	 * Handle the intent-firing request.
	 *
	 * F10 evolution: validate inputs, run per-post permission check,
	 * detect platform from User-Agent, dispatch to the appropriate
	 * payload builder. Android requests get the real intent payload
	 * via {@see Outpost_Manual_Share_Intent_Payload_Builder::build_for_android()};
	 * iOS and desktop continue to receive the F9 stub until F11 lands.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {
		$post_id_raw     = $request->get_param( 'post_id' );
		$platform_id_raw = $request->get_param( 'platform_id' );

		$post_id = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'Manual-share intent requires a positive integer post_id.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$platform_id = is_string( $platform_id_raw ) ? trim( $platform_id_raw ) : '';
		if ( '' === $platform_id ) {
			return new WP_Error(
				'invalid_platform_id',
				__( 'Manual-share intent requires a platform_id string.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$platforms = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$known_ids = array_map(
			static fn( Outpost_Manual_Share_Platform_Config $p ): string => $p->id(),
			$platforms
		);
		if ( ! in_array( $platform_id, $known_ids, true ) ) {
			return new WP_Error(
				'unknown_platform_id',
				sprintf(
					/* translators: %s: platform id supplied by the client. */
					__( 'Unknown manual-share platform: %s', 'outpost' ),
					$platform_id
				),
				array(
					'status'    => 400,
					'known_ids' => array_values( $known_ids ),
				)
			);
		}

		// Per-post edit-permission check (F10 acceptance #10).
		// Falls through cleanly on bearer-token auth where current
		// user is set by the IndieAuth plugin; rejects when the
		// requesting user is not a post author/editor for this post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden_post',
				__( 'You do not have permission to share this post.', 'outpost' ),
				array( 'status' => 403 )
			);
		}

		$platform_config = null;
		foreach ( $platforms as $candidate ) {
			if ( $candidate->id() === $platform_id ) {
				$platform_config = $candidate;
				break;
			}
		}
		if ( ! $platform_config instanceof Outpost_Manual_Share_Platform_Config ) {
			// Defensive — `in_array` already passed, so this branch is
			// unreachable in practice; keep the guard for type safety.
			return new WP_Error(
				'unknown_platform_id',
				__( 'Manual-share platform lookup failed.', 'outpost' ),
				array( 'status' => 500 )
			);
		}

		$platform = self::detect_platform( self::current_user_agent() );
		if ( self::PLATFORM_ANDROID === $platform ) {
			$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
				$platform_config,
				$post_id
			);
			return new WP_REST_Response( $payload, 200 );
		}

		if ( self::PLATFORM_IOS === $platform ) {
			$in_pwa_mode_raw = $request->get_param( 'in_pwa_mode' );
			$in_pwa_mode     = filter_var( $in_pwa_mode_raw, FILTER_VALIDATE_BOOLEAN );
			$payload         = Outpost_Manual_Share_Intent_Payload_Builder::build_for_ios(
				$platform_config,
				$post_id,
				$in_pwa_mode
			);
			return new WP_REST_Response( $payload, 200 );
		}

		// Desktop falls through to the F9 stub. A future session may add
		// desktop-specific handling (likely web-intent-only). F11 keeps
		// scope to the mobile branches.
		$stub = Outpost_Manual_Share_Intent_Payload_Builder::build_stub_response(
			$platform_id,
			$post_id
		);
		return new WP_REST_Response( $stub, 200 );
	}

	/**
	 * Handle telemetry POSTs from the PWA reporting the user-side
	 * outcome of a fired share. The PWA includes the audit_log_id
	 * returned in the original intent payload; this handler updates
	 * the matching audit log entry's `outcome` field.
	 *
	 * F12 will extend this endpoint to accept `silo_url` + `completed_at`
	 * when the user comes back with a posted-to-silo URL. F13 surfaces
	 * the resulting log in admin notices and composer.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_log_request( $request ) {
		$post_id_raw      = $request->get_param( 'post_id' );
		$audit_log_id_raw = $request->get_param( 'audit_log_id' );
		$outcome_raw      = $request->get_param( 'outcome' );

		$post_id = is_numeric( $post_id_raw ) ? (int) $post_id_raw : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'Manual-share telemetry requires a positive integer post_id.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden_post',
				__( 'You do not have permission to update telemetry for this post.', 'outpost' ),
				array( 'status' => 403 )
			);
		}

		$audit_log_id = is_string( $audit_log_id_raw ) ? trim( $audit_log_id_raw ) : '';
		if ( '' === $audit_log_id ) {
			return new WP_Error(
				'invalid_audit_log_id',
				__( 'Manual-share telemetry requires the audit_log_id from the original intent payload.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$outcome = is_string( $outcome_raw ) ? trim( $outcome_raw ) : '';
		$updated = Outpost_Manual_Share_Audit_Log::update_entry(
			$post_id,
			$audit_log_id,
			array( 'outcome' => $outcome )
		);
		if ( ! $updated ) {
			return new WP_Error(
				'audit_log_entry_not_found',
				__( 'Manual-share telemetry could not match an audit log entry. The PWA may have stale state.', 'outpost' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'status'       => 'recorded',
				'audit_log_id' => $audit_log_id,
				'outcome'      => Outpost_Manual_Share_Audit_Log::OUTCOME_UNKNOWN === $outcome ? Outpost_Manual_Share_Audit_Log::OUTCOME_UNKNOWN : $outcome,
			),
			200
		);
	}

	/**
	 * Classify the User-Agent into one of three buckets. Single
	 * function so tests can pass synthetic UAs without touching
	 * `$_SERVER`. F10 cares only about the Android branch; iOS and
	 * desktop both fall through to the F9 stub.
	 *
	 * Detection heuristics:
	 *
	 *   - Android: contains "Android" anywhere in the UA string.
	 *   - iOS: contains "iPhone", "iPad", or "iPod".
	 *   - Otherwise: desktop.
	 *
	 * The order matters because iPadOS 13+ Safari sends a Mac-shaped UA
	 * by default, but `iPad` still appears when Request Desktop Site
	 * is off; for Request-Desktop-Site iPads the UA is indistinguishable
	 * from macOS Safari and we treat it as desktop. F11 iOS work will
	 * add display-mode + touch-points heuristics on the PWA side to
	 * recover those cases without hardcoding a list of UAs.
	 *
	 * @param string $user_agent Full User-Agent header value.
	 */
	public static function detect_platform( string $user_agent ): string {
		if ( false !== stripos( $user_agent, 'Android' ) ) {
			return self::PLATFORM_ANDROID;
		}
		if ( false !== stripos( $user_agent, 'iPhone' )
			|| false !== stripos( $user_agent, 'iPad' )
			|| false !== stripos( $user_agent, 'iPod' )
		) {
			return self::PLATFORM_IOS;
		}
		return self::PLATFORM_DESKTOP;
	}

	private static function current_user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return Outpost_Request_Headers::server_string( 'HTTP_USER_AGENT' );
	}

	/**
	 * GET /manual-share-chips handler. Returns platform-only chips for
	 * the requested composer mode.
	 *
	 * Filters chips by intersecting `chips_for_mode($mode)` with chips
	 * whose `manual_share` extension is set — this strips F1+F2 single-
	 * companion chips (ActivityPub) so the response carries only manual-
	 * share platforms. The composer renders manual-share chips in a
	 * separate strip from the syndication chips, so the response is
	 * purpose-filtered on the server.
	 *
	 * Mode validation is fail-OPEN (matches syndicate-targets endpoint
	 * behavior): an unknown mode returns every detected manual-share
	 * chip rather than zero.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_chips_request( $request ) {
		$raw_mode = $request->get_param( 'mode' );
		$mode     = is_string( $raw_mode ) && '' !== $raw_mode
			? $raw_mode
			: null;

		$known_modes     = Outpost_Companion_Registry::known_modes();
		$mode_recognized = null !== $mode && in_array( $mode, $known_modes, true );
		$applied_filter  = $mode_recognized ? $mode : null;
		$all_chips       = Outpost_Companion_Registry::chips_for_mode( $mode );
		$manual_chips    = array_values(
			array_filter(
				$all_chips,
				static fn( array $chip ): bool => isset( $chip['manual_share'] ) && is_array( $chip['manual_share'] )
			)
		);

		$payload = array(
			'mode_requested'  => $mode,
			'mode_applied'    => $applied_filter,
			'mode_recognized' => $mode_recognized || null === $mode,
			'chips'           => $manual_chips,
		);

		return new WP_REST_Response( $payload, 200 );
	}
}
