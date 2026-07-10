<?php
/**
 * Outpost_Manual_Share_Status_Controller
 *
 * REST endpoints for the F13 audit-log surfacing layer:
 *
 *   GET  /wp-json/outpost/v1/manual-share/status/{post_id}
 *   GET  /wp-json/outpost/v1/manual-share/pending-summary
 *   POST /wp-json/outpost/v1/manual-share/dismiss-reminder
 *   POST /wp-json/outpost/v1/manual-share/snooze-all
 *
 * Read-only except for the two POST endpoints, both of which mutate
 * `reminder_dismissed_until` on existing audit log entries (no
 * destructive operations on the log itself).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Status_Controller {

	private const ROUTE_NAMESPACE       = 'outpost/v1';
	private const ROUTE_STATUS          = '/manual-share/status/(?P<post_id>\d+)';
	private const ROUTE_PENDING_SUMMARY = '/manual-share/pending-summary';
	private const ROUTE_DISMISS         = '/manual-share/dismiss-reminder';
	private const ROUTE_SNOOZE_ALL      = '/manual-share/snooze-all';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_STATUS,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_status_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PENDING_SUMMARY,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_pending_summary_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_DISMISS,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_dismiss_request' ),
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
					'until'        => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_SNOOZE_ALL,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_snooze_all_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'until' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
		/**
		 * Override the manual-share status endpoint permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_manual_share_status_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost manual-share status requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	private static function has_bearer_header(): bool {
		$header = Outpost_Request_Headers::authorization();
		return '' !== $header && 1 === preg_match( '/^\s*Bearer\s+\S+/i', $header );
	}

	/**
	 * GET /status/{post_id} — full audit log + summary + status string.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_status_request( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post_id', __( 'A positive post_id is required.', 'outpost' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden_post', __( 'You do not have permission to view this post\'s syndication.', 'outpost' ), array( 'status' => 403 ) );
		}

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( $post_id );
		$status  = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$summary = Outpost_Manual_Share_Status_Computer::summarize_entries( $entries );

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'status'  => $status,
				'summary' => $summary,
				'entries' => $entries,
			),
			200
		);
	}

	/**
	 * GET /pending-summary — aggregate count + posts list for the rollup UI.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_pending_summary_request( $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'outpost' ), array( 'status' => 401 ) );
		}

		$pending = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( $user_id );

		// Compute aggregate count + oldest-fired-at across all visible
		// entries. Detector already filtered for visibility (snoozed
		// entries dropped).
		$total           = 0;
		$oldest_fired_ts = null;
		foreach ( $pending as $row ) {
			foreach ( $row['entries'] as $entry ) {
				++$total;
				$ts = isset( $entry['fired_at'] ) && is_string( $entry['fired_at'] )
					? strtotime( $entry['fired_at'] )
					: false;
				if ( false !== $ts && ( null === $oldest_fired_ts || $ts < $oldest_fired_ts ) ) {
					$oldest_fired_ts = $ts;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'count'           => $total,
				'oldest_fired_at' => null === $oldest_fired_ts ? null : gmdate( 'c', $oldest_fired_ts ),
				'posts'           => $pending,
				'can_snooze_all'  => Outpost_Manual_Share_Reminder_Manager::can_snooze_all( $user_id ),
			),
			200
		);
	}

	/**
	 * POST /dismiss-reminder — snooze a single entry.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_dismiss_request( $request ) {
		$post_id          = (int) $request->get_param( 'post_id' );
		$audit_log_id_raw = $request->get_param( 'audit_log_id' );
		$until_raw        = $request->get_param( 'until' );

		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_post_id', __( 'A positive post_id is required.', 'outpost' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden_post', __( 'You do not have permission to update this post\'s syndication.', 'outpost' ), array( 'status' => 403 ) );
		}

		$audit_log_id = is_string( $audit_log_id_raw ) ? trim( $audit_log_id_raw ) : '';
		if ( '' === $audit_log_id ) {
			return new WP_Error( 'invalid_audit_log_id', __( 'audit_log_id is required.', 'outpost' ), array( 'status' => 400 ) );
		}

		$until_str = is_string( $until_raw ) ? trim( $until_raw ) : '';
		$resolved  = Outpost_Manual_Share_Reminder_Manager::resolve_until( $until_str );
		if ( null === $resolved ) {
			return new WP_Error( 'invalid_until', __( 'Invalid snooze duration. Use a SNOOZE_* duration or a valid date/time string.', 'outpost' ), array( 'status' => 400 ) );
		}

		$updated = Outpost_Manual_Share_Audit_Log::update_entry(
			$post_id,
			$audit_log_id,
			array( 'reminder_dismissed_until' => $resolved )
		);
		if ( ! $updated ) {
			return new WP_Error( 'audit_log_entry_not_found', __( 'No matching audit log entry.', 'outpost' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'status'                   => 'snoozed',
				'audit_log_id'             => $audit_log_id,
				'reminder_dismissed_until' => $resolved,
			),
			200
		);
	}

	/**
	 * POST /snooze-all — bulk-snooze every visible pending entry for
	 * the requesting user. Rate-limited via {@see Outpost_Manual_Share_Reminder_Manager}.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_snooze_all_request( $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'outpost' ), array( 'status' => 401 ) );
		}
		if ( ! Outpost_Manual_Share_Reminder_Manager::can_snooze_all( $user_id ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Snooze-all was used recently; try again in a few minutes.', 'outpost' ),
				array(
					'status'      => 429,
					'retry_after' => 300,
				)
			);
		}

		$until_raw = $request->get_param( 'until' );
		$until_str = is_string( $until_raw ) ? trim( $until_raw ) : '';
		$resolved  = Outpost_Manual_Share_Reminder_Manager::resolve_until( $until_str );
		if ( null === $resolved ) {
			return new WP_Error( 'invalid_until', __( 'Invalid snooze duration.', 'outpost' ), array( 'status' => 400 ) );
		}

		$pending = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user( $user_id );
		$snoozed = 0;
		foreach ( $pending as $row ) {
			$post_id = (int) $row['post_id'];
			foreach ( $row['entries'] as $entry ) {
				$audit_log_id = (string) ( $entry['id'] ?? '' );
				if ( '' === $audit_log_id ) {
					continue;
				}
				$ok = Outpost_Manual_Share_Audit_Log::update_entry(
					$post_id,
					$audit_log_id,
					array( 'reminder_dismissed_until' => $resolved )
				);
				if ( $ok ) {
					++$snoozed;
				}
			}
		}

		Outpost_Manual_Share_Reminder_Manager::record_snooze_all( $user_id );

		return new WP_REST_Response(
			array(
				'status'                   => 'snoozed_all',
				'count'                    => $snoozed,
				'reminder_dismissed_until' => $resolved,
			),
			200
		);
	}
}
