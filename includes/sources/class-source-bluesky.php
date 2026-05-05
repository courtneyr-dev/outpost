<?php
/**
 * Outpost_Source_Bluesky
 *
 * Bluesky post URLs:
 *
 *   https://bsky.app/profile/{handle}/post/{id}
 *
 * Plus the Bridgy Fed bridge for AT-protocol-to-fediverse:
 *
 *   https://bsky.brid.gy/r/https://...  (rare; usually bsky.app native)
 *
 * Bluesky pages emit OG tags including og:title (post text excerpt),
 * og:description (post body), og:image (attached image when present).
 * No public oEmbed; no auth required for og_tags scraping of public
 * posts.
 *
 * Mode is unambiguous Reply — same rationale as Mastodon: sharing
 * someone else's post to your own site is a Reply / commentary
 * action by default. User can switch to Bookmark / Like via the
 * composer's variant picker.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Bluesky extends Outpost_Source_Base {

	public const ID = 'bluesky';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Bluesky', 'outpost' ),
			'host_patterns'    => array( 'bsky.app' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'reply',
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
				'@source_url'    => 'u-in-reply-to',
			),
			'h_entry_property' => 'u-in-reply-to',
			'auth_required'    => false,
			'tags_default'     => array( 'reply' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override matches_url to constrain to /profile/{handle}/post/{id}
	 * paths. The Bluesky homepage and profile pages are not single-
	 * post Reply targets.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 'bsky.app' !== $host ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		return 1 === preg_match( '~^/profile/[^/]+/post/[^/]+~', $path );
	}
}
