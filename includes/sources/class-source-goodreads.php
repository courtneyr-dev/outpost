<?php
/**
 * Outpost_Source_Goodreads
 *
 * Per Doc 2 §3.5 — Goodreads's REST API was killed in Dec 2020;
 * OG tags on book pages are the surviving public extraction path.
 * Auto-route to Read mode with p-name (book title), u-photo (cover),
 * p-summary (book blurb), u-read-of (share URL).
 *
 * URL forms claimed:
 *
 *   - https://www.goodreads.com/book/show/{id}-{slug}
 *   - https://www.goodreads.com/book/show/{id}.{slug}      (legacy)
 *   - https://www.goodreads.com/review/show/{id}
 *
 * User shelf URLs and search URLs fall through to Source_Unknown.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Goodreads extends Outpost_Source_Base {

	public const ID = 'goodreads';

	private const CLAIMED_HOSTS = array( 'goodreads.com', 'www.goodreads.com' );

	private const CLAIMED_PATH_PREFIXES = array( '/book/show/', '/review/show/' );

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Goodreads', 'outpost-mobile-publishing' ),
			'host_patterns'    => self::CLAIMED_HOSTS,
			'ambiguity'        => 'unambiguous',
			'mode'             => 'read',
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
				'@source_url'    => 'u-read-of',
			),
			'h_entry_property' => 'u-read-of',
			'auth_required'    => false,
			'tags_default'     => array( 'read', 'book' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to constrain to book / review pages only. Shelf URLs,
	 * search URLs, and user profiles fall through to Source_Unknown.
	 *
	 * F5 design-gap follow-up applies (same as F15 YouTube,
	 * F16 Snipd) — path-prefix-without-trailing-slash isn't
	 * expressible in host_patterns yet.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, self::CLAIMED_HOSTS, true ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		foreach ( self::CLAIMED_PATH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) && strlen( $path ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
