<?php
/**
 * Outpost_Source_Snipd
 *
 * Snipd's public share pages emit standard OG tags. No oEmbed,
 * no public API. Per Doc 2 §3.2 — auto-route to Listen mode with
 * pre-filled p-name (snip / episode title), p-summary (Snipd's
 * generated summary), u-photo (episode cover), u-listen-of
 * (share URL).
 *
 * URL forms claimed:
 *
 *   - https://share.snipd.com/snip/{id}
 *   - https://share.snipd.com/episode/{id}
 *   - https://share.snipd.com/show/{id}
 *
 * Profile URLs (`/user/{handle}`) are NOT claimed — they aren't
 * single-listen events, so they fall through to Source_Unknown.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Snipd extends Outpost_Source_Base {

	public const ID = 'snipd';

	private const SHARE_HOST = 'share.snipd.com';

	private const CLAIMED_PATH_PREFIXES = array( '/snip/', '/episode/', '/show/' );

	/**
	 * Per-path Post Kind suggestion (G15a item 1).
	 *
	 * - /snip/{id}    → quote   (the snip's transcript becomes the body;
	 *                            the timestamped link is the cite)
	 * - /episode/{id} → listen  (existing F-phase behavior preserved)
	 * - /show/{id}    → bookmark
	 * - other Snipd paths → bookmark (safe default; matches_url already
	 *                                 filters to the three claimed prefixes)
	 */
	private const PATH_TO_MODE = array(
		'/snip/'    => 'quote',
		'/episode/' => 'listen',
		'/show/'    => 'bookmark',
	);

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Snipd', 'outpost' ),
			'host_patterns'    => array( self::SHARE_HOST ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'listen',
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
				'@source_url'    => 'u-listen-of',
			),
			'h_entry_property' => 'u-listen-of',
			'auth_required'    => false,
			'tags_default'     => array( 'listen', 'podcast' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to add the path-prefix constraint. Snipd profile URLs
	 * are NOT single-listen events; only /snip/, /episode/, /show/
	 * paths are claimed.
	 *
	 * F5 design-gap follow-up — same shape as F15 YouTube
	 * (CLAUDE.md F15 #1): trailing-slash-required path patterns
	 * don't fit Snipd's `/snip/{id}` (no trailing slash). Override
	 * is the established escape hatch until Source_Base supports
	 * path-prefix-without-trailing-slash patterns.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( self::SHARE_HOST !== $host ) {
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

	/**
	 * Per-URL Post Kind suggestion (G15a item 1). Snipd's three share
	 * patterns map to different IndieWeb kinds:
	 *
	 *   - /snip/{id}    → quote   (the snip is a transcript excerpt;
	 *                              the timestamped link is the citation)
	 *   - /episode/{id} → listen  (the episode itself, preserved)
	 *   - /show/{id}    → bookmark (the show is a discovery target,
	 *                              not a single listen event)
	 *
	 * Capabilities still declares mode='listen' as the dominant default;
	 * this override branches on URL path so the dispatcher routes each
	 * variant to the right composer mode.
	 *
	 * matches_url() filters to the three claimed prefixes before
	 * mode_for_url runs, so the per-path map is exhaustive in practice.
	 * Defensive fallback to bookmark for any future additions to
	 * CLAIMED_PATH_PREFIXES that arrive without a matching mode entry.
	 *
	 * @param string $url URL the user shared.
	 * @return string Composer mode slug.
	 */
	public function mode_for_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		foreach ( self::PATH_TO_MODE as $prefix => $mode ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return $mode;
			}
		}
		return 'bookmark';
	}
}
