<?php
/**
 * Test stub for Post Kinds' public `pkiw_get_safe_title()` (PKIW 1.9.0+,
 * includes/functions-privacy.php).
 *
 * Post Kinds isn't installed in wp-env, so integration tests that check
 * Outpost honours Post Kinds' title privacy load this stub. It follows the
 * documented contract: the raw stored title, or the "Check-in, <date>"
 * fallback when the title names a hidden location. The test marks a post's
 * title as hidden by storing the fallback in post meta.
 *
 * @package Outpost\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'pkiw_get_safe_title' ) ) {
	/**
	 * Safe title for a post, per the Post Kinds contract.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function pkiw_get_safe_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$fallback = (string) get_post_meta( $post_id, '_outpost_test_pkiw_safe_title', true );
		return '' !== $fallback ? $fallback : (string) $post->post_title;
	}
}
