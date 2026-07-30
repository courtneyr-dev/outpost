<?php
/**
 * Outpost_Source_Pretalx (G13a).
 *
 * Pretalx (https://pretalx.com/) is the open-source CFP / conference
 * scheduling platform. The hosted SaaS at pretalx.com runs many
 * conferences; self-hosted instances live at custom domains.
 *
 * G13a covers pretalx.com hosted only — single-instance og_tags
 * adapter. Self-hosted Pretalx (G13b) and Sessionize (G13c) wait on
 * the settings-UI foundation.
 *
 * URL forms claimed:
 *
 *   - https://pretalx.com/{event}/talk/{id}      → quote
 *   - https://pretalx.com/{event}/talk/{id}/     → quote (trailing slash)
 *   - https://pretalx.com/{event}/schedule       → bookmark
 *   - https://pretalx.com/{event}/speaker/{id}   → bookmark
 *
 * Other paths under pretalx.com fall through to Source_Unknown.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Pretalx extends Outpost_Source_Base {

	public const ID = 'pretalx';

	private const HOST = 'pretalx.com';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Pretalx', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( self::HOST ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'quote',
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
				'@source_url'    => 'u-quotation-of',
			),
			'h_entry_property' => 'u-quotation-of',
			'auth_required'    => false,
			'tags_default'     => array( 'quote', 'conference' ),
			'caveats'          => array(
				__( 'G13a covers pretalx.com hosted only. Self-hosted Pretalx instances at custom domains (G13b) and Sessionize (G13c) wait on the settings-UI foundation.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Constrain matches to /{event}/{talk|schedule|speaker}/* paths.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( self::HOST !== $host ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		// /{event}/talk/{id} or /{event}/schedule or /{event}/speaker/{id}.
		// Event slug is one path segment; subsequent segment is the type.
		return 1 === preg_match(
			'~^/[^/]+/(talk/[^/]+|schedule|speaker/[^/]+)~i',
			$path
		);
	}

	/**
	 * Per-URL Post Kind suggestion (G13a item 1).
	 *
	 *   - /{event}/talk/{id}    → quote   (the talk's title + abstract
	 *                                      become the quote body)
	 *   - /{event}/schedule     → bookmark (the schedule is a discovery
	 *                                       target for the whole event)
	 *   - /{event}/speaker/{id} → bookmark (the speaker page is a
	 *                                       reference target)
	 *
	 * @param string $url URL the user shared.
	 * @return string Composer mode slug.
	 */
	public function mode_for_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( 1 === preg_match( '~^/[^/]+/talk/[^/]+~i', $path ) ) {
			return 'quote';
		}
		if ( 1 === preg_match( '~^/[^/]+/(schedule|speaker/[^/]+)~i', $path ) ) {
			return 'bookmark';
		}
		// Defensive default; matches_url filters before this runs.
		return 'bookmark';
	}
}
