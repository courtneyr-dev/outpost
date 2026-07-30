<?php
/**
 * Outpost_Source_ApplePodcasts
 *
 * Apple Podcasts share URLs follow:
 *
 *   https://podcasts.apple.com/{country}/podcast/{slug}/id{id}
 *   https://podcasts.apple.com/{country}/podcast/{slug}/id{id}?i={episode-id}
 *
 * No public oEmbed; OG tags from the canonical page work for show
 * title + cover art. The user can amend the episode title in the
 * composer if the share URL was a show-level link.
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

final class Outpost_Source_ApplePodcasts extends Outpost_Source_Base {

	public const ID = 'apple-podcasts';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Apple Podcasts', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'podcasts.apple.com' ),
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
}
