<?php
/**
 * Outpost_POSSE_Content_Transformer (G5).
 *
 * Shared content-shaping helpers for POSSE destinations.
 *
 *   - HTML pipeline:  do_blocks() -> wpautop() -> append canonical
 *     paragraph. Used by Beehiiv and Kit (their APIs take HTML).
 *   - Markdown pipeline: do_blocks() -> wpautop() -> minimal
 *     HTML-to-markdown -> append canonical paragraph. Used by
 *     Buttondown and write.as.
 *
 * The HTML-to-markdown converter is deliberately minimal — it handles
 * what Gutenberg's core blocks emit (p, h1-h6, a, strong, em, code,
 * pre, ul/ol/li, blockquote, br, img) and passes other tags through
 * stripped. A richer converter (e.g. league/html-to-markdown) is a
 * later concern; the four G5 destinations don't need fidelity beyond
 * what's listed here.
 *
 * The canonical-link paragraph is appended to every syndicated copy
 * for destinations whose API has no `canonical_url` field. Buttondown
 * uses its native `canonical_url` field and does NOT call the
 * append helpers.
 *
 * @package Outpost
 * @since   0.1.100
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Content_Transformer {

	/**
	 * Build HTML body for the given post with canonical paragraph
	 * appended. Suitable for Beehiiv body_content / Kit content fields.
	 *
	 * @since 0.1.100
	 */
	public static function to_html_with_canonical( int $post_id ): string {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return '';
		}
		$html = wpautop( do_blocks( (string) $post->post_content ) );
		return $html . self::canonical_paragraph_html( get_permalink( $post_id ) );
	}

	/**
	 * Build markdown body for the given post with canonical paragraph
	 * appended. Suitable for Buttondown body (when no canonical_url
	 * field used) and write.as body.
	 *
	 * @since 0.1.100
	 */
	public static function to_markdown_with_canonical( int $post_id ): string {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return '';
		}
		$html     = wpautop( do_blocks( (string) $post->post_content ) );
		$markdown = self::html_to_markdown( $html );
		return $markdown . "\n\n" . self::canonical_paragraph_markdown( get_permalink( $post_id ) );
	}

	/**
	 * Build markdown body for the given post WITHOUT appending the
	 * canonical paragraph. Used by Buttondown, which carries canonical
	 * info in its dedicated `canonical_url` field instead.
	 *
	 * @since 0.1.100
	 */
	public static function to_markdown_only( int $post_id ): string {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return '';
		}
		$html = wpautop( do_blocks( (string) $post->post_content ) );
		return self::html_to_markdown( $html );
	}

	/**
	 * Canonical-link paragraph in HTML. Wraps the WP post URL inside a
	 * small italicized anchor, mirroring the "originally appeared on…"
	 * pattern most IndieWeb POSSE workflows use.
	 *
	 * @since 0.1.100
	 */
	public static function canonical_paragraph_html( string $wp_url ): string {
		$safe = esc_url( $wp_url );
		if ( '' === $safe ) {
			return '';
		}
		return sprintf(
			'<p><small>%1$s <a href="%2$s">%2$s</a>.</small></p>',
			esc_html__( 'This post originally appeared on', 'outpost-mobile-publishing' ),
			$safe
		);
	}

	/**
	 * Canonical-link paragraph in markdown form.
	 *
	 * @since 0.1.100
	 */
	public static function canonical_paragraph_markdown( string $wp_url ): string {
		if ( '' === $wp_url ) {
			return '';
		}
		return sprintf(
			'*%1$s [%2$s](%2$s).*',
			__( 'This post originally appeared on', 'outpost-mobile-publishing' ),
			$wp_url
		);
	}

	/**
	 * Minimal HTML-to-markdown converter. Handles the tag set Gutenberg
	 * core blocks emit; unknown tags are stripped (their text content
	 * is preserved).
	 *
	 * Not a general-purpose converter — accept the limits documented in
	 * the class docblock and either swap in league/html-to-markdown
	 * later or extend the cases below as new tags surface.
	 *
	 * @since 0.1.100
	 */
	public static function html_to_markdown( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// Normalize whitespace inside block-level tags before structural
		// rewrites; preserves intent without leaving stray newlines that
		// breaks list parsing downstream.
		$out = $html;
		$out = preg_replace( '#\s*<br\s*/?>\s*#i', "\n", $out ) ?? $out;

		// Headings.
		for ( $level = 1; $level <= 6; $level++ ) {
			$prefix = str_repeat( '#', $level );
			$out    = preg_replace_callback(
				'#<h' . $level . '\b[^>]*>(.*?)</h' . $level . '>#is',
				static function ( $m ) use ( $prefix ) {
					return "\n\n" . $prefix . ' ' . trim( wp_strip_all_tags( (string) $m[1] ) ) . "\n\n";
				},
				$out
			) ?? $out;
		}

		// Bold / italic / inline code.
		$out = preg_replace( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $out ) ?? $out;
		$out = preg_replace( '#<(em|i)\b[^>]*>(.*?)</\1>#is', '*$2*', $out ) ?? $out;
		$out = preg_replace( '#<code\b[^>]*>(.*?)</code>#is', '`$1`', $out ) ?? $out;

		// Pre/code blocks: <pre><code>...</code></pre> or just <pre>.
		$out = preg_replace_callback(
			'#<pre\b[^>]*>(.*?)</pre>#is',
			static function ( $m ) {
				$inner = preg_replace( '#</?code\b[^>]*>#i', '', (string) $m[1] ) ?? (string) $m[1];
				return "\n\n```\n" . trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . "\n```\n\n";
			},
			$out
		) ?? $out;

		// Blockquotes.
		$out = preg_replace_callback(
			'#<blockquote\b[^>]*>(.*?)</blockquote>#is',
			static function ( $m ) {
				$inner = trim( wp_strip_all_tags( (string) $m[1] ) );
				$split = preg_split( '/\r\n|\r|\n/', $inner );
				$lines = is_array( $split ) && array() !== $split ? $split : array( $inner );
				return "\n\n" . implode( "\n", array_map( static fn( $line ) => '> ' . $line, $lines ) ) . "\n\n";
			},
			$out
		) ?? $out;

		// Images.
		$out = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) {
				$tag = (string) $m[0];
				preg_match( '#\bsrc=("|\')([^"\']+)\1#i', $tag, $src_match );
				preg_match( '#\balt=("|\')([^"\']*)\1#i', $tag, $alt_match );
				$src = $src_match[2] ?? '';
				$alt = $alt_match[2] ?? '';
				if ( '' === $src ) {
					return '';
				}
				return sprintf( '![%s](%s)', $alt, $src );
			},
			$out
		) ?? $out;

		// Links.
		$out = preg_replace_callback(
			'#<a\b[^>]*\bhref=("|\')([^"\']+)\1[^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$text = trim( wp_strip_all_tags( (string) $m[3] ) );
				return sprintf( '[%s](%s)', '' === $text ? (string) $m[2] : $text, (string) $m[2] );
			},
			$out
		) ?? $out;

		// Lists. Convert ordered/unordered list items; nested lists are
		// flattened (acceptable for newsletter syndication copy).
		$out = preg_replace_callback(
			'#<ol\b[^>]*>(.*?)</ol>#is',
			static function ( $m ) {
				return self::convert_list( (string) $m[1], true );
			},
			$out
		) ?? $out;
		$out = preg_replace_callback(
			'#<ul\b[^>]*>(.*?)</ul>#is',
			static function ( $m ) {
				return self::convert_list( (string) $m[1], false );
			},
			$out
		) ?? $out;

		// Paragraphs -> bare text with blank-line separators.
		$out = preg_replace( '#<p\b[^>]*>#i', '', $out ) ?? $out;
		$out = preg_replace( '#</p>#i', "\n\n", $out ) ?? $out;

		// Strip any remaining tags but keep their text content.
		$out = wp_strip_all_tags( $out );

		// Decode entities, normalize newlines.
		$out = html_entity_decode( $out, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out = preg_replace( "/\n{3,}/", "\n\n", $out ) ?? $out;
		return trim( $out );
	}

	/**
	 * Helper for html_to_markdown(): convert a list body to markdown.
	 *
	 * @since 0.1.100
	 */
	private static function convert_list( string $body, bool $ordered ): string {
		preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $body, $matches );
		$items = array();
		$idx   = 1;
		foreach ( (array) $matches[1] as $raw ) {
			$text    = trim( wp_strip_all_tags( (string) $raw ) );
			$bullet  = $ordered ? ( $idx . '.' ) : '-';
			$items[] = $bullet . ' ' . $text;
			++$idx;
		}
		return "\n\n" . implode( "\n", $items ) . "\n\n";
	}
}
