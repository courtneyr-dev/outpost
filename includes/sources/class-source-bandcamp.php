<?php
/**
 * Outpost_Source_Bandcamp
 *
 * Bandcamp uses per-artist subdomains: `{artist}.bandcamp.com/{album,track}/{slug}`.
 * Apex `bandcamp.com` mostly hosts discovery / company pages and is
 * NOT claimed — those fall through to Source_Unknown. Subdomain
 * matches are claimed via the F5 suffix-wildcard pattern.
 *
 * No public oEmbed without API key registration. OG tags work cleanly
 * — Bandcamp's track / album pages emit og:title (track or album
 * name), og:description (artist context), og:image (album art).
 *
 * Mode is unambiguous Listen.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Bandcamp extends Outpost_Source_Base {

	public const ID = 'bandcamp';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Bandcamp', 'outpost-mobile-publishing' ),
			// Suffix wildcard claims every artist subdomain. Apex
			// `bandcamp.com` falls through to Source_Unknown.
			'host_patterns'    => array( '*.bandcamp.com' ),
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
			'tags_default'     => array( 'listen' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
