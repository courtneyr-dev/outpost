<?php
/**
 * Outpost_Source_AppleMusic
 *
 * Apple Music share URLs follow:
 *
 *   https://music.apple.com/{country}/album/{slug}/{id}
 *   https://music.apple.com/{country}/album/{slug}/{id}?i={track-id}  (track in album)
 *   https://music.apple.com/{country}/song/{slug}/{id}
 *   https://music.apple.com/{country}/playlist/{slug}/{id}
 *   https://music.apple.com/{country}/artist/{slug}/{id}
 *
 * Apple does not expose a public oEmbed endpoint for music. The
 * canonical pages emit standard OG meta tags (og:title with track or
 * album name, og:image with cover art, og:description with
 * artist/album context). The og_tags extractor scrapes these.
 *
 * Mode is unambiguous Listen (artist URLs included — they're a
 * legitimate Listen target as a "listening to this artist" entry).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_AppleMusic extends Outpost_Source_Base {

	public const ID = 'apple-music';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Apple Music', 'outpost' ),
			'host_patterns'    => array( 'music.apple.com' ),
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
