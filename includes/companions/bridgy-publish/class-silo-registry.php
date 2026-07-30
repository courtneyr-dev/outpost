<?php
/**
 * Outpost_Bridgy_Publish_Silo_Registry
 *
 * Holds the 5 default Bridgy Publish silo configs (May 2026 confirmed
 * per concepts/posse-outbound-may-2026.md §5) and applies the
 * `outpost_bridgy_publish_silos` filter so site owners can append,
 * modify, or remove without forking core.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Silo_Registry {

	/**
	 * @var Outpost_Bridgy_Publish_Silo_Config[]|null
	 */
	private static ?array $resolved = null;

	/**
	 * Resolve all silos (defaults + filter additions), validate each.
	 *
	 * @return Outpost_Bridgy_Publish_Silo_Config[]
	 *
	 * @throws Outpost_Bridgy_Publish_Invalid_Config_Exception
	 */
	public static function all_silos(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$defaults = self::default_configs();
		/**
		 * Filter the Bridgy Publish silo configs before validation.
		 *
		 * Site owners and third-party plugins can:
		 *   - Append a new silo (Bridgy adds new silos occasionally)
		 *   - Remove a default silo (filter the array, drop entries by id)
		 *   - Modify caveats / accepts_modes per their setup
		 *
		 * Returned configs are validated through Silo_Config; malformed
		 * entries throw `Outpost_Bridgy_Publish_Invalid_Config_Exception`
		 * during resolution.
		 *
		 * @param array<int, array<string,mixed>> $configs Default silo configs.
		 */
		$filtered   = apply_filters( 'outpost_bridgy_publish_silos', $defaults );
		$candidates = is_array( $filtered ) ? array_values( $filtered ) : $defaults;

		$resolved = array();
		foreach ( $candidates as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$resolved[] = new Outpost_Bridgy_Publish_Silo_Config( $config );
		}

		self::$resolved = $resolved;
		return $resolved;
	}

	/**
	 * Look up a silo config by id.
	 */
	public static function find_by_id( string $silo_chip_id ): ?Outpost_Bridgy_Publish_Silo_Config {
		foreach ( self::all_silos() as $silo ) {
			if ( $silo->id() === $silo_chip_id ) {
				return $silo;
			}
		}
		return null;
	}

	public static function reset_for_tests(): void {
		self::$resolved = null;
	}

	/**
	 * The five default Bridgy Publish silos (May 2026 confirmed). Each
	 * is a raw associative array validated into a Silo_Config during
	 * resolution.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function default_configs(): array {
		return array(
			array(
				'id'            => 'bridgy-mastodon',
				'label'         => __( 'Mastodon (via Bridgy)', 'outpost-mobile-publishing' ),
				'icon'          => 'mastodon',
				'silo_id'       => 'mastodon',
				'bridgy_url'    => 'https://brid.gy/publish/mastodon',
				'accepts_modes' => array(
					'note',
					'photo',
					'gallery',
					'article',
					'reply',
					'like',
					'repost',
					'bookmark',
				),
				'accepts_media' => array( 'image', 'video', 'audio' ),
				'caveats'       => array(
					__( 'Posts route through brid.gy; first-time setup at brid.gy is required.', 'outpost-mobile-publishing' ),
				),
			),
			array(
				'id'            => 'bridgy-bluesky',
				'label'         => __( 'Bluesky (via Bridgy)', 'outpost-mobile-publishing' ),
				'icon'          => 'bluesky',
				'silo_id'       => 'bluesky',
				'bridgy_url'    => 'https://brid.gy/publish/bluesky',
				'accepts_modes' => array(
					'note',
					'photo',
					'gallery',
					'article',
					'reply',
					'like',
					'repost',
				),
				'accepts_media' => array( 'image', 'video' ),
				'caveats'       => array(
					__( 'Bluesky has 1MB / ~1000px image limits and 60s video cap. Outpost does not auto-resize for Bluesky in this release.', 'outpost-mobile-publishing' ),
				),
			),
			array(
				'id'            => 'bridgy-flickr',
				'label'         => __( 'Flickr (via Bridgy)', 'outpost-mobile-publishing' ),
				'icon'          => 'flickr',
				'silo_id'       => 'flickr',
				'bridgy_url'    => 'https://brid.gy/publish/flickr',
				'accepts_modes' => array(
					'photo',
					'gallery',
					'reply',
					'like',
				),
				'accepts_media' => array( 'image' ),
				'caveats'       => array(),
			),
			array(
				'id'            => 'bridgy-github',
				'label'         => __( 'GitHub (via Bridgy)', 'outpost-mobile-publishing' ),
				'icon'          => 'github',
				'silo_id'       => 'github',
				'bridgy_url'    => 'https://brid.gy/publish/github',
				'accepts_modes' => array(
					'note',
					'reply',
					'like',
				),
				'accepts_media' => array(),
				'caveats'       => array(
					__( 'GitHub posts work for issue comments, repo stars (likes), and creating issues from posts. Photos reference the hosted image; GitHub does not host uploaded binaries.', 'outpost-mobile-publishing' ),
				),
			),
			array(
				'id'            => 'bridgy-reddit',
				'label'         => __( 'Reddit (via Bridgy)', 'outpost-mobile-publishing' ),
				'icon'          => 'reddit',
				'silo_id'       => 'reddit',
				'bridgy_url'    => 'https://brid.gy/publish/reddit',
				'accepts_modes' => array(
					'note',
					'article',
					'reply',
					'like',
				),
				'accepts_media' => array(),
				'caveats'       => array(
					__( 'Reddit posts go to the subreddit linked at brid.gy; configure subreddit at brid.gy.', 'outpost-mobile-publishing' ),
				),
			),
		);
	}
}
