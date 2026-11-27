<?php
/**
 * Outpost_Image_Block_Uses
 *
 * Reads the core/image blocks in post content: which attachment each one
 * shows and the alt that use carries. The same attachment can appear in many
 * posts with a different alt in each, so the per-use alt, not the
 * attachment's shared `_wp_attachment_image_alt`, is what describes the
 * image in that post. An explicit `alt=""` stays `''`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Image_Block_Uses {

	/**
	 * Every core/image block with an attachment ID, in document order,
	 * including blocks nested in galleries and groups.
	 *
	 * The alt comes from the block's `<img alt>` (the block sources it from
	 * there). Core saves that attribute double-quoted and escaped, so reading
	 * it back means decoding the entities.
	 *
	 * @param string $content Post content.
	 * @return list<array{id: int, alt: string, decorative: bool}>
	 */
	public static function in_content( string $content ): array {
		if ( ! str_contains( $content, '<!-- wp:image' ) ) {
			return array();
		}
		return self::collect( parse_blocks( $content ) );
	}

	/**
	 * Walk parsed blocks depth-first.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return list<array{id: int, alt: string, decorative: bool}>
	 */
	private static function collect( array $blocks ): array {
		$uses = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$id    = (int) ( $attrs['id'] ?? 0 );
			if ( 'core/image' === ( $block['blockName'] ?? null ) && $id > 0 ) {
				$uses[] = array(
					'id'         => $id,
					'alt'        => self::img_alt( (string) ( $block['innerHTML'] ?? '' ) ),
					'decorative' => true === ( $attrs['isDecorative'] ?? false ),
				);
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$uses = array_merge( $uses, self::collect( $block['innerBlocks'] ) );
			}
		}
		return $uses;
	}

	/**
	 * The `alt` of the first `<img>` in a block's markup, or '' without one.
	 *
	 * @param string $html Block inner HTML.
	 * @return string
	 */
	private static function img_alt( string $html ): string {
		if ( ! preg_match( '/<img\b[^>]*?\salt="([^"]*)"/i', $html, $matches ) ) {
			return '';
		}
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
