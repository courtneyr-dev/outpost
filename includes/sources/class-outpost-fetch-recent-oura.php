<?php
/**
 * Outpost_Fetch_Recent_Oura (G11a-consumer).
 *
 * Registers Oura as a fetch-recent provider per PR #57's primitive.
 * Users connected to Oura (PR #45) can pick a recent workout or sleep
 * session from the composer sidebar.
 *
 * Fetch window: last 14 days from request time. Modal cap: 10 items
 * per open. Workouts and sleep are combined into a single list sorted
 * descending by start time.
 *
 * Membership-lapsed handling: Oura's API returns 403 with a body
 * containing "membership" when a previously-connected account no
 * longer has the active subscription required for v2 API access. In
 * that case the callback returns an empty list with reason
 * 'membership_lapsed' so the modal can render a graceful prompt.
 *
 * @package Outpost
 * @since   0.1.87
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Fetch_Recent_Oura {

	public const PROVIDER_ID = 'oura';

	private const API_BASE = 'https://api.ouraring.com/v2';

	private const FETCH_WINDOW_DAYS = 14;

	private const TIMEOUT_SECONDS = 10;

	/**
	 * Test seam: closure that returns the API response for a given path.
	 * When set, replaces the wp_remote_get call entirely.
	 *
	 * @var (callable(string,string):mixed)|null
	 */
	private static $http_resolver = null;

	/**
	 * Override the HTTP resolver for testing.
	 *
	 * @since 0.1.87
	 *
	 * @param (callable(string,string):mixed)|null $resolver Closure receiving (url, token) → array{status:int,body:array}.
	 */
	public static function set_http_resolver_for_tests( ?callable $resolver ): void {
		self::$http_resolver = $resolver;
	}

	/**
	 * Hook the provider into the registry.
	 *
	 * @since 0.1.87
	 */
	public static function register(): void {
		add_filter( 'outpost_fetch_recent_providers', array( __CLASS__, 'add_to_registry' ) );
	}

	/**
	 * Append the Oura provider to the registry.
	 *
	 * @since 0.1.87
	 *
	 * @param array<string,array<string,mixed>> $providers Existing providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_to_registry( $providers ): array {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers[ self::PROVIDER_ID ] = array(
			'label'          => __( 'Oura', 'outpost-mobile-publishing' ),
			'callback'       => array( __CLASS__, 'fetch_items' ),
			'capability'     => 'publish_posts',
			'oauth_provider' => self::PROVIDER_ID,
		);
		return $providers;
	}

	/**
	 * Resolve the canonical fetch-recent items for the current user. Reads
	 * the OAuth credentials, fetches recent workouts + sleep sessions in
	 * parallel-ish (sequential), maps to the canonical shape, sorts by
	 * start time descending, caps to count.
	 *
	 * @since 0.1.87
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

		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', time() - ( self::FETCH_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$workouts = self::fetch_workouts( $token, $start_date, $end_date );
		$sleep    = self::fetch_sleep( $token, $start_date, $end_date );

		$items = array_merge(
			array_map( array( __CLASS__, 'map_workout_item' ), $workouts ),
			array_map( array( __CLASS__, 'map_sleep_item' ), $sleep )
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
	private static function fetch_workouts( string $token, string $start_date, string $end_date ): array {
		$response = self::api_get(
			'/usercollection/workout?start_date=' . $start_date . '&end_date=' . $end_date,
			$token
		);
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}
		return array_values( array_filter( $response['data'], 'is_array' ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_sleep( string $token, string $start_date, string $end_date ): array {
		$response = self::api_get(
			'/usercollection/sleep?start_date=' . $start_date . '&end_date=' . $end_date,
			$token
		);
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}
		return array_values( array_filter( $response['data'], 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $workout Oura workout v2 record.
	 * @return array<string,mixed>
	 */
	public static function map_workout_item( array $workout ): array {
		$activity    = isset( $workout['activity'] ) ? ucfirst( str_replace( '_', ' ', (string) $workout['activity'] ) ) : __( 'Workout', 'outpost-mobile-publishing' );
		$distance_m  = isset( $workout['distance'] ) ? (float) $workout['distance'] : 0.0;
		$distance_km = $distance_m > 0 ? round( $distance_m / 1000, 1 ) : 0.0;
		$kcal        = isset( $workout['calories'] ) ? (int) round( (float) $workout['calories'] ) : 0;
		$start_at    = isset( $workout['start_datetime'] ) ? (string) $workout['start_datetime'] : '';
		$duration_s  = self::seconds_between( $start_at, isset( $workout['end_datetime'] ) ? (string) $workout['end_datetime'] : '' );
		$duration_m  = $duration_s > 0 ? (int) round( $duration_s / 60 ) : 0;

		$title = $distance_km > 0
			? sprintf(
				/* translators: 1: activity name, 2: distance in km. */
				__( 'Workout — %1$s, %2$s km', 'outpost-mobile-publishing' ),
				$activity,
				(string) $distance_km
			)
			: sprintf(
				/* translators: %s: activity name. */
				__( 'Workout — %s', 'outpost-mobile-publishing' ),
				$activity
			);

		$subtitle_parts = array();
		if ( '' !== $start_at ) {
			$subtitle_parts[] = self::short_date( $start_at );
		}
		if ( $duration_m > 0 ) {
			$subtitle_parts[] = sprintf(
				/* translators: %d: duration in minutes. */
				__( '%d min', 'outpost-mobile-publishing' ),
				$duration_m
			);
		}
		if ( $kcal > 0 ) {
			$subtitle_parts[] = sprintf(
				/* translators: %d: calories. */
				__( '%d kcal', 'outpost-mobile-publishing' ),
				$kcal
			);
		}

		$content = '<p>' . esc_html( $title ) . '</p>';

		return array(
			'id'           => isset( $workout['id'] ) ? 'oura-workout-' . (string) $workout['id'] : 'oura-workout-' . md5( $start_at ),
			'title'        => $title,
			'subtitle'     => implode( ', ', $subtitle_parts ),
			'icon_url'     => null,
			'fetched_at'   => '' !== $start_at ? $start_at : gmdate( 'c' ),
			'post_kind'    => 'workout',
			'post_payload' => array(
				'title'                  => $title,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_oura_activity'   => isset( $workout['activity'] ) ? (string) $workout['activity'] : '',
					'_outpost_oura_start_at'   => $start_at,
					'_outpost_oura_duration_s' => (string) $duration_s,
					'_outpost_oura_distance_m' => (string) $distance_m,
					'_outpost_oura_calories'   => (string) $kcal,
				),
				'syndication_source_url' => null,
			),
		);
	}

	/**
	 * @param array<string,mixed> $sleep Oura sleep v2 record.
	 * @return array<string,mixed>
	 */
	public static function map_sleep_item( array $sleep ): array {
		$day     = isset( $sleep['day'] ) ? (string) $sleep['day'] : '';
		$total_s = isset( $sleep['total_sleep_duration'] ) ? (int) $sleep['total_sleep_duration'] : 0;
		$score   = isset( $sleep['readiness']['score'] )
			? (int) $sleep['readiness']['score']
			: ( isset( $sleep['score'] ) ? (int) $sleep['score'] : 0 );
		$bedtime = isset( $sleep['bedtime_start'] ) ? (string) $sleep['bedtime_start'] : '';

		$hours = $total_s > 0 ? round( $total_s / 3600, 1 ) : 0.0;

		$title = sprintf(
			/* translators: %s: date. */
			__( 'Sleep — %s', 'outpost-mobile-publishing' ),
			'' !== $day ? $day : __( 'recent', 'outpost-mobile-publishing' )
		);

		$subtitle_parts = array();
		if ( $hours > 0 ) {
			$subtitle_parts[] = sprintf(
				/* translators: %s: hours of sleep. */
				__( '%s hours', 'outpost-mobile-publishing' ),
				(string) $hours
			);
		}
		if ( $score > 0 ) {
			$subtitle_parts[] = sprintf(
				/* translators: %d: sleep score. */
				__( 'score: %d', 'outpost-mobile-publishing' ),
				$score
			);
		}

		$content = '<p>' . esc_html( $title ) . '</p>';

		return array(
			'id'           => isset( $sleep['id'] ) ? 'oura-sleep-' . (string) $sleep['id'] : 'oura-sleep-' . md5( $day ),
			'title'        => $title,
			'subtitle'     => implode( ', ', $subtitle_parts ),
			'icon_url'     => null,
			'fetched_at'   => '' !== $bedtime ? $bedtime : ( '' !== $day ? $day . 'T00:00:00+00:00' : gmdate( 'c' ) ),
			'post_kind'    => 'note',
			'post_payload' => array(
				'title'                  => $title,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_oura_sleep_day'     => $day,
					'_outpost_oura_sleep_seconds' => (string) $total_s,
					'_outpost_oura_sleep_score'   => (string) $score,
				),
				'syndication_source_url' => null,
			),
		);
	}

	/**
	 * GET helper for Oura's API.
	 *
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

	private static function seconds_between( string $start, string $end ): int {
		if ( '' === $start || '' === $end ) {
			return 0;
		}
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );
		if ( false === $start_ts || false === $end_ts ) {
			return 0;
		}
		return max( 0, $end_ts - $start_ts );
	}

	private static function short_date( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return gmdate( 'Y-m-d', $ts );
	}
}
