<?php
/**
 * Outpost_Fetch_Recent_Whoop (G11b-consumer).
 *
 * Registers WHOOP as a fetch-recent provider per PR #57's primitive.
 * Users connected to WHOOP (PR #49) can pick a recent cycle (24-hour
 * strain period) or recovery (morning recovery score) from the
 * composer sidebar.
 *
 * Both kinds map to `note` post_kind — cycles and recoveries are
 * observational reflections, not active sessions.
 *
 * Per PR #49's locked decisions, WHOOP has no membership gate (the
 * existing API access for connected users is unconditional). Failures
 * surface as empty lists; the picker modal renders "No recent items
 * available" gracefully.
 *
 * @package Outpost
 * @since   0.1.88
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Fetch_Recent_Whoop {

	public const PROVIDER_ID = 'whoop';

	private const API_BASE = 'https://api.prod.whoop.com';

	private const FETCH_WINDOW_DAYS = 14;

	private const TIMEOUT_SECONDS = 10;

	/**
	 * @var (callable(string,string):mixed)|null
	 */
	private static $http_resolver = null;

	/**
	 * Override the HTTP resolver for testing.
	 *
	 * @since 0.1.88
	 *
	 * @param (callable(string,string):mixed)|null $resolver Closure receiving (url, token) → response array.
	 */
	public static function set_http_resolver_for_tests( ?callable $resolver ): void {
		self::$http_resolver = $resolver;
	}

	/**
	 * @since 0.1.88
	 */
	public static function register(): void {
		add_filter( 'outpost_fetch_recent_providers', array( __CLASS__, 'add_to_registry' ) );
	}

	/**
	 * @since 0.1.88
	 *
	 * @param array<string,array<string,mixed>> $providers Existing providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_to_registry( $providers ): array {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers[ self::PROVIDER_ID ] = array(
			'label'          => __( 'WHOOP', 'outpost-mobile-publishing' ),
			'callback'       => array( __CLASS__, 'fetch_items' ),
			'capability'     => 'publish_posts',
			'oauth_provider' => self::PROVIDER_ID,
		);
		return $providers;
	}

	/**
	 * @since 0.1.88
	 *
	 * @param int $count Max items to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fetch_items( int $count = 10 ): array {
		$count = max( 1, min( 50, $count ) );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$creds = Outpost_Credentials_Store::get( self::PROVIDER_ID, $user_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return array();
		}
		$token = (string) $creds['access_token'];

		$start_iso = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( self::FETCH_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$cycles     = self::fetch_cycles( $token, $start_iso );
		$recoveries = self::fetch_recoveries( $token, $start_iso );

		$items = array_merge(
			array_map( array( __CLASS__, 'map_cycle_item' ), $cycles ),
			array_map( array( __CLASS__, 'map_recovery_item' ), $recoveries )
		);

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$at = isset( $a['fetched_at'] ) ? (string) $a['fetched_at'] : '';
				$bt = isset( $b['fetched_at'] ) ? (string) $b['fetched_at'] : '';
				return strcmp( $bt, $at );
			}
		);

		return array_slice( $items, 0, $count );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_cycles( string $token, string $start_iso ): array {
		$response = self::api_get(
			'/developer/v2/cycle?start=' . rawurlencode( $start_iso ) . '&limit=25',
			$token
		);
		if ( ! is_array( $response ) || ! isset( $response['records'] ) || ! is_array( $response['records'] ) ) {
			return array();
		}
		return array_values( array_filter( $response['records'], 'is_array' ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_recoveries( string $token, string $start_iso ): array {
		$response = self::api_get(
			'/developer/v2/recovery?start=' . rawurlencode( $start_iso ) . '&limit=25',
			$token
		);
		if ( ! is_array( $response ) || ! isset( $response['records'] ) || ! is_array( $response['records'] ) ) {
			return array();
		}
		return array_values( array_filter( $response['records'], 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $cycle WHOOP cycle record.
	 * @return array<string,mixed>
	 */
	public static function map_cycle_item( array $cycle ): array {
		$score         = self::extract_score( $cycle, 'strain' );
		$score_rounded = $score > 0 ? round( $score, 1 ) : 0.0;
		$start_at      = isset( $cycle['start'] ) ? (string) $cycle['start'] : '';
		$id            = isset( $cycle['id'] ) ? (string) $cycle['id'] : md5( $start_at );

		$title = $score_rounded > 0
			? sprintf(
				/* translators: %s: strain score (X.X). */
				__( 'Cycle — Strain %s/21', 'outpost-mobile-publishing' ),
				(string) $score_rounded
			)
			: __( 'Cycle', 'outpost-mobile-publishing' );

		$content = '<p>' . esc_html( $title ) . '</p>';

		return array(
			'id'           => 'whoop-cycle-' . $id,
			'title'        => $title,
			'subtitle'     => '' !== $start_at ? self::short_date( $start_at ) : '',
			'icon_url'     => null,
			'fetched_at'   => '' !== $start_at ? $start_at : gmdate( 'c' ),
			'post_kind'    => 'note',
			'post_payload' => array(
				'title'                  => $title,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_whoop_cycle_id' => $id,
					'_outpost_whoop_strain'   => (string) $score_rounded,
					'_outpost_whoop_start_at' => $start_at,
				),
				'syndication_source_url' => null,
			),
		);
	}

	/**
	 * @param array<string,mixed> $recovery WHOOP recovery record.
	 * @return array<string,mixed>
	 */
	public static function map_recovery_item( array $recovery ): array {
		$score     = self::extract_score( $recovery, 'recovery_score' );
		$score_int = $score > 0 ? (int) round( $score ) : 0;
		$created   = isset( $recovery['created_at'] ) ? (string) $recovery['created_at'] : '';
		$cycle_id  = isset( $recovery['cycle_id'] ) ? (string) $recovery['cycle_id'] : '';

		$day = self::short_date( $created );

		$title = $score_int > 0
			? sprintf(
				/* translators: 1: date, 2: recovery percent. */
				__( 'Recovery — %1$s, %2$d%%', 'outpost-mobile-publishing' ),
				$day,
				$score_int
			)
			: sprintf(
				/* translators: %s: date. */
				__( 'Recovery — %s', 'outpost-mobile-publishing' ),
				$day
			);

		$content = '<p>' . esc_html( $title ) . '</p>';

		return array(
			'id'           => 'whoop-recovery-' . ( '' !== $cycle_id ? $cycle_id : md5( $created ) ),
			'title'        => $title,
			'subtitle'     => $day,
			'icon_url'     => null,
			'fetched_at'   => '' !== $created ? $created : gmdate( 'c' ),
			'post_kind'    => 'note',
			'post_payload' => array(
				'title'                  => $title,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_whoop_recovery_score' => (string) $score_int,
					'_outpost_whoop_cycle_id'       => $cycle_id,
					'_outpost_whoop_created_at'     => $created,
				),
				'syndication_source_url' => null,
			),
		);
	}

	/**
	 * Extract a score from WHOOP's nested response shape. Cycles have
	 * `score.strain`; recoveries have `score.recovery_score`. Both may
	 * appear at the top level on older API revisions.
	 *
	 * @param array<string,mixed> $record Cycle or recovery record.
	 */
	private static function extract_score( array $record, string $field ): float {
		if ( isset( $record['score'] ) && is_array( $record['score'] ) && isset( $record['score'][ $field ] ) ) {
			return (float) $record['score'][ $field ];
		}
		if ( isset( $record[ $field ] ) ) {
			return (float) $record[ $field ];
		}
		return 0.0;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function api_get( string $path, string $token ) {
		if ( null !== self::$http_resolver ) {
			return ( self::$http_resolver )( self::API_BASE . $path, $token );
		}
		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function short_date( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return gmdate( 'Y-m-d', $ts );
	}
}
