<?php
/**
 * Outpost_Source_TikTok
 *
 * Per Doc 2 §3.20 — TikTok video pages emit OG tags. TikTok also
 * has an oEmbed endpoint, but F16 batches it with the og_tags
 * sources for bulk-coverage parity (oEmbed adoption can come in a
 * follow-up if richer metadata is needed; OG covers the composer
 * pre-fill case adequately).
 *
 * URL forms claimed:
 *
 *   - https://www.tiktok.com/@{user}/video/{id}
 *   - https://tiktok.com/@{user}/video/{id}     (apex)
 *   - https://vm.tiktok.com/{shortcode}         (short link)
 *
 * Profile URLs (`@{user}` with no `/video/...` segment) and live
 * URLs are NOT claimed — they aren't single watch events.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_TikTok extends Outpost_Source_Base {

	public const ID = 'tiktok';

	private const VIDEO_HOSTS = array(
		'tiktok.com',
		'www.tiktok.com',
	);

	private const SHORT_LINK_HOST = 'vm.tiktok.com';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'TikTok', 'outpost' ),
			'host_patterns'    => array(
				'tiktok.com',
				'www.tiktok.com',
				self::SHORT_LINK_HOST,
			),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'watch',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'    => 'p-name',
				'og:image'    => 'u-photo',
				'@source_url' => 'u-watch-of',
			),
			'h_entry_property' => 'u-watch-of',
			'auth_required'    => false,
			'tags_default'     => array( 'watch', 'tiktok' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to constrain tiktok.com to `@{user}/video/{id}` paths;
	 * vm.tiktok.com short links match all paths.
	 *
	 * Profile URLs (`/@user` with no further segment) fall through
	 * to Source_Unknown.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );

		if ( self::SHORT_LINK_HOST === $host ) {
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			return strlen( $path ) > 1;
		}

		if ( ! in_array( $host, self::VIDEO_HOSTS, true ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		// Path must look like `/@user/video/12345` — username segment
		// followed by `/video/` followed by a non-empty video id.
		return (bool) preg_match( '#^/@[^/]+/video/[^/]+#i', $path );
	}
}
