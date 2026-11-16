<?php
/**
 * Syndicate-targets endpoint — mode-aware companion chip listing.
 *
 * Phase F2. The Shanske Micropub plugin's `?q=syndicate-to` route returns
 * every configured destination, projected to the `[uid, name]` shape its
 * filter contract requires. That shape carries no per-mode information,
 * so a Note-mode composer can't distinguish "ActivityPub federates Notes"
 * from "ManualShare to Instagram only accepts Photos."
 *
 * This endpoint is Outpost-owned. It returns the full
 * {@see Outpost_Companion_Base::capabilities()} shape for every active
 * companion, optionally filtered by composer mode. The composer hits
 * this when rendering the syndication strip for a specific mode and
 * decides locally which chips to surface as toggles.
 *
 * Security posture mirrors the preview / composer-config endpoints:
 *
 *   - `show_in_index => false` — keeps the route out of `/wp-json/`'s
 *     public listing. Defends against credentials-discovery scanning.
 *   - Permission callback requires `edit_posts` after bearer-token
 *     validation. Static metadata only — no SSRF surface, no external
 *     requests, no rate limiting needed.
 *   - Filterable via `outpost_syndicate_targets_permission`.
 *
 * Mode validation is fail-OPEN: an unknown mode returns every detected
 * chip. The composer always sends a known mode; defensive fail-open
 * means a typo from a third-party caller doesn't silently hide every
 * destination.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Syndicate_Targets_Endpoint {
	use Outpost_Bearer_Auth;

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/syndicate-targets';

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
					'mode' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Checks the user WordPress resolved (cookie
	 * session or IndieAuth bearer token) for edit_posts and token scope. Filterable.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		// Read-only: static companion-chip metadata, writes nothing. A
		// token scoped for `read` alone (as well as `create`/`update`) may
		// use it.
		$message = __( 'Outpost syndication targets require an authenticated user.', 'outpost-mobile-publishing' );
		// Credential first, and it fails early: a cookie session without a
		// valid REST nonce, or a token without an accepted scope, is refused
		// here, before the capability check. No filter can change this.
		if ( ! self::bearer_has_scope( $request, array( 'create', 'update', 'read' ) ) ) {
			// See Outpost_Preview_Endpoint::check_permission() for the
			// 401-vs-403 rationale.
			return new WP_Error(
				'rest_forbidden',
				$message,
				array( 'status' => current_user_can( 'edit_posts' ) ? 403 : 401 )
			);
		}
		// Capability second, unfiltered: a request without `edit_posts`,
		// anonymous ones included, is refused before the site filter runs.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				$message,
				array( 'status' => 401 )
			);
		}
		/**
		 * Filters whether a request may use the syndicate-targets endpoint.
		 *
		 * This filter can only restrict access. It runs after the credential
		 * check and the `edit_posts` check have both passed, and its value
		 * starts at true, so returning false denies the request while no
		 * return value admits a request those checks refused.
		 *
		 * @param bool            $allow   True: the request passed both checks.
		 * @param WP_REST_Request $request Current REST request.
		 */
		$allow = (bool) apply_filters( 'outpost_syndicate_targets_permission', true, $request );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				$message,
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Handle the request: read the optional `mode` query parameter,
	 * delegate to the registry's per-mode chip filter, and respond with
	 * the chip list and the resolved mode (so the client can confirm
	 * what the server interpreted — useful when fail-open kicks in on
	 * an unknown mode).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$raw_mode = $request->get_param( 'mode' );
		$mode     = is_string( $raw_mode ) && '' !== $raw_mode
			? $raw_mode
			: null;

		return new WP_REST_Response( self::build_payload( $mode ), 200 );
	}

	/**
	 * Build the mode-aware syndication target payload.
	 *
	 * @param string|null $mode Requested composer mode, or null for all modes.
	 * @return array{mode_requested: string|null, mode_applied: string|null, mode_recognized: bool, chips: array<int, array<string, mixed>>}
	 */
	public static function build_payload( ?string $mode ): array {

		$known_modes     = Outpost_Companion_Registry::known_modes();
		$mode_recognized = null !== $mode && in_array( $mode, $known_modes, true );
		$applied_filter  = $mode_recognized ? $mode : null;
		$chips           = Outpost_Companion_Registry::chips_for_mode( $mode );

		return array(
			'mode_requested'  => $mode,
			'mode_applied'    => $applied_filter,
			'mode_recognized' => $mode_recognized || null === $mode,
			'chips'           => $chips,
		);
	}
}
