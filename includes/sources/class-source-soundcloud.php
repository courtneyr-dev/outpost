<?php
/**
 * Outpost_Source_SoundCloud
 *
 * SoundCloud's oEmbed:
 *
 *   https://soundcloud.com/oembed?format=json&url={url}
 *
 * Anonymous, no API key. Returns title / author_name / thumbnail_url /
 * provider_name. Track / playlist / user-page URLs all supported.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_SoundCloud extends Outpost_Source_Base {

	public const ID = 'soundcloud';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'SoundCloud', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'soundcloud.com', 'on.soundcloud.com' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'listen',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'oembed',
			'recipe'           => array(
				'endpoint'        => 'https://soundcloud.com/oembed?format=json&url={url}',
				'response_format' => 'json',
			),
			'mapping'          => array(
				'title'         => 'p-name',
				'author_name'   => 'p-author',
				'thumbnail_url' => 'u-photo',
				'provider_name' => 'p-publication',
				'@source_url'   => 'u-listen-of',
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
