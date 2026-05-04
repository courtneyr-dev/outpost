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
	private const ROUTE_CHIPS_PATH  = '/manual-share-chips';

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
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		return '' !== $header && 1 === preg_match( '/^\s*Bearer\s+\S+/i', $header );
	}

	/**
	 * Handle the request. F9 stub: validate inputs, return structured
	 * "not yet implemented" payload that the composer renders so the
	 * user sees clearly that intent firing is pending F10/F11.
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

		$known_ids = array_map(
			static fn( Outpost_Manual_Share_Platform_Config $p ): string => $p->id(),
			Outpost_Manual_Share_Platform_Registry::all_platforms()
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

		$payload = array(
			'status'      => 'stub',
			'message'     => sprintf(
				/* translators: %s: platform id (e.g. "instagram-feed"). */
				__( 'Manual share intent firing is not yet implemented (F10 Android / F11 iOS). Will fire intent for platform: %s', 'outpost' ),
				$platform_id
			),
			'platform_id' => $platform_id,
			'post_id'     => $post_id,
		);
		return new WP_REST_Response( $payload, 200 );
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
