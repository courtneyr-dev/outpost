<?php
/**
 * Outpost_Source_Rwg (G12a-source).
 *
 * URL-paste consumer for the Ride With GPS OAuth provider (PR #46).
 * When a user shares an RWG trip or route URL into the composer,
 * this source reads the trip/route via authenticated API calls and
 * returns the canonical extracted shape.
 *
 * URL forms claimed:
 *
 *   - https://ridewithgps.com/trips/<id>
 *   - https://ridewithgps.com/routes/<id>
 *   - With or without `www.` and `.json` suffix.
 *
 * post_kind:
 *
 *   - Trip → 'workout' (an actual ridden activity).
 *   - Route → 'note' (a planned ride).
 *
 * Privacy boundary: trips and routes have a `visibility` field. Values
 * "everyone" and "public_search" are public. "private" or "friends"
 * are treated as not-shareable: the source returns extracted: false +
 * reason: 'private' rather than partial data. The OAuth provider's
 * docblock notes the same rule for future source implementations.
 *
 * Distance / elevation: RWG returns metric. The composer renders both
 * metric (source-of-truth) and imperial (computed). Conversions never
 * round the metric source.
 *
 * @package Outpost
 * @since   0.1.85
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Rwg extends Outpost_Source_Base {

	public const ID = 'ridewithgps';

	private const API_BASE = 'https://ridewithgps.com/api/v1';

	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const CACHE_PREFIX = 'outpost_rwg_';

	private const TIMEOUT_SECONDS = 10;

	/**
	 * Public visibility values RWG returns for trips/routes that are
	 * shareable. Anything not in this list is treated as private —
	 * we refuse to surface user-restricted data even when the OAuth
	 * token has access.
	 *
	 * @var string[]
	 */
	private const PUBLIC_VISIBILITY_VALUES = array( 'everyone', 'public_search', 'public' );

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Ride With GPS', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'ridewithgps.com', 'www.ridewithgps.com' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'workout',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'api_json',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'@source_url' => 'u-syndication',
			),
			'h_entry_property' => 'u-syndication',
			'auth_required'    => true,
			'tags_default'     => array( 'cycling' ),
			'caveats'          => array(
				__( 'Private trips and routes ("friends" or "private" visibility) are not surfaced even when the connected user has access.', 'outpost-mobile-publishing' ),
				__( 'The connected Ride With GPS account must be the owner or have read access to the trip or route.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Match RWG trip and route URLs.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		return null !== self::parse_url_target( $url );
	}

	/**
	 * Parse an RWG URL into a structured target. Returns null when the
	 * URL doesn't match a trip or route on a recognized host.
	 *
	 * @since 0.1.85
	 *
	 * @param string $url URL the user shared.
	 * @return array{kind: string, id: int}|null
	 */
	public static function parse_url_target( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, array( 'ridewithgps.com', 'www.ridewithgps.com' ), true ) ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( preg_match( '#^/trips/(\d+)(?:\.json)?/?$#', $path, $m ) ) {
			return array(
				'kind' => 'trip',
				'id'   => (int) $m[1],
			);
		}
		if ( preg_match( '#^/routes/(\d+)(?:\.json)?/?$#', $path, $m ) ) {
			return array(
				'kind' => 'route',
				'id'   => (int) $m[1],
			);
		}
		return null;
	}

	/**
	 * Fetch the trip or route from RWG's API. Returns the canonical
	 * extracted shape on success or a structured failure with a
	 * `reason` code on any non-success path (private, auth_failed,
	 * transport_failed, not_found, parse_failed).
	 *
	 * @since 0.1.85
	 *
	 * @param string $url     URL the user shared.
	 * @param int    $user_id WP user whose RWG OAuth credentials to use.
	 * @return array<string,mixed> Canonical shape with `extracted` key.
	 */
	public static function fetch( string $url, int $user_id ): array {
		$target = self::parse_url_target( $url );
		if ( null === $target ) {
			return self::failure( 'invalid_url' );
		}

		$cache_key = self::CACHE_PREFIX . $target['kind'] . '_' . $target['id'];
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$creds = Outpost_Credentials_Store::get( self::ID, $user_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return self::failure( 'not_connected' );
		}

		$path = sprintf( '/%ss/%d.json', $target['kind'], $target['id'] );
		$body = self::api_get( $path, (string) $creds['access_token'] );
		if ( isset( $body['extracted'] ) && false === $body['extracted'] ) {
			// api_get returned a normalized failure; surface as-is.
			return $body;
		}

		// RWG wraps single-object responses under the kind name (`trip`
		// or `route`). The actual fields live one level down.
		$payload = isset( $body[ $target['kind'] ] ) && is_array( $body[ $target['kind'] ] )
			? $body[ $target['kind'] ]
			: $body;

		if ( ! self::is_publicly_visible( $payload ) ) {
			return self::failure( 'private' );
		}

		$result = ( 'trip' === $target['kind'] )
			? self::project_trip( $payload, $url )
			: self::project_route( $payload, $url );

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Map an RWG trip payload to the canonical extracted shape.
	 *
	 * @param array<string,mixed> $trip      RWG trip object.
	 * @param string              $source_url URL the user shared.
	 * @return array<string,mixed>
	 */
	private static function project_trip( array $trip, string $source_url ): array {
		$name             = isset( $trip['name'] ) ? (string) $trip['name'] : __( 'Ride', 'outpost-mobile-publishing' );
		$distance_meters  = isset( $trip['distance'] ) ? (float) $trip['distance'] : 0.0;
		$elevation_gain_m = isset( $trip['elevation_gain'] ) ? (float) $trip['elevation_gain'] : 0.0;
		$description      = isset( $trip['description'] ) ? (string) $trip['description'] : '';
		$departed_at      = isset( $trip['departed_at'] ) ? (string) $trip['departed_at'] : '';

		$summary = self::format_summary_line( $distance_meters, $elevation_gain_m );
		$content = '<p>' . esc_html( $summary ) . '</p>';
		if ( '' !== $description ) {
			$content .= "\n<p>" . esc_html( $description ) . '</p>';
		}
		$content .= "\n<p><a href=\"" . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></p>';

		return array(
			'extracted'        => true,
			'kind'             => 'trip',
			'title'            => $name,
			'content'          => $content,
			'post_kind'        => 'workout',
			'post_payload'     => array(
				'title'                  => $name,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_rwg_distance_meters'  => (string) $distance_meters,
					'_outpost_rwg_elevation_meters' => (string) $elevation_gain_m,
					'_outpost_rwg_departed_at'      => $departed_at,
				),
				'syndication_source_url' => $source_url,
			),
			'fetched_at'       => gmdate( 'c' ),
			'distance_meters'  => $distance_meters,
			'distance_km'      => round( $distance_meters / 1000, 2 ),
			'distance_miles'   => round( ( $distance_meters / 1000 ) * 0.621371, 2 ),
			'elevation_meters' => $elevation_gain_m,
			'elevation_feet'   => round( $elevation_gain_m * 3.28084, 0 ),
			'departed_at'      => $departed_at,
			'source_url'       => $source_url,
		);
	}

	/**
	 * Map an RWG route payload to the canonical extracted shape.
	 *
	 * @param array<string,mixed> $route      RWG route object.
	 * @param string              $source_url URL the user shared.
	 * @return array<string,mixed>
	 */
	private static function project_route( array $route, string $source_url ): array {
		$name             = isset( $route['name'] ) ? (string) $route['name'] : __( 'Route', 'outpost-mobile-publishing' );
		$distance_meters  = isset( $route['distance'] ) ? (float) $route['distance'] : 0.0;
		$elevation_gain_m = isset( $route['elevation_gain'] ) ? (float) $route['elevation_gain'] : 0.0;
		$description      = isset( $route['description'] ) ? (string) $route['description'] : '';

		$summary = sprintf(
			/* translators: 1: distance summary, 2: elevation summary. */
			__( 'Planned route: %1$s, %2$s', 'outpost-mobile-publishing' ),
			self::format_distance( $distance_meters ),
			self::format_elevation( $elevation_gain_m )
		);
		$content = '<p>' . esc_html( $summary ) . '</p>';
		if ( '' !== $description ) {
			$content .= "\n<p>" . esc_html( $description ) . '</p>';
		}
		$content .= "\n<p><a href=\"" . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></p>';

		return array(
			'extracted'        => true,
			'kind'             => 'route',
			'title'            => $name,
			'content'          => $content,
			'post_kind'        => 'note',
			'post_payload'     => array(
				'title'                  => $name,
				'content'                => $content,
				'post_meta'              => array(
					'_outpost_rwg_distance_meters'  => (string) $distance_meters,
					'_outpost_rwg_elevation_meters' => (string) $elevation_gain_m,
				),
				'syndication_source_url' => $source_url,
			),
			'fetched_at'       => gmdate( 'c' ),
			'distance_meters'  => $distance_meters,
			'distance_km'      => round( $distance_meters / 1000, 2 ),
			'distance_miles'   => round( ( $distance_meters / 1000 ) * 0.621371, 2 ),
			'elevation_meters' => $elevation_gain_m,
			'elevation_feet'   => round( $elevation_gain_m * 3.28084, 0 ),
			'source_url'       => $source_url,
		);
	}

	/**
	 * @param array<string,mixed> $payload Trip or route fields.
	 */
	private static function is_publicly_visible( array $payload ): bool {
		$visibility = isset( $payload['visibility'] ) ? strtolower( (string) $payload['visibility'] ) : '';
		if ( '' === $visibility ) {
			// Some RWG endpoints omit the field on public-by-default
			// records. Default to public when absent.
			return true;
		}
		return in_array( $visibility, self::PUBLIC_VISIBILITY_VALUES, true );
	}

	/**
	 * GET helper for the RWG API.
	 *
	 * @return array<string,mixed>
	 */
	private static function api_get( string $path, string $token ): array {
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
			return self::failure( 'transport_failed' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status || 403 === $status ) {
			return self::failure( 'auth_failed' );
		}
		if ( 404 === $status ) {
			return self::failure( 'not_found' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::failure( 'transport_failed' );
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return self::failure( 'parse_failed' );
		}
		return $decoded;
	}

	/**
	 * Build the human summary line for a trip:
	 * "Ride With GPS — 45.2 km / 28.1 mi, 580 m / 1903 ft elevation gain".
	 */
	private static function format_summary_line( float $distance_meters, float $elevation_meters ): string {
		return sprintf(
			/* translators: 1: distance summary (metric + imperial), 2: elevation summary. */
			__( 'Ride With GPS — %1$s, %2$s elevation gain', 'outpost-mobile-publishing' ),
			self::format_distance( $distance_meters ),
			self::format_elevation( $elevation_meters )
		);
	}

	private static function format_distance( float $meters ): string {
		$km    = round( $meters / 1000, 1 );
		$miles = round( ( $meters / 1000 ) * 0.621371, 1 );
		return sprintf(
			/* translators: 1: distance in km, 2: distance in miles. */
			__( '%1$s km / %2$s mi', 'outpost-mobile-publishing' ),
			(string) $km,
			(string) $miles
		);
	}

	private static function format_elevation( float $meters ): string {
		$feet = (int) round( $meters * 3.28084 );
		return sprintf(
			/* translators: 1: elevation in m, 2: elevation in ft. */
			__( '%1$d m / %2$d ft', 'outpost-mobile-publishing' ),
			(int) round( $meters ),
			$feet
		);
	}

	/**
	 * @return array{extracted: false, reason: string}
	 */
	private static function failure( string $reason ): array {
		return array(
			'extracted' => false,
			'reason'    => $reason,
		);
	}
}
