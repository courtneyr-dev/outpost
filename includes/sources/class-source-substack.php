<?php
/**
 * Outpost_Source_Substack
 *
 * Substack hosts publications as per-pub subdomains:
 * `{publication}.substack.com/p/{slug}`. The apex `substack.com` is
 * the discovery / company site and is NOT claimed.
 *
 * OG tags on Substack post pages include og:title (post title),
 * og:description (excerpt), og:image (post cover or default
 * publication image).
 *
 * Mode is unambiguous Read.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Substack extends Outpost_Source_Base {

	public const ID = 'substack';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Substack', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( '*.substack.com' ),
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
			'tags_default'     => array( 'read' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
