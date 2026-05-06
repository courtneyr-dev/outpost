<?php
/**
 * Outpost_Apple_Music_Adapter
 *
 * G4b composite-primitive demonstration. Composes:
 *
 *   - Primary: Outpost_Og_Inbound on the Apple Music share URL
 *     (returns the standard 9-key OG response with title / image /
 *     description / site_name from Apple's canonical page).
 *
 *   - Enricher: iTunes Lookup API call keyed on the album / song id
 *     parsed from the URL. Returns higher-resolution artwork (1000×1000
 *     vs ~600×600 from og:image), genre, track count, primary artist,
 *     country code, and the canonical iTunes URL.
 *
 * Merged via Composite_Inbound's `deep_merge` strategy: primary values
 * win for keys both sides emit, enricher fills gaps. The composite
 * meta block (`_composite_meta`) records which sources ran and how
 * long each took.
 *
 * iTunes Lookup is a public anonymous API:
 *   https://itunes.apple.com/lookup?id={id}&country={country}
 *
 * No auth, no rate-limit advertised. We cap timeouts at 5 seconds for
 * the enricher per Composite_Inbound's per-source timeout convention.
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Apple_Music_Adapter {

	private const ITUNES_LOOKUP_URL = 'https://itunes.apple.com/lookup';

	private const ITUNES_TIMEOUT = 5;

	/**
	 * Fetch Apple Music metadata for a share URL via the composite
	 * primitive.
	 *
	 * @since 0.1.70
	 *
	 * @param string              $url  Apple Music URL.
	 * @param array<string,mixed> $args Optional. `force_refresh` bypasses cache.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function fetch( string $url, array $args = array() ) {
		$identity = self::parse_url_identity( $url );
		if ( null === $identity ) {
			return new WP_Error(
				'outpost_apple_music_invalid_url',
				__( 'URL is not an Apple Music album, song, or playlist URL.', 'outpost' ),
				array( 'url' => $url )
			);
		}

		$sources = array(
			array(
				'id'       => 'apple_music_og',
				'role'     => 'primary',
				'callback' => static function () use ( $url, $args ) {
					return Outpost_Og_Inbound::fetch( $url, $args );
				},
			),
			array(
				'id'       => 'itunes_lookup',
				'role'     => 'enrich',
				'timeout'  => self::ITUNES_TIMEOUT,
				'callback' => static function () use ( $identity ) {
					return self::fetch_itunes_lookup( $identity );
				},
			),
		);

		return Outpost_Composite_Inbound::fetch( $url, $sources, $args );
	}

	/**
	 * Parse an Apple Music share URL into the parts iTunes Lookup needs.
	 * Returns null when the URL doesn't match the expected shape.
	 *
	 * Supported shapes:
	 *   - https://music.apple.com/{cc}/album/{slug}/{album-id}
	 *   - https://music.apple.com/{cc}/album/{slug}/{album-id}?i={track-id}
	 *   - https://music.apple.com/{cc}/song/{slug}/{song-id}
	 *
	 * @param string $url Apple Music URL.
	 * @return array{country: string, kind: string, id: string}|null
	 */
	public static function parse_url_identity( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['path'] ) ) {
			return null;
		}
		if ( 'music.apple.com' !== strtolower( $parts['host'] ) ) {
			return null;
		}
		$path = trim( (string) $parts['path'], '/' );
		// Match path shape: country/kind/slug/numeric-id.
		if ( ! preg_match( '~^([a-z]{2})/(album|song|playlist)/[^/]+/(\d+)$~i', $path, $m ) ) {
			return null;
		}
		$country = strtolower( $m[1] );
		$kind    = strtolower( $m[2] );
		$id      = $m[3];

		// `?i={track-id}` upgrades an album URL to a track lookup.
		if ( 'album' === $kind && isset( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			if ( isset( $query['i'] ) && is_string( $query['i'] ) && preg_match( '/^\d+$/', $query['i'] ) ) {
				$kind = 'song';
				$id   = $query['i'];
			}
		}

		return array(
			'country' => $country,
			'kind'    => $kind,
			'id'      => $id,
		);
	}

	/**
	 * Hit iTunes Lookup and return a normalised enrichment array.
	 *
	 * @param array{country: string, kind: string, id: string} $identity Parsed identity.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function fetch_itunes_lookup( array $identity ) {
		$endpoint = add_query_arg(
			array(
				'id'      => $identity['id'],
				'country' => $identity['country'],
			),
			self::ITUNES_LOOKUP_URL
		);
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout' => self::ITUNES_TIMEOUT,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'outpost_itunes_lookup_http',
				sprintf( /* translators: %d: HTTP status */ __( 'iTunes Lookup HTTP %d.', 'outpost' ), $status ),
				array( 'status' => $status )
			);
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['results'][0] ) || ! is_array( $decoded['results'][0] ) ) {
			return new WP_Error(
				'outpost_itunes_lookup_empty',
				__( 'iTunes Lookup returned no results.', 'outpost' )
			);
		}
		return self::shape_itunes_result( $decoded['results'][0] );
	}

	/**
	 * Project an iTunes Lookup result into a shape that merges cleanly
	 * with Og_Inbound's response. Keys not in the OG response shape get
	 * passed through; keys that overlap are filled only when OG's value
	 * is empty (handled by Composite_Inbound's deep_merge strategy).
	 *
	 * Notable transformation: iTunes returns `artworkUrl100` at 100×100
	 * but the URL pattern lets you swap the dimensions. We rewrite to
	 * 1000×1000 for richer cover art.
	 *
	 * @param array<string,mixed> $result Raw iTunes result.
	 * @return array<string,mixed>
	 */
	private static function shape_itunes_result( array $result ): array {
		$artwork_high_res = '';
		if ( isset( $result['artworkUrl100'] ) && is_string( $result['artworkUrl100'] ) ) {
			$artwork_high_res = str_replace( '100x100bb', '1000x1000bb', (string) $result['artworkUrl100'] );
		}
		return array(
			'itunes_artwork_high_res'    => $artwork_high_res,
			'itunes_artist_name'         => isset( $result['artistName'] ) ? (string) $result['artistName'] : '',
			'itunes_collection_name'     => isset( $result['collectionName'] ) ? (string) $result['collectionName'] : '',
			'itunes_track_name'          => isset( $result['trackName'] ) ? (string) $result['trackName'] : '',
			'itunes_track_count'         => isset( $result['trackCount'] ) && is_numeric( $result['trackCount'] )
				? (int) $result['trackCount']
				: null,
			'itunes_genre'               => isset( $result['primaryGenreName'] ) ? (string) $result['primaryGenreName'] : '',
			'itunes_country'             => isset( $result['country'] ) ? (string) $result['country'] : '',
			'itunes_release_date'        => isset( $result['releaseDate'] ) ? (string) $result['releaseDate'] : '',
			'itunes_collection_view_url' => isset( $result['collectionViewUrl'] ) ? (string) $result['collectionViewUrl'] : '',
			'itunes_track_view_url'      => isset( $result['trackViewUrl'] ) ? (string) $result['trackViewUrl'] : '',
		);
	}
}
