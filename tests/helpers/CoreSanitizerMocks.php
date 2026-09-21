<?php
/**
 * WP_Mock models of the core sanitizers the share paths touch.
 *
 * Each model reproduces what WordPress 7.1 returned for the inputs in
 * `CoreSanitizerMocksTest` (recorded 2026-09-21 by running the real
 * functions). Two behaviors matter most, because a looser mock hides a
 * production bug:
 *
 *   - sanitize_text_field() / sanitize_textarea_field() delete every %XX
 *     octet, which rewrites an encoded link.
 *   - wp_kses() and esc_url_raw() are a fatal TypeError on a non-string
 *     (`?text[]=`), not an empty string.
 *
 * @package Outpost\Tests\Helpers
 */

declare(strict_types=1);

namespace Outpost\Tests\Helpers;

use WP_Mock;

final class CoreSanitizerMocks {

	/**
	 * Every esc_url_raw() call since register(), as `[ value, protocols ]`.
	 *
	 * @var array<int, array{0:mixed, 1:mixed}>
	 */
	public static array $esc_url_raw_calls = array();

	/**
	 * Register every model with WP_Mock. Call after WP_Mock::setUp().
	 */
	public static function register(): void {
		self::$esc_url_raw_calls = array();
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( array( self::class, 'sanitize_text_field' ) );
		WP_Mock::userFunction( 'sanitize_textarea_field' )->andReturnUsing( array( self::class, 'sanitize_textarea_field' ) );
		WP_Mock::userFunction( 'wp_check_invalid_utf8' )->andReturnUsing( array( self::class, 'wp_check_invalid_utf8' ) );
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( array( self::class, 'wp_kses' ) );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( array( self::class, 'esc_url_raw' ) );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_text_field( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return self::strip_octets( (string) preg_replace( '/[\r\n\t ]+/', ' ', self::text_fields_head( $value ) ) );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_textarea_field( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return self::strip_octets( self::text_fields_head( $value ) );
	}

	/**
	 * Core returns '' for a string that is not valid UTF-8.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function wp_check_invalid_utf8( $value ): string {
		$value = (string) $value;
		return mb_check_encoding( $value, 'UTF-8' ) ? $value : '';
	}

	/**
	 * wp_kses() with no allowed tags.
	 *
	 * @param mixed $value        Raw value.
	 * @param mixed $allowed_html Ignored; the share paths allow nothing.
	 */
	public static function wp_kses( $value, $allowed_html = array() ): string {
		if ( ! is_string( $value ) ) {
			throw new \TypeError( 'str_contains(): Argument #1 ($haystack) must be of type string, array given' );
		}
		// wp_kses_no_null(): C0 controls go, tab / newline / return stay.
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		$value = strip_tags( self::escape_unclosed_less_than( $value ) );
		$value = str_replace( '>', '&gt;', $value );
		// wp_kses_normalize_entities(): a bare ampersand becomes &amp;.
		return (string) preg_replace( '/&(?!(?:[a-z][a-z0-9]*|#[0-9]+|#x[0-9a-f]+);)/i', '&amp;', $value );
	}

	/**
	 * @param mixed $value     Raw value.
	 * @param mixed $protocols Allowed protocols.
	 */
	public static function esc_url_raw( $value, $protocols = null ): string {
		self::$esc_url_raw_calls[] = array( $value, $protocols );
		// Core's esc_url() calls ltrim() on its argument.
		if ( ! is_string( $value ) ) {
			throw new \TypeError( 'ltrim(): Argument #1 ($string) must be of type string, array given' );
		}
		return 1 === preg_match( '#^https?://\S+$#i', $value ) ? $value : '';
	}

	/**
	 * The part of _sanitize_text_fields() that runs before whitespace
	 * handling: UTF-8 check, then tag stripping when a `<` is present.
	 */
	private static function text_fields_head( string $value ): string {
		$value = self::wp_check_invalid_utf8( $value );
		if ( str_contains( $value, '<' ) ) {
			$value = strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', self::escape_unclosed_less_than( $value ) ) );
		}
		return $value;
	}

	/**
	 * wp_pre_kses_less_than(): a `<` that never closes is text, not a tag.
	 */
	private static function escape_unclosed_less_than( string $value ): string {
		return (string) preg_replace_callback(
			'%<[^>]*?((?=<)|>|$)%',
			static fn( array $m ): string => str_contains( $m[0], '>' ) ? $m[0] : htmlspecialchars( $m[0], ENT_QUOTES ),
			$value
		);
	}

	/**
	 * The loop at the end of _sanitize_text_fields() that deletes %XX.
	 */
	private static function strip_octets( string $value ): string {
		$value = trim( $value );
		$found = false;
		while ( 1 === preg_match( '/%[a-f0-9]{2}/i', $value, $match ) ) {
			$value = str_replace( $match[0], '', $value );
			$found = true;
		}
		return $found ? trim( (string) preg_replace( '/ +/', ' ', $value ) ) : $value;
	}
}
