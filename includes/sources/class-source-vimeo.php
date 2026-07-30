<?php
/**
 * Outpost_Source_Vimeo
 *
 * Vimeo's oEmbed endpoint is the canonical extraction path:
 *
 *   https://vimeo.com/api/oembed.json?url={url}
 *
 * Anonymous, no API key, returns title / author_name (channel) /
 * thumbnail_url / provider_name. Mode is unambiguous Watch.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Vimeo extends Outpost_Source_Base {

	public const ID = 'vimeo';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Vimeo', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'vimeo.com', 'player.vimeo.com' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'watch',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'oembed',
			'recipe'           => array(
				'endpoint'        => 'https://vimeo.com/api/oembed.json?url={url}',
				'response_format' => 'json',
			),
			'mapping'          => array(
				'title'         => 'p-name',
				'author_name'   => 'p-author',
				'thumbnail_url' => 'u-photo',
				'provider_name' => 'p-publication',
				'@source_url'   => 'u-watch-of',
			),
			'h_entry_property' => 'u-watch-of',
			'auth_required'    => false,
			'tags_default'     => array( 'watch' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}
}
