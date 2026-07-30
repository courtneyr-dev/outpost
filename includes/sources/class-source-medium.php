<?php
/**
 * Outpost_Source_Medium
 *
 * Medium URLs come in three shapes:
 *
 *   - https://medium.com/@{user}/{title-hash} (canonical user form)
 *   - https://{publication}.medium.com/{title-hash} (publication form)
 *   - https://medium.com/{publication}/{title-hash} (legacy form)
 *
 * All emit standard OG tags.
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

final class Outpost_Source_Medium extends Outpost_Source_Base {

	public const ID = 'medium';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Medium', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'medium.com', '*.medium.com' ),
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
