<?php
/**
 * Outpost_Source_Ravelry (G14b-source).
 *
 * URL-paste consumer for the Ravelry OAuth provider (PR #47). Reads
 * pattern and project URLs and produces a canonical extracted shape
 * with rich knit/crochet metadata.
 *
 * URL forms claimed:
 *
 *   - https://www.ravelry.com/patterns/library/<slug>
 *   - https://www.ravelry.com/projects/<username>/<slug>
 *
 * post_kind:
 *
 *   - Pattern → 'note' ("I'm going to make this")
 *   - Project → 'note' ("I'm making / made this")
 *
 * Both flavors carry rich metadata: gauge, yardage, fiber types,
 * needles/hooks (patterns); plus status, started/completed dates,
 * modifications (projects). The primary photo flows into
 * `post_payload.featured_image_url`.
 *
 * Privacy boundary: Ravelry projects can be marked private. Same rule
 * as RWG — the source returns extracted: false + reason: 'private'
 * even when the OAuth token has access.
 *
 * Q3/Q4 (.overnight-questions.md): Ravelry's API docs at
 * https://www.ravelry.com/api are login-gated. The OAuth provider's
 * scope defaults (G14b) are tracked separately and overridable via
 * the existing `outpost_oauth_provider_ravelry_scopes` filter. This
 * source class does not assume any scope-only data; it reads the
 * standard pattern / project fields available to any authenticated
 * read.
 *
 * @package Outpost
 * @since   0.1.86
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Ravelry extends Outpost_Source_Base {

	public const ID = 'ravelry';

	private const API_BASE = 'https://api.ravelry.com';

	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const CACHE_PREFIX = 'outpost_ravelry_';

	private const TIMEOUT_SECONDS = 10;

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Ravelry', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'ravelry.com', 'www.ravelry.com' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'note',
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
			'tags_default'     => array( 'fiber-arts' ),
			'caveats'          => array(
				__( 'Private projects are not surfaced even when the connected user has access.', 'outpost-mobile-publishing' ),
				__( 'The connected Ravelry account must have read access to the pattern or project.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		return null !== self::parse_url_target( $url );
	}

	/**
	 * Parse a Ravelry URL into a structured target. Returns null when the
	 * URL doesn't match a pattern or project on a recognized host.
	 *
	 * @since 0.1.86
	 *
	 * @return array{kind: string, slug: string, username?: string}|null
	 */
	public static function parse_url_target( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, array( 'ravelry.com', 'www.ravelry.com' ), true ) ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		// Pattern: /patterns/library/<slug>
		if ( preg_match( '#^/patterns/library/([^/]+)/?$#', $path, $m ) ) {
			return array(
				'kind' => 'pattern',
				'slug' => (string) $m[1],
			);
		}
		// Project: /projects/<username>/<slug>
		if ( preg_match( '#^/projects/([^/]+)/([^/]+)/?$#', $path, $m ) ) {
			return array(
				'kind'     => 'project',
				'username' => (string) $m[1],
				'slug'     => (string) $m[2],
			);
		}
		return null;
	}

	/**
	 * Fetch the pattern or project from Ravelry. Returns the canonical
	 * extracted shape on success, or a structured failure with a
	 * `reason` code.
	 *
	 * @since 0.1.86
	 *
	 * @param string $url     URL the user shared.
	 * @param int    $user_id WP user whose Ravelry OAuth credentials to use.
	 * @return array<string,mixed>
	 */
	public static function fetch( string $url, int $user_id ): array {
		$target = self::parse_url_target( $url );
		if ( null === $target ) {
			return self::failure( 'invalid_url' );
		}

		$cache_key = self::CACHE_PREFIX . $target['kind'] . '_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$creds = Outpost_Credentials_Store::get( self::ID, $user_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return self::failure( 'not_connected' );
		}
		$token = (string) $creds['access_token'];

		if ( 'pattern' === $target['kind'] ) {
			// Patterns: search by permalink/slug to resolve the numeric id, then fetch.
			$body = self::api_get( '/patterns/search.json?query=' . rawurlencode( $target['slug'] ) . '&page_size=1', $token );
			if ( isset( $body['extracted'] ) && false === $body['extracted'] ) {
				return $body;
			}
			$pattern_id = self::first_pattern_id( $body );
			if ( null === $pattern_id ) {
				return self::failure( 'not_found' );
			}
			$body = self::api_get( '/patterns/' . $pattern_id . '.json', $token );
			if ( isset( $body['extracted'] ) && false === $body['extracted'] ) {
				return $body;
			}
			$payload = isset( $body['pattern'] ) && is_array( $body['pattern'] ) ? $body['pattern'] : $body;
			$result  = self::project_pattern( $payload, $url );
		} else {
			// Projects: GET /projects/<username>/<slug>.json
			$path = sprintf(
				'/projects/%s/%s.json',
				rawurlencode( (string) $target['username'] ),
				rawurlencode( $target['slug'] )
			);
			$body = self::api_get( $path, $token );
			if ( isset( $body['extracted'] ) && false === $body['extracted'] ) {
				return $body;
			}
			$payload = isset( $body['project'] ) && is_array( $body['project'] ) ? $body['project'] : $body;
			if ( self::is_private_project( $payload ) ) {
				return self::failure( 'private' );
			}
			$result = self::project_project( $payload, $url );
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @param array<string,mixed> $body Raw search response.
	 */
	private static function first_pattern_id( array $body ): ?int {
		$patterns = isset( $body['patterns'] ) && is_array( $body['patterns'] ) ? $body['patterns'] : array();
		if ( empty( $patterns ) ) {
			return null;
		}
		$first = $patterns[0];
		if ( ! is_array( $first ) || ! isset( $first['id'] ) ) {
			return null;
		}
		$id = (int) $first['id'];
		return $id > 0 ? $id : null;
	}

	/**
	 * @param array<string,mixed> $project Raw project payload.
	 */
	private static function is_private_project( array $project ): bool {
		// Ravelry exposes `made_for_others` and `permissions` shapes; the
		// canonical "this is private" signal we ship against is the
		// `permission_to_view` key when present + a positive flag, and
		// otherwise the `status_name` value of "frogged" / "hibernating"
		// is NOT private — those are valid public statuses. Default to
		// public when no signal is present.
		if ( isset( $project['permission_to_view'] ) && false === $project['permission_to_view'] ) {
			return true;
		}
		if ( isset( $project['private'] ) && true === $project['private'] ) {
			return true;
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $pattern    Pattern payload.
	 * @param string              $source_url URL the user shared.
	 * @return array<string,mixed>
	 */
	private static function project_pattern( array $pattern, string $source_url ): array {
		$name        = isset( $pattern['name'] ) ? (string) $pattern['name'] : __( 'Pattern', 'outpost-mobile-publishing' );
		$designer    = self::extract_designer_name( $pattern );
		$photo_url   = self::extract_primary_photo_url( $pattern );
		$gauge_str   = self::format_gauge( $pattern );
		$yardage_str = self::format_yardage( $pattern );
		$needles     = self::format_needles( $pattern );
		$fibers      = self::format_fibers( $pattern );

		$lines = array();
		if ( '' !== $gauge_str ) {
			$lines[] = '<dt>' . esc_html__( 'Gauge', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $gauge_str ) . '</dd>';
		}
		if ( '' !== $yardage_str ) {
			$lines[] = '<dt>' . esc_html__( 'Yardage', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $yardage_str ) . '</dd>';
		}
		if ( '' !== $needles ) {
			$lines[] = '<dt>' . esc_html__( 'Needles / hooks', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $needles ) . '</dd>';
		}
		if ( '' !== $fibers ) {
			$lines[] = '<dt>' . esc_html__( 'Fiber', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $fibers ) . '</dd>';
		}

		$header = '' === $designer
			? sprintf( /* translators: %s: pattern name. */ esc_html__( 'Pattern: %s', 'outpost-mobile-publishing' ), esc_html( $name ) )
			: sprintf(
				/* translators: 1: pattern name, 2: designer name. */
				esc_html__( 'Pattern: %1$s by %2$s', 'outpost-mobile-publishing' ),
				esc_html( $name ),
				esc_html( $designer )
			);
		$content = '<p>' . $header . '</p>';
		if ( ! empty( $lines ) ) {
			$content .= "\n<dl>" . implode( '', $lines ) . '</dl>';
		}
		$content .= "\n<p><a href=\"" . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></p>';

		$payload = array(
			'title'                  => $name,
			'content'                => $content,
			'post_meta'              => array(
				'_outpost_ravelry_designer' => $designer,
			),
			'syndication_source_url' => $source_url,
		);
		if ( null !== $photo_url ) {
			$payload['featured_image_url'] = $photo_url;
		}

		return array(
			'extracted'    => true,
			'kind'         => 'pattern',
			'title'        => $name,
			'content'      => $content,
			'post_kind'    => 'note',
			'post_payload' => $payload,
			'fetched_at'   => gmdate( 'c' ),
			'designer'     => $designer,
			'photo_url'    => $photo_url,
			'source_url'   => $source_url,
		);
	}

	/**
	 * @param array<string,mixed> $project    Project payload.
	 * @param string              $source_url URL the user shared.
	 * @return array<string,mixed>
	 */
	private static function project_project( array $project, string $source_url ): array {
		$name      = isset( $project['name'] ) ? (string) $project['name'] : __( 'Project', 'outpost-mobile-publishing' );
		$status    = isset( $project['status_name'] ) ? (string) $project['status_name'] : '';
		$photo_url = self::extract_primary_photo_url( $project );
		$started   = isset( $project['started'] ) ? (string) $project['started'] : '';
		$completed = isset( $project['completed'] ) ? (string) $project['completed'] : '';

		$lines = array();
		if ( '' !== $status ) {
			$lines[] = '<dt>' . esc_html__( 'Status', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $status ) . '</dd>';
		}
		if ( '' !== $started ) {
			$lines[] = '<dt>' . esc_html__( 'Started', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $started ) . '</dd>';
		}
		if ( '' !== $completed ) {
			$lines[] = '<dt>' . esc_html__( 'Completed', 'outpost-mobile-publishing' ) . '</dt><dd>' . esc_html( $completed ) . '</dd>';
		}

		$content = '<p>' . sprintf(
			/* translators: %s: project name. */
			esc_html__( 'Project: %s', 'outpost-mobile-publishing' ),
			esc_html( $name )
		) . '</p>';
		if ( ! empty( $lines ) ) {
			$content .= "\n<dl>" . implode( '', $lines ) . '</dl>';
		}
		$content .= "\n<p><a href=\"" . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></p>';

		$payload = array(
			'title'                  => $name,
			'content'                => $content,
			'post_meta'              => array(
				'_outpost_ravelry_status' => $status,
			),
			'syndication_source_url' => $source_url,
		);
		if ( null !== $photo_url ) {
			$payload['featured_image_url'] = $photo_url;
		}

		return array(
			'extracted'    => true,
			'kind'         => 'project',
			'title'        => $name,
			'content'      => $content,
			'post_kind'    => 'note',
			'post_payload' => $payload,
			'fetched_at'   => gmdate( 'c' ),
			'status'       => $status,
			'photo_url'    => $photo_url,
			'source_url'   => $source_url,
		);
	}

	/**
	 * @param array<string,mixed> $pattern Pattern payload.
	 */
	private static function extract_designer_name( array $pattern ): string {
		if ( isset( $pattern['designer'] ) && is_array( $pattern['designer'] ) ) {
			$name = $pattern['designer']['name'] ?? '';
			return is_string( $name ) ? $name : '';
		}
		return '';
	}

	/**
	 * Find the primary (sort_order=1 or marked_as_primary=true) photo URL.
	 *
	 * @param array<string,mixed> $payload Pattern or project payload.
	 */
	private static function extract_primary_photo_url( array $payload ): ?string {
		$photos = isset( $payload['photos'] ) && is_array( $payload['photos'] ) ? $payload['photos'] : array();
		if ( empty( $photos ) ) {
			return null;
		}
		$primary = null;
		$lowest  = PHP_INT_MAX;
		foreach ( $photos as $photo ) {
			if ( ! is_array( $photo ) ) {
				continue;
			}
			if ( ! empty( $photo['marked_as_primary'] ) ) {
				$primary = $photo;
				break;
			}
			$order = isset( $photo['sort_order'] ) ? (int) $photo['sort_order'] : PHP_INT_MAX;
			if ( $order < $lowest ) {
				$lowest  = $order;
				$primary = $photo;
			}
		}
		if ( null === $primary ) {
			return null;
		}
		// Ravelry returns `medium_url`, `medium2_url`, `large_url`. Prefer
		// medium2 (1000px wide) for featured image.
		foreach ( array( 'medium2_url', 'large_url', 'medium_url', 'small_url' ) as $key ) {
			if ( isset( $primary[ $key ] ) && is_string( $primary[ $key ] ) && '' !== $primary[ $key ] ) {
				return (string) $primary[ $key ];
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $pattern Pattern payload.
	 */
	private static function format_gauge( array $pattern ): string {
		$gauge        = isset( $pattern['gauge'] ) ? (float) $pattern['gauge'] : 0.0;
		$gauge_div    = isset( $pattern['gauge_divisor'] ) ? (float) $pattern['gauge_divisor'] : 0.0;
		$row_gauge    = isset( $pattern['row_gauge'] ) ? (float) $pattern['row_gauge'] : 0.0;
		$pattern_type = isset( $pattern['gauge_pattern'] ) ? (string) $pattern['gauge_pattern'] : '';
		if ( $gauge <= 0 ) {
			return '';
		}
		$base = $gauge_div > 0
			? sprintf( '%s × %s sts', (string) $gauge, (string) $gauge_div )
			: sprintf( '%s sts', (string) $gauge );
		if ( $row_gauge > 0 ) {
			$base .= sprintf( ' / %s rows', (string) $row_gauge );
		}
		if ( '' !== $pattern_type ) {
			$base .= sprintf( ' (%s)', $pattern_type );
		}
		return $base;
	}

	/**
	 * @param array<string,mixed> $pattern Pattern payload.
	 */
	private static function format_yardage( array $pattern ): string {
		$min = isset( $pattern['yardage'] ) ? (int) $pattern['yardage'] : 0;
		$max = isset( $pattern['yardage_max'] ) ? (int) $pattern['yardage_max'] : 0;
		if ( $min <= 0 && $max <= 0 ) {
			return '';
		}
		if ( $max > 0 && $max !== $min ) {
			return sprintf( '%d–%d yd', $min, $max );
		}
		return sprintf( '%d yd', $min );
	}

	/**
	 * @param array<string,mixed> $pattern Pattern payload.
	 */
	private static function format_needles( array $pattern ): string {
		$needles = isset( $pattern['pattern_needle_sizes'] ) && is_array( $pattern['pattern_needle_sizes'] )
			? $pattern['pattern_needle_sizes']
			: array();
		$names   = array();
		foreach ( $needles as $needle ) {
			if ( is_array( $needle ) && isset( $needle['name'] ) && is_string( $needle['name'] ) ) {
				$names[] = $needle['name'];
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * @param array<string,mixed> $pattern Pattern payload.
	 */
	private static function format_fibers( array $pattern ): string {
		$packs = isset( $pattern['packs'] ) && is_array( $pattern['packs'] ) ? $pattern['packs'] : array();
		$names = array();
		foreach ( $packs as $pack ) {
			if ( ! is_array( $pack ) || empty( $pack['yarn_name'] ) ) {
				continue;
			}
			$names[] = (string) $pack['yarn_name'];
		}
		return implode( ', ', $names );
	}

	/**
	 * GET helper for the Ravelry API.
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
	 * @return array{extracted: false, reason: string}
	 */
	private static function failure( string $reason ): array {
		return array(
			'extracted' => false,
			'reason'    => $reason,
		);
	}
}
