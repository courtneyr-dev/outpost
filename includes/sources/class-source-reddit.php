<?php
/**
 * Outpost_Source_Reddit
 *
 * Reddit comment / post share URLs:
 *
 *   - https://www.reddit.com/r/{sub}/comments/{id}/{slug}
 *   - https://reddit.com/r/{sub}/comments/{id}/{slug}
 *   - https://old.reddit.com/r/{sub}/comments/{id}/{slug}
 *   - https://redd.it/{id}  (short link form)
 *
 * Path constraint: only /r/{sub}/comments/{id} and the redd.it short
 * domain are claimed. Subreddit landing pages, profile pages, and
 * the homepage fall through to Source_Unknown — those aren't
 * single-post Bookmark targets.
 *
 * Mode default is Bookmark; user can switch to Reply variant in the
 * composer if they want to thread on the post. Reddit's OG tags
 * include og:title (post title), og:description (post body excerpt),
 * og:image (link image when applicable).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Reddit extends Outpost_Source_Base {

	public const ID = 'reddit';

	private const COMMENT_HOSTS = array(
		'reddit.com',
		'www.reddit.com',
		'old.reddit.com',
		'new.reddit.com',
		'np.reddit.com',
	);

	private const SHORT_HOSTS = array( 'redd.it' );

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Reddit', 'outpost' ),
			'host_patterns'    => array_merge( self::COMMENT_HOSTS, self::SHORT_HOSTS ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'bookmark',
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
				'@source_url'    => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => false,
			'tags_default'     => array( 'bookmark' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override matches_url to constrain Reddit hosts to comment/post
	 * URLs only. Subreddit landing pages and profile pages fall
	 * through to Source_Unknown.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		// redd.it short links: any non-empty path is a post.
		if ( in_array( $host, self::SHORT_HOSTS, true ) ) {
			return strlen( $path ) > 1;
		}

		// reddit.com hosts: only /r/{sub}/comments/{id}/...
		if ( in_array( $host, self::COMMENT_HOSTS, true ) ) {
			return 1 === preg_match( '~^/r/[^/]+/comments/[^/]+~i', $path );
		}

		return false;
	}
}
