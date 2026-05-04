<?php
/**
 * Outpost_Syndication_Capture_Controller
 *
 * REST endpoints for the manual-share phase-2 capture flow (F12).
 *
 *   GET  /wp-json/outpost/v1/manual-share/pending
 *        Returns the requesting user's posts with pending audit log
 *        entries (completed_at IS NULL within the grace + retention
 *        window). Composer's PendingSyndicationsPanel consumes this.
 *
 *   POST /wp-json/outpost/v1/manual-share/capture
 *        body: { post_id, audit_log_id, silo_url, confirm_mismatch? }
 *        Validates the URL, soft-warns on platform mismatch (unless
 *        the caller opts in via confirm_mismatch=true), updates the
 *        audit log entry's `completed_at` + `silo_url`, and writes
 *        the entry to `outpost_syndication_links` post-meta.
 *
 * Security posture mirrors the F9–F11 manual-share endpoints:
 *
 *   - `show_in_index => false` keeps these out of the public REST
 *     listing (defense against credentials-discovery scanning).
 *   - Permission accepts cookie / `edit_posts` cap / bearer presence
 *     at the route level + per-post `edit_post` check at the handler.
 *   - URL validation lives in {@see Outpost_Manual_Share_Syndication_Writeback};
 *     no fetch of the pasted URL (no SSRF surface beyond what
 *     wp_http_validate_url already blocks during validation).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Syndication_Capture_Controller {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PENDING   = '/manual-share/pending';
	private const ROUTE_CAPTURE   = '/manual-share/capture';

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register both capture-flow REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PENDING,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_pending_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_CAPTURE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_capture_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_id'          => array(
						'required' => true,
						'type'     => 'integer',
					),
					'audit_log_id'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'silo_url'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'confirm_mismatch' => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Same shape as the other manual-share
	 * endpoints — cookie / edit_posts cap / bearer presence.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
		/**
		 * Override the syndication-capture endpoint permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_syndication_capture_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost syndication capture requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

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
	 * GET handler: list the requesting user's pending captures.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_pending_request( $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		$results = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( $user_id );
		return new WP_REST_Response(
			array(
				'pending' => $results,
			),
			200
		);
	}

	/**
	 * POST handler: validate URL, optionally soft-warn on mismatch,
	 * write outpost_syndication_links + update audit log.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_capture_request( $request ) {
		$post_id          = is_numeric( $request->get_param( 'post_id' ) ) ? (int) $request->get_param( 'post_id' ) : 0;
		$audit_log_id_raw = $request->get_param( 'audit_log_id' );
		$silo_url_raw     = $request->get_param( 'silo_url' );
		$confirm_mismatch = (bool) filter_var(
			$request->get_param( 'confirm_mismatch' ),
			FILTER_VALIDATE_BOOLEAN
		);

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'A positive post_id is required.', 'outpost' ),
				array( 'status' => 400 )
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden_post',
				__( 'You do not have permission to update syndication for this post.', 'outpost' ),
				array( 'status' => 403 )
			);
		}

		$audit_log_id = is_string( $audit_log_id_raw ) ? trim( $audit_log_id_raw ) : '';
		if ( '' === $audit_log_id ) {
			return new WP_Error(
				'invalid_audit_log_id',
				__( 'audit_log_id is required.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$silo_url = is_string( $silo_url_raw ) ? trim( $silo_url_raw ) : '';
		$validity = Outpost_Manual_Share_Syndication_Writeback::validate_url( $silo_url );
		if ( is_wp_error( $validity ) ) {
			return new WP_Error(
				$validity->get_error_code(),
				$validity->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Look up the audit entry to get the platform_id (we need it
		// for the syndication_links record + the mismatch check).
		$entry = self::find_audit_entry( $post_id, $audit_log_id );
		if ( null === $entry ) {
			return new WP_Error(
				'audit_log_entry_not_found',
				__( 'No matching audit log entry. The PWA may have stale state.', 'outpost' ),
				array( 'status' => 404 )
			);
		}
		$platform_id = (string) ( $entry['platform_id'] ?? '' );

		// Platform-mismatch soft warning. Caller can confirm with
		// `confirm_mismatch=true` to proceed despite the warning.
		$mismatch = Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
			$platform_id,
			$silo_url
		);
		if ( $mismatch && ! $confirm_mismatch ) {
			return new WP_REST_Response(
				array(
					'status'      => 'mismatch_warning',
					'platform_id' => $platform_id,
					'silo_url'    => $silo_url,
					'message'     => sprintf(
						/* translators: %1$s: platform id; %2$s: pasted URL host. */
						__( 'This URL\'s domain doesn\'t match the expected %1$s domain. Confirm the URL is correct, then resubmit with confirm_mismatch=true.', 'outpost' ),
						$platform_id
					),
				),
				200
			);
		}

		// Write the syndication link + update audit entry.
		$links_after = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			$post_id,
			$platform_id,
			$silo_url
		);
		Outpost_Manual_Share_Audit_Log::update_entry(
			$post_id,
			$audit_log_id,
			array(
				'silo_url'     => $silo_url,
				'completed_at' => gmdate( 'c' ),
				'outcome'      => Outpost_Manual_Share_Audit_Log::OUTCOME_FIRED,
			)
		);

		return new WP_REST_Response(
			array(
				'status'             => 'recorded',
				'audit_log_id'       => $audit_log_id,
				'silo_url'           => $silo_url,
				'platform_id'        => $platform_id,
				'syndication_links'  => $links_after,
				'mismatch_confirmed' => $confirm_mismatch && $mismatch,
			),
			200
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function find_audit_entry( int $post_id, string $audit_log_id ): ?array {
		foreach ( Outpost_Manual_Share_Audit_Log::get_entries( $post_id ) as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $audit_log_id ) {
				return $entry;
			}
		}
		return null;
	}
}
