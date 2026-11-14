<?php
/**
 * The Outpost PWA's IndieAuth client identity.
 *
 * The PWA signs in with `client_id` set to its own origin plus the composer
 * path (`pwa/src/index.tsx`: `window.location.origin + COMPOSER_URL`). The
 * composer path is fixed: the rewrite rules in Outpost_Route_Handler and the
 * PWA's COMPOSER_URL both use `/post/`, and nothing makes it configurable.
 * A guard test keeps COMPOSER_PATH and the PWA constant in step.
 *
 * Client identifiers are compared in one canonical absolute form: scheme,
 * host, effective port, and path. Origin alone never identifies a client;
 * two paths on one origin are two clients.
 *
 * @package Outpost
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Client_Identity {

	/** The composer path the PWA appends to its origin to form its client_id. */
	public const COMPOSER_PATH = '/post/';

	/**
	 * Canonical form of a client identifier, or null when it isn't an
	 * absolute http(s) URL Outpost can compare safely.
	 *
	 * Lowercases scheme and host, drops the default port, treats an empty
	 * path as `/`, uppercases percent-encoding hex digits and decodes the
	 * ones for unreserved characters (RFC 3986 §6.2.2). Rejects user info,
	 * a query, a fragment, whitespace, backslashes, and dot segments
	 * (including percent-encoded ones) rather than resolving them. The path
	 * is otherwise kept exactly, including case and a trailing slash.
	 *
	 * @param string $url Client identifier as presented.
	 */
	public static function canonical( string $url ): ?string {
		if ( '' === $url || 1 === preg_match( '/[\s\\\\?#]/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = null;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			return null;
		}
		$path = (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}/',
			static fn( array $m ): string => strtoupper( $m[0] ),
			$path
		);
		$path = (string) preg_replace_callback(
			'/%(?:4[1-9A-F]|5[0-9A]|6[1-9A-F]|7[0-9A]|3[0-9]|2D|2E|5F|7E)/',
			static fn( array $m ): string => rawurldecode( $m[0] ),
			$path
		);
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		return $scheme . '://' . $host . ( null !== $port ? ':' . $port : '' ) . $path;
	}

	/**
	 * This site's Outpost PWA client identifier, canonical: the origin of
	 * home_url() plus COMPOSER_PATH, as the PWA builds it.
	 */
	public static function outpost_client_id(): string {
		$home   = wp_parse_url( home_url() );
		$scheme = is_array( $home ) && isset( $home['scheme'] ) ? (string) $home['scheme'] : 'https';
		$host   = is_array( $home ) && isset( $home['host'] ) ? (string) $home['host'] : '';
		$port   = is_array( $home ) && isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		return (string) self::canonical( $scheme . '://' . $host . $port . self::COMPOSER_PATH );
	}

	/**
	 * Whether a verified client_id is exactly this site's Outpost PWA.
	 *
	 * @param mixed $client_id Verified client_id from IndieAuth (any type).
	 */
	public static function is_outpost_client( $client_id ): bool {
		if ( ! is_string( $client_id ) || '' === $client_id ) {
			return false;
		}
		$expected = self::outpost_client_id();
		return '' !== $expected && self::canonical( $client_id ) === $expected;
	}
}
