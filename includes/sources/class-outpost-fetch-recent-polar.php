<?php
/**
 * Outpost_Fetch_Recent_Polar (G11c-consumer).
 *
 * Registers Polar Flow as a fetch-recent provider per PR #57's
 * primitive. Users connected to Polar (PR #50) can pick a recent
 * training session from the composer sidebar.
 *
 * THE TRANSACTION MODEL
 *
 * Polar's AccessLink API uses a "transaction" model that's
 * non-standard for fetch-recent flows:
 *
 *   1. POST /v3/users/{user-id}/exercise-transactions to start a
 *      transaction → returns transaction-id.
 *   2. GET  /v3/users/{user-id}/exercise-transactions/{txn-id}
 *      → returns the list of exercise URLs available in that
 *      transaction.
 *   3. Optional: GET each exercise URL for full detail.
 *   4. Normally a consumer would PUT the transaction to commit it,
 *      after which Polar removes those exercises from the next
 *      transaction's window. We DO NOT commit — the picker only
 *      reads, never consumes.
 *
 * Consequence: exercises that the user already viewed via this
 * picker will keep appearing in subsequent transaction windows up
 * to Polar's 24-hour caching ceiling. That's fine for a picker —
 * "I saw this option last time too" is acceptable; "this option
 * vanished because I happened to glance at it" would be worse.
 *
 * The 24-hour staleness ceiling is documented in the docs page so
 * users / admins know what they're seeing.
 *
 * `after_token_exchange()` from PR #50 already handled the user
 * registration step. The fetch-recent callback assumes the user is
 * registered. If registration failed silently, exercise calls return
 * 404 — surfaces as an empty list, modal renders "No recent items
 * available."
 *
 * @package Outpost
 * @since   0.1.89
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Fetch_Recent_Polar {

	public const PROVIDER_ID = 'polar';

	private const API_BASE = 'https://www.polaraccesslink.com/v3';

	private const TIMEOUT_SECONDS = 10;

	/**
	 * Max exercise URLs to inspect per transaction window. Keeps the
	 * total number of HTTP roundtrips bounded — exercise list +
	 * per-exercise GETs.
	 */
	private const MAX_EXERCISES_PER_FETCH = 25;

	/**
	 * @var (callable(string,string,string,?string):mixed)|null
	 */
	private static $http_resolver = null;

	/**
	 * Override the HTTP resolver for testing.
	 *
	 * @since 0.1.89
	 *
	 * @param (callable(string,string,string,?string):mixed)|null $resolver Closure receiving (method, url, token, ?body) → response array.
	 */
	public static function set_http_resolver_for_tests( ?callable $resolver ): void {
		self::$http_resolver = $resolver;
	}

	/**
	 * @since 0.1.89
	 */
	public static function register(): void {
		add_filter( 'outpost_fetch_recent_providers', array( __CLASS__, 'add_to_registry' ) );
	}

	/**
	 * @since 0.1.89
	 *
	 * @param array<string,array<string,mixed>> $providers Existing providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_to_registry( $providers ): array {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers[ self::PROVIDER_ID ] = array(
			'label'          => __( 'Polar Flow', 'outpost-mobile-publishing' ),
			'callback'       => array( __CLASS__, 'fetch_items' ),
			'capability'     => 'publish_posts',
			'oauth_provider' => self::PROVIDER_ID,
		);
		return $providers;
	}

	/**
	 * @since 0.1.89
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

		// Step 1: POST to start a transaction. The Polar member-id is
		// the WP user_id (per PR #50's after_token_exchange registration).
		$txn = self::start_transaction( $token, $user_id );
		if ( null === $txn ) {
			return array();
		}

		// Step 2: GET the transaction to list exercise URLs.
		$exercise_urls = self::list_transaction_exercises( $token, $user_id, $txn );
		if ( empty( $exercise_urls ) ) {
			return array();
		}

		// Cap to MAX_EXERCISES_PER_FETCH. We'll then fetch each (capped
		// further at $count, which the caller enforces).
		$exercise_urls = array_slice( $exercise_urls, 0, self::MAX_EXERCISES_PER_FETCH );

		// Step 3: GET each exercise URL. For LIST display we deliberately
		// do NOT commit (PUT) the transaction — items remain in the
		// window for next time.
		$items = array();
		foreach ( $exercise_urls as $url ) {
			$exercise = self::fetch_exercise( $token, (string) $url );
			if ( null === $exercise ) {
				continue;
			}
			$items[] = self::map_exercise_item( $exercise, (string) $url );
			if ( count( $items ) >= $count ) {
				break;
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$at = isset( $a['fetched_at'] ) ? (string) $a['fetched_at'] : '';
				$bt = isset( $b['fetched_at'] ) ? (string) $b['fetched_at'] : '';
				return strcmp( $bt, $at );
			}
		);

		return $items;
	}

	/**
	 * @return string|null Transaction id, or null on failure.
	 */
	public static function start_transaction( string $token, int $user_id ): ?string {
		$response = self::api_request(
			'POST',
			self::API_BASE . '/users/' . rawurlencode( (string) $user_id ) . '/exercise-transactions',
			$token,
			null
		);
		if ( ! is_array( $response ) ) {
			return null;
		}
		// Polar returns either a JSON body with `transaction-id` or a
		// Location header pointing to the transaction. We accept the
		// JSON path; integration tests that exercise the redirect-only
		// path can extend this method later.
		if ( isset( $response['transaction-id'] ) ) {
			return (string) $response['transaction-id'];
		}
		// Some Polar revisions nest under a `transaction` key.
		if ( isset( $response['transaction']['id'] ) ) {
			return (string) $response['transaction']['id'];
		}
		return null;
	}

	/**
	 * @return array<int,string> Exercise URLs.
	 */
	public static function list_transaction_exercises( string $token, int $user_id, string $txn ): array {
		$response = self::api_request(
			'GET',
			self::API_BASE . '/users/' . rawurlencode( (string) $user_id ) . '/exercise-transactions/' . rawurlencode( $txn ),
			$token,
			null
		);
		if ( ! is_array( $response ) ) {
			return array();
		}
		// Polar returns `{ "exercises": [ url, url, ... ] }` or
		// `{ "exercises": [ { "url": "..." }, ... ] }` depending on revision.
		$urls = array();
		if ( isset( $response['exercises'] ) && is_array( $response['exercises'] ) ) {
			foreach ( $response['exercises'] as $entry ) {
				if ( is_string( $entry ) && '' !== $entry ) {
					$urls[] = $entry;
				} elseif ( is_array( $entry ) && isset( $entry['url'] ) && is_string( $entry['url'] ) ) {
					$urls[] = $entry['url'];
				}
			}
		}
		return $urls;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function fetch_exercise( string $token, string $url ): ?array {
		$response = self::api_request( 'GET', $url, $token, null );
		return is_array( $response ) ? $response : null;
	}

	/**
	 * @param array<string,mixed> $exercise Polar exercise record.
	 * @return array<string,mixed>
	 */
	public static function map_exercise_item( array $exercise, string $exercise_url ): array {
		$sport      = isset( $exercise['sport'] )
			? ucwords( strtolower( str_replace( '_', ' ', (string) $exercise['sport'] ) ) )
			: '';
		$start      = isset( $exercise['start-time'] ) ? (string) $exercise['start-time'] : '';
		$distance_m = isset( $exercise['distance'] ) ? (float) $exercise['distance'] : 0.0;
		$duration   = isset( $exercise['duration'] ) ? (string) $exercise['duration'] : ''; // ISO 8601 duration "PT45M30S".
		$id         = isset( $exercise['id'] ) ? (string) $exercise['id'] : md5( $exercise_url );

		$distance_km = $distance_m > 0 ? round( $distance_m / 1000, 1 ) : 0.0;

		if ( '' !== $sport && $distance_km > 0 ) {
			$title = sprintf(
				/* translators: 1: sport name, 2: distance in km. */
				__( 'Training — %1$s, %2$s km', 'outpost-mobile-publishing' ),
				$sport,
				(string) $distance_km
			);
		} elseif ( '' !== $sport ) {
			$duration_min = self::iso8601_duration_to_minutes( $duration );
			$title        = $duration_min > 0
				? sprintf(
					/* translators: 1: sport name, 2: duration in minutes. */
					__( 'Training — %1$s, %2$d min', 'outpost-mobile-publishing' ),
					$sport,
					$duration_min
				)
				: sprintf(
					/* translators: %s: sport name. */
					__( 'Training — %s', 'outpost-mobile-publishing' ),
					$sport
				);
		} else {
			$title = __( 'Training', 'outpost-mobile-publishing' );
		}

		$content = '<p>' . esc_html( $title ) . '</p>';

		return array(
			'id'           => 'polar-exercise-' . $id,
			'title'        => $title,
			'subtitle'     => '' !== $start ? self::short_date( $start ) : '',
			'icon_url'     => null,
			'fetched_at'   => '' !== $start ? $start : gmdate( 'c' ),
			'post_kind'    => 'workout',
			'post_payload' => array(
				'title'                  => $title,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_polar_exercise_id' => $id,
					'_outpost_polar_sport'       => $sport,
					'_outpost_polar_start_time'  => $start,
					'_outpost_polar_distance_m'  => (string) $distance_m,
					'_outpost_polar_duration'    => $duration,
				),
				'syndication_source_url' => null,
			),
		);
	}

	/**
	 * Generic Polar API request. Returns parsed JSON array or null on
	 * any non-2xx status.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function api_request( string $method, string $url, string $token, ?string $body ) {
		if ( null !== self::$http_resolver ) {
			return ( self::$http_resolver )( $method, $url, $token, $body );
		}
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}
		// Polar's POST /exercise-transactions returns 201 with the
		// transaction-id in the body; some revisions return 204 with
		// only a Location header. We only support the body case for
		// now (covers current production AccessLink behaviour).
		if ( '' === $response_body ) {
			return array();
		}
		$decoded = json_decode( $response_body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Parse an ISO 8601 duration string ("PT45M30S") into total minutes.
	 * Crude but sufficient for picker subtitles.
	 */
	private static function iso8601_duration_to_minutes( string $iso ): int {
		if ( '' === $iso || 0 !== strpos( $iso, 'PT' ) ) {
			return 0;
		}
		$total_seconds = 0;
		if ( preg_match( '/(\d+)H/', $iso, $m ) ) {
			$total_seconds += (int) $m[1] * 3600;
		}
		if ( preg_match( '/(\d+)M/', $iso, $m ) ) {
			$total_seconds += (int) $m[1] * 60;
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)S/', $iso, $m ) ) {
			$total_seconds += (int) round( (float) $m[1] );
		}
		return (int) round( $total_seconds / 60 );
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
