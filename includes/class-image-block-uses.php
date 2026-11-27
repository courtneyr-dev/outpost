<?php
/**
 * Outpost_Image_Block_Uses
 *
 * Reads the image blocks in post content: which attachment each one shows
 * and the alt that use carries. The same attachment can appear in many
 * posts with a different alt in each, so the per-use alt, not the
 * attachment's shared `_wp_attachment_image_alt`, is what describes the
 * image in that post. An explicit `alt=""` stays `''`; an `<img>` with no
 * `alt` attribute at all gives `null`, so a caller can fall back to the
 * shared alt.
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
	 * Blocks whose `<img>` alt ActivityPub 9.4.4 reads
	 * (Transformer\Post::get_media_from_blocks()), each mapped to the
	 * attribute that holds the attachment ID.
	 */
	private const ACTIVITYPUB_BLOCKS = array(
		'core/image'      => 'id',
		'core/cover'      => 'id',
		'core/media-text' => 'mediaId',
	);

	/**
	 * Every core/image block with an attachment ID, in document order,
	 * including blocks nested in galleries and groups.
	 *
	 * @param string $content Post content.
	 * @return list<array{id: int, alt: ?string, decorative: bool}>
	 */
	public static function in_content( string $content ): array {
		if ( ! str_contains( $content, '<!-- wp:image' ) ) {
			return array();
		}
		return self::collect( parse_blocks( $content ), array( 'core/image' => 'id' ) );
	}

	/**
	 * For each attachment, the block use whose alt ActivityPub 9.4.4 sends.
	 *
	 * ActivityPub walks inner blocks before their parent and, when an
	 * attachment ID comes up again, overwrites the alt it collected with the
	 * later block's. The last use in that order wins.
	 *
	 * @param string $content Post content.
	 * @return array<int, array{id: int, alt: ?string, decorative: bool}> Keyed by attachment ID.
	 */
	public static function activitypub_selected( string $content ): array {
		$selected = array();
		foreach ( self::collect( parse_blocks( $content ), self::ACTIVITYPUB_BLOCKS ) as $use ) {
			$selected[ $use['id'] ] = $use;
		}
		return $selected;
	}

	/**
	 * Walk parsed blocks, inner blocks before their parent (ActivityPub's
	 * order; for core/image alone it matches document order, since an image
	 * block holds no image blocks).
	 *
	 * @param array<int, mixed>     $blocks Parsed blocks.
	 * @param array<string, string> $names  Block name => attribute holding the attachment ID.
	 * @return list<array{id: int, alt: ?string, decorative: bool}>
	 */
	private static function collect( array $blocks, array $names ): array {
		$uses = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$uses = array_merge( $uses, self::collect( $block['innerBlocks'], $names ) );
			}
			$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$id    = isset( $names[ $name ] ) ? (int) ( $attrs[ $names[ $name ] ] ?? 0 ) : 0;
			if ( $id <= 0 || ( 'core/media-text' === $name && 'video' === ( $attrs['mediaType'] ?? 'image' ) ) ) {
				continue;
			}
			$uses[] = array(
				'id'         => $id,
				'alt'        => self::img_alt( (string) ( $block['innerHTML'] ?? '' ) ),
				'decorative' => 'core/image' === $name && true === ( $attrs['isDecorative'] ?? false ),
			);
		}
		return $uses;
	}

	/**
	 * The `alt` of the first `<img>` in a block's markup: '' for an explicit
	 * empty (or valueless) alt, null when there is no `<img>` or it has no
	 * `alt` attribute.
	 *
	 * @param string $html Block inner HTML.
	 * @return ?string
	 */
	private static function img_alt( string $html ): ?string {
		$tags = new WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
			return null;
		}
		$alt = $tags->get_attribute( 'alt' );
		if ( null === $alt ) {
			return null;
		}
		return true === $alt ? '' : (string) $alt;
	}
}
