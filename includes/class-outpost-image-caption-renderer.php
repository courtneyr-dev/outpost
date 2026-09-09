<?php
/**
 * Outpost_Image_Caption_Renderer
 *
 * Renders an image's caption on the front end from the attachment.
 *
 * Core's Image block emits a `<figcaption>` only when the caption is inside
 * the block's own markup. It never looks the attachment up at render time. So
 * a caption written to the attachment — by Outpost's Micropub bridge, or by
 * hand in the media library — shows nowhere until something puts it into the
 * rendered figure. This does that, at render time, without touching the stored
 * post content.
 *
 * Rendering rather than rewriting the post is deliberate. The attachment stays
 * the single source of truth, editing a caption in the media library is
 * immediately reflected everywhere, and posts published before captions
 * existed pick theirs up with no migration.
 *
 * A caption the author wrote in the block editor always wins: a figure that
 * already has a `<figcaption>` is returned untouched.
 *
 * @package Outpost
 * @since   1.0.14
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Image_Caption_Renderer {

	/**
	 * Hook the block filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'render_block', array( __CLASS__, 'filter_block' ), 10, 2 );
	}

	/**
	 * Inject the attachment's caption into a rendered Image block.
	 *
	 * @param string              $block_content Rendered block HTML.
	 * @param array<string,mixed> $block         Parsed block, including attrs.
	 * @return string
	 */
	public static function filter_block( string $block_content, array $block ): string {
		if ( ( $block['blockName'] ?? '' ) !== 'core/image' ) {
			return $block_content;
		}
		// An image dropped in without a media-library attachment has no id and
		// therefore no caption to find.
		$attachment_id = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;
		if ( $attachment_id <= 0 ) {
			return $block_content;
		}
		// A caption written in the editor is the author's explicit choice for
		// this post and outranks the attachment's.
		if ( false !== strpos( $block_content, '<figcaption' ) ) {
			return $block_content;
		}
		$caption = trim( (string) get_post_field( 'post_excerpt', $attachment_id ) );
		if ( '' === $caption ) {
			return $block_content;
		}
		$position = strrpos( $block_content, '</figure>' );
		if ( false === $position ) {
			return $block_content;
		}

		$figcaption = '<figcaption class="wp-element-caption">'
			. wp_kses_post( $caption )
			. '</figcaption>';

		return substr_replace( $block_content, $figcaption, $position, 0 );
	}
}
