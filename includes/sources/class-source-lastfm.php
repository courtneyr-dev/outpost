<?php
/**
 * Outpost_Source_LastFm
 *
 * Per Doc 2 §3.7 — Last.fm has an API but it requires an API key
 * (§5 risk for a free WP.org plugin). OG tags on track / artist /
 * album pages are auth-free. Auto-route to Listen mode with
 * p-name + u-photo (cover art) + u-listen-of.
 *
 * URL forms claimed:
 *
 *   - https://www.last.fm/music/{artist}/_/{track}
 *   - https://www.last.fm/music/{artist}/{album}
 *   - https://www.last.fm/music/{artist}
 *
 * Last.fm doesn't provide a useful description on track/album pages
 * (only on artist pages, where it's a Wikipedia excerpt) — the
 * mapping omits og:description to avoid the awkward bio-paste case.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_LastFm extends Outpost_Source_Base {

	public const ID = 'lastfm';

	private const CLAIMED_HOSTS = array( 'last.fm', 'www.last.fm' );

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Last.fm', 'outpost' ),
			'host_patterns'    => self::CLAIMED_HOSTS,
			'ambiguity'        => 'unambiguous',
			'mode'             => 'listen',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'    => 'p-name',
				'og:image'    => 'u-photo',
				'@source_url' => 'u-listen-of',
			),
			'h_entry_property' => 'u-listen-of',
			'auth_required'    => false,
			'tags_default'     => array( 'listen' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to claim only `/music/...` paths. User-profile URLs
	 * (`/user/...`) and library / chart URLs fall through to
	 * Source_Unknown.
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
		return 0 === strpos( $path, '/music/' ) && strlen( $path ) > strlen( '/music/' );
	}
}
