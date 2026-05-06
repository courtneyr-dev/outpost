<?php
/**
 * Outpost_Notion_Blocks_Converter (G3.5a — MVP).
 *
 * Bidirectional conversion between WordPress block markup and Notion
 * block objects. v1 ships the minimum viable mapping table:
 *
 *   WP                          Notion
 *   ----                        ------
 *   core/paragraph              paragraph
 *   core/heading (level 1)      heading_1
 *   core/heading (level 2)      heading_2
 *   core/heading (level 3-6)    heading_3  (Notion has only 3 levels;
 *                                           4-6 collapse with debug log)
 *   core/quote                  quote
 *   core/code                   code
 *   core/list (unordered)       bulleted_list_item × N
 *   core/list (ordered)         numbered_list_item × N
 *   core/image                  image (external URL)
 *   anything else               paragraph (text passthrough; debug log)
 *
 * Lossy operations are documented inline; debug-level error_log
 * entries surface them when WP_DEBUG is on.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Notion_Blocks_Converter {

	/**
	 * WP block markup → Notion block array.
	 *
	 * @since 0.1.69
	 *
	 * @param string $wp_block_markup Serialized WP block markup.
	 * @return array<int,array<string,mixed>> Notion block array.
	 */
	public static function wp_to_notion( string $wp_block_markup ): array {
		$blocks = parse_blocks( $wp_block_markup );
		$out    = array();
		foreach ( $blocks as $block ) {
			$converted = self::wp_block_to_notion( $block );
			if ( null === $converted ) {
				continue;
			}
			if ( isset( $converted[0] ) && is_array( $converted[0] ) ) {
				// Multi-block return (list expansion).
				foreach ( $converted as $b ) {
					$out[] = $b;
				}
				continue;
			}
			$out[] = $converted;
		}
		return $out;
	}

	/**
	 * Notion block array → WP block markup.
	 *
	 * @since 0.1.69
	 *
	 * @param array<int,array<string,mixed>> $notion_blocks Notion block array.
	 * @return string Serialized WP block markup.
	 */
	public static function notion_to_wp( array $notion_blocks ): string {
		$out = array();
		foreach ( $notion_blocks as $notion_block ) {
			$serialized = self::notion_block_to_wp( $notion_block );
			if ( '' !== $serialized ) {
				$out[] = $serialized;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Convert one parsed WP block. Returns null when the block has no
	 * content; an array describing one Notion block; or a list of
	 * Notion blocks (for list expansion).
	 *
	 * @param array<string,mixed> $block Parsed WP block.
	 * @return array<string,mixed>|array<int,array<string,mixed>>|null
	 */
	private static function wp_block_to_notion( array $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		$html = trim( (string) ( $block['innerHTML'] ?? '' ) );

		switch ( $name ) {
			case 'core/paragraph':
				$text = trim( wp_strip_all_tags( $html ) );
				if ( '' === $text ) {
					return null;
				}
				return self::notion_text_block( 'paragraph', $text );
			case 'core/heading':
				$level = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;
				if ( $level >= 4 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
					error_log( 'Outpost Notion converter: heading level ' . $level . ' collapses to heading_3 (Notion has only 3 levels).' );
				}
				$type = ( 1 === $level ) ? 'heading_1' : ( ( 2 === $level ) ? 'heading_2' : 'heading_3' );
				return self::notion_text_block( $type, trim( wp_strip_all_tags( $html ) ) );
			case 'core/quote':
				return self::notion_text_block( 'quote', trim( wp_strip_all_tags( $html ) ) );
			case 'core/code':
				return self::notion_code_block( trim( wp_strip_all_tags( $html ) ) );
			case 'core/list':
				$ordered = ! empty( $block['attrs']['ordered'] );
				$type    = $ordered ? 'numbered_list_item' : 'bulleted_list_item';
				return self::notion_list_blocks( $type, $html );
			case 'core/image':
				$src = '';
				if ( preg_match( '~<img[^>]+src="([^"]+)"~i', $html, $m ) ) {
					$src = $m[1];
				}
				if ( '' === $src ) {
					return null;
				}
				return array(
					'object' => 'block',
					'type'   => 'image',
					'image'  => array(
						'type'     => 'external',
						'external' => array( 'url' => $src ),
					),
				);
			case '':
				return null;
			default:
				$text = trim( wp_strip_all_tags( $html ) );
				if ( '' === $text ) {
					return null;
				}
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
					error_log( 'Outpost Notion converter: unsupported WP block "' . $name . '" passed through as paragraph.' );
				}
				return self::notion_text_block( 'paragraph', $text );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function notion_text_block( string $type, string $text ): array {
		return array(
			'object' => 'block',
			'type'   => $type,
			$type    => array(
				'rich_text' => array(
					array(
						'type' => 'text',
						'text' => array( 'content' => $text ),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function notion_code_block( string $code ): array {
		return array(
			'object' => 'block',
			'type'   => 'code',
			'code'   => array(
				'rich_text' => array(
					array(
						'type' => 'text',
						'text' => array( 'content' => $code ),
					),
				),
				'language'  => 'plain text',
			),
		);
	}

	/**
	 * Expand a WP list block's <li> children into N Notion list-item blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function notion_list_blocks( string $type, string $html ): array {
		$items = array();
		if ( preg_match_all( '~<li\b[^>]*>(.*?)</li>~is', $html, $matches ) ) {
			foreach ( $matches[1] as $item ) {
				$text = trim( wp_strip_all_tags( (string) $item ) );
				if ( '' === $text ) {
					continue;
				}
				$items[] = self::notion_text_block( $type, $text );
			}
		}
		return $items;
	}

	/**
	 * Convert one Notion block to serialized WP block markup. Returns
	 * empty string for blocks we don't render.
	 *
	 * @param array<string,mixed> $block Notion block.
	 */
	private static function notion_block_to_wp( array $block ): string {
		$type = (string) ( $block['type'] ?? '' );
		switch ( $type ) {
			case 'paragraph':
				$text = self::extract_rich_text( $block, 'paragraph' );
				return self::wp_serialize_paragraph( $text );
			case 'heading_1':
				return self::wp_serialize_heading( 1, self::extract_rich_text( $block, 'heading_1' ) );
			case 'heading_2':
				return self::wp_serialize_heading( 2, self::extract_rich_text( $block, 'heading_2' ) );
			case 'heading_3':
				return self::wp_serialize_heading( 3, self::extract_rich_text( $block, 'heading_3' ) );
			case 'quote':
				return self::wp_serialize_quote( self::extract_rich_text( $block, 'quote' ) );
			case 'bulleted_list_item':
				return self::wp_serialize_list_item( false, self::extract_rich_text( $block, 'bulleted_list_item' ) );
			case 'numbered_list_item':
				return self::wp_serialize_list_item( true, self::extract_rich_text( $block, 'numbered_list_item' ) );
			case 'code':
				$text = self::extract_rich_text( $block, 'code' );
				return '<!-- wp:code --><pre class="wp-block-code"><code>' . esc_html( $text ) . '</code></pre><!-- /wp:code -->';
			case 'image':
				$src = (string) ( $block['image']['external']['url'] ?? $block['image']['file']['url'] ?? '' );
				if ( '' === $src ) {
					return '';
				}
				return '<!-- wp:image --><figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt=""/></figure><!-- /wp:image -->';
			default:
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
					error_log( 'Outpost Notion converter: unsupported Notion type "' . $type . '" passed through as empty paragraph.' );
				}
				return '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
		}
	}

	/**
	 * Pull the plain text out of a Notion block's rich_text array.
	 *
	 * @param array<string,mixed> $block      Notion block.
	 * @param string              $field_name e.g. 'paragraph'.
	 */
	private static function extract_rich_text( array $block, string $field_name ): string {
		$container = $block[ $field_name ] ?? array();
		if ( ! is_array( $container ) || ! isset( $container['rich_text'] ) || ! is_array( $container['rich_text'] ) ) {
			return '';
		}
		$out = '';
		foreach ( $container['rich_text'] as $rt ) {
			if ( is_array( $rt ) && isset( $rt['plain_text'] ) ) {
				$out .= (string) $rt['plain_text'];
			} elseif ( is_array( $rt ) && isset( $rt['text']['content'] ) ) {
				$out .= (string) $rt['text']['content'];
			}
		}
		return $out;
	}

	private static function wp_serialize_paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	private static function wp_serialize_heading( int $level, string $text ): string {
		$attr = ( 2 === $level ) ? '' : '{"level":' . $level . '} ';
		return '<!-- wp:heading ' . $attr . '--><h' . $level . '>' . esc_html( $text ) . '</h' . $level . '><!-- /wp:heading -->';
	}

	private static function wp_serialize_quote( string $text ): string {
		return '<!-- wp:quote --><blockquote class="wp-block-quote"><p>' . esc_html( $text ) . '</p></blockquote><!-- /wp:quote -->';
	}

	private static function wp_serialize_list_item( bool $ordered, string $text ): string {
		$tag  = $ordered ? 'ol' : 'ul';
		$attr = $ordered ? '{"ordered":true} ' : '';
		return '<!-- wp:list ' . $attr . '--><' . $tag . '><li>' . esc_html( $text ) . '</li></' . $tag . '><!-- /wp:list -->';
	}
}
