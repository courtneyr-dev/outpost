<?php
/**
 * Outpost_Source_Pinterest
 *
 * Per Doc 2 §3.10 — Pinterest pin pages emit OG tags. Pinterest's
 * v5 API requires user OAuth (already covered in Doc 1 outbound);
 * F16's inbound flow uses the OG path.
 *
 * URL forms claimed:
 *
 *   - https://www.pinterest.com/pin/{id}/
 *   - https://pinterest.com/pin/{id}/             (apex)
 *   - https://pin.it/{shortcode}                  (short link)
 *
 * Board URLs (`/{user}/{board}/`) and profile URLs are NOT claimed
 * — they aren't single pins.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Pinterest extends Outpost_Source_Base {

	public const ID = 'pinterest';

	private const PIN_HOSTS = array(
		'pinterest.com',
		'www.pinterest.com',
		'pin.it',
	);

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Pinterest', 'outpost-mobile-publishing' ),
			'host_patterns'    => self::PIN_HOSTS,
			'ambiguity'        => 'unambiguous',
			'mode'             => 'bookmark',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'       => 'p-name',
				'og:description' => 'p-summary',
				'og:image'       => 'u-photo',
				'@source_url'    => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => false,
			'tags_default'     => array( 'bookmark', 'pinterest' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to constrain to /pin/ paths on pinterest.com (board /
	 * profile URLs fall through). pin.it is all-paths since every
	 * pin.it short link redirects to a /pin/ canonical URL.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, self::PIN_HOSTS, true ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		if ( 'pin.it' === $host ) {
			// Short links: any non-empty path.
			return strlen( $path ) > 1;
		}

		return 0 === strpos( $path, '/pin/' ) && strlen( $path ) > strlen( '/pin/' );
	}
}
