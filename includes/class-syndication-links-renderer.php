<?php
/**
 * Outpost_Syndication_Links_Renderer
 *
 * Hooks `the_content` to inject `u-syndication` anchors into post
 * output for posts with `outpost_syndication_links` post-meta. Bridgy
 * and other webmention consumers parse those anchors as
 * microformats2 syndication markers — that's how they discover the
 * silo copy of an Outpost post (Doc 1 §4.2).
 *
 * Render shape (all entries appended at end of post content):
 *
 *     <div class="outpost-syndication-links" hidden>
 *         <a class="u-syndication" href="https://www.instagram.com/p/abc">Instagram</a>
 *         <a class="u-syndication" href="https://twitter.com/.../1234567">X</a>
 *     </div>
 *
 * The `hidden` attribute keeps the wrapper out of visible content by
 * default (mf2 parsers see the source HTML). Themes wanting to show
 * the syndication links override `[hidden]` in CSS or use the
 * `outpost_render_syndication_links` filter for full programmatic
 * control.
 *
 * Filter: `outpost_render_syndication_links`
 *   Signature: `(bool $should_render, int $post_id, array $links) => bool`
 *   Default: true. Sites that want to suppress rendering entirely
 *   (e.g. they emit u-syndication via a custom template) return false.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Syndication_Links_Renderer {

	/** Per-platform display label for the visible anchor text. */
	private const LABELS = array(
		'instagram-feed'    => 'Instagram',
		'instagram-stories' => 'Instagram Stories',
		'facebook'          => 'Facebook',
		'x-twitter'         => 'X',
		'linkedin'          => 'LinkedIn',
		'threads'           => 'Threads',
		'tiktok'            => 'TikTok',
		'pinterest'         => 'Pinterest',
		'reddit-manual'     => 'Reddit',
		'flickr-manual'     => 'Flickr',
	);

	/**
	 * Hook the the_content + the_excerpt filters.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'append_links' ), 99 );
		// the_excerpt is conservative — Bridgy's feed-scanning paths
		// also benefit from u-syndication being in the excerpt for
		// posts that show feed previews.
		add_filter( 'the_excerpt', array( self::class, 'append_links_excerpt' ), 99 );
	}

	/**
	 * Filter callback for the_content. Reads the post's syndication
	 * links and appends anchors when present.
	 *
	 * @param string $content Original post content.
	 */
	public static function append_links( string $content ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return $content;
		}
		return $content . self::render_for_post( $post_id );
	}

	/**
	 * Filter callback for the_excerpt. Appends to excerpt when post
	 * has syndication links.
	 *
	 * @param string $excerpt Original excerpt.
	 */
	public static function append_links_excerpt( string $excerpt ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return $excerpt;
		}
		return $excerpt . self::render_for_post( $post_id );
	}

	/**
	 * Render the syndication-links HTML for a specific post. Public
	 * so the WP admin notice and tests can call it directly without
	 * the_content's loop dependency.
	 *
	 * @param int $post_id Post id.
	 */
	public static function render_for_post( int $post_id ): string {
		$links = Outpost_Manual_Share_Syndication_Writeback::get_links( $post_id );
		if ( empty( $links ) ) {
			return '';
		}

		/**
		 * Filter whether to render syndication-links HTML for a post.
		 *
		 * Default true. Sites that emit u-syndication via a custom
		 * template should return false to disable Outpost's auto-render.
		 *
		 * @param bool $should_render Default true.
		 * @param int  $post_id       Post id.
		 * @param array $links         Syndication link entries.
		 */
		$should_render = (bool) apply_filters(
			'outpost_render_syndication_links',
			true,
			$post_id,
			$links
		);
		if ( ! $should_render ) {
			return '';
		}

		$anchors = '';
		foreach ( $links as $entry ) {
			$url   = (string) ( $entry['url'] ?? '' );
			$label = self::label_for( (string) ( $entry['platform_id'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$anchors .= sprintf(
				'<a class="u-syndication outpost-u-syndication-link" href="%s" rel="syndication">%s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}
		if ( '' === $anchors ) {
			return '';
		}

		return sprintf(
			'<div class="outpost-syndication-links" hidden>%s</div>',
			$anchors
		);
	}

	private static function label_for( string $platform_id ): string {
		if ( isset( self::LABELS[ $platform_id ] ) ) {
			return self::LABELS[ $platform_id ];
		}
		// Unknown platform (custom site-registered) — derive a
		// human-readable label from the id.
		return ucwords( str_replace( '-', ' ', $platform_id ) );
	}
}
