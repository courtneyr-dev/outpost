<?php
/**
 * Outpost_Source_Readwise
 *
 * Per Doc 2 §3.8 — Readwise's authenticated API requires per-user
 * tokens (BYO, not embedded). For the share-target inbound flow the
 * anonymous OG path is preferred. Auto-route to Bookmark mode (the
 * closest fit for a quote share; Outpost has no separate Quote
 * mode in CLAUDE.md).
 *
 * URL forms claimed:
 *
 *   - https://readwise.io/bookreview/{id}
 *   - https://readwise.io/highlights/{id}
 *   - https://read.readwise.io/read/{id}
 *
 * Profile URLs (`@<handle>`) and library URLs are NOT single
 * highlights / readings — they fall through to Source_Unknown.
 *
 * Mapping note: og:description carries the highlight text itself
 * (the quote), so it routes to e-content rather than p-summary
 * (per Doc 2 §3.8 — "the highlight text itself").
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Readwise extends Outpost_Source_Base {

	public const ID = 'readwise';

	private const CLAIMED_HOSTS = array( 'readwise.io', 'read.readwise.io' );

	private const READWISE_PATH_PREFIXES = array( '/bookreview/', '/highlights/' );

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Readwise', 'outpost-mobile-publishing' ),
			'host_patterns'    => self::CLAIMED_HOSTS,
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
				'og:description' => 'e-content',
				'@source_url'    => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => false,
			'tags_default'     => array( 'bookmark', 'quote' ),
			'caveats'          => array(
				__( 'Anonymous OG path. Authenticated highlight-pull (BYO Readwise token) is a separate optional sync feature.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to claim only highlight / bookreview / Reader-document
	 * paths. Profile URLs and library URLs fall through.
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

		if ( 'read.readwise.io' === $host ) {
			// Reader documents at /read/{id} on the read. subdomain.
			return 0 === strpos( $path, '/read/' ) && strlen( $path ) > strlen( '/read/' );
		}

		foreach ( self::READWISE_PATH_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) && strlen( $path ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
