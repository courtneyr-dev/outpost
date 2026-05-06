<?php
/**
 * Outpost_Source_Sefaria (G10a).
 *
 * Sefaria (https://www.sefaria.org/) is the open Jewish-text library —
 * Tanakh, Talmud, Mishnah, commentaries, and more under CC0 / CC-BY /
 * CC-BY-SA depending on text. The og_tags-only adapter ships now;
 * full API integration with citation parsing + license-aware attribution
 * arrives with G10b.
 *
 * URL forms claimed: any path under sefaria.org or www.sefaria.org.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Sefaria extends Outpost_Source_Base {

	public const ID = 'sefaria';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Sefaria', 'outpost' ),
			'host_patterns'    => array( 'sefaria.org', 'www.sefaria.org' ),
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
			'tags_default'     => array( 'quote', 'scripture' ),
			'caveats'          => array(
				__( 'Sefaria texts are CC0 / CC-BY / CC-BY-SA depending on the work. The og_tags adapter cannot detect which license applies; the docs page recommends a generic "Source: Sefaria.org" attribution. License-aware attribution arrives with G10b.', 'outpost' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
