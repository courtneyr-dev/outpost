<?php
/**
 * Outpost_Source_SuttaCentral (G10a).
 *
 * SuttaCentral (https://suttacentral.net/) is the open Buddhist canon
 * library — Pali / Sanskrit / Chinese / Tibetan texts under mostly
 * CC0 (Bhikkhu Sujato translations). The og_tags-only adapter ships
 * now; full API integration with translator-aware selection arrives
 * with G10b.
 *
 * URL forms claimed: any path under suttacentral.net.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_SuttaCentral extends Outpost_Source_Base {

	public const ID = 'suttacentral';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'SuttaCentral', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'suttacentral.net' ),
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
				__( 'SuttaCentral texts are mostly CC0 (Bhikkhu Sujato translations). The og_tags adapter cannot detect translator metadata; the docs page recommends a generic "Source: SuttaCentral.net" attribution. Translator-aware attribution arrives with G10b.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
