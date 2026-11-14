<?php
/**
 * Outpost_Debug_Log — the plugin's only log path (T10-S3-10).
 *
 * Writes only when WP_DEBUG is defined as exactly `true`; with WP_DEBUG
 * false, undefined, or any other value, nothing is logged. Callers pass
 * bounded, non-sensitive fields only (a provider id, an operation, an HTTP
 * status, a stable error code), never a response body, raw error message,
 * error data, headers, credentials, cookies, or nonces; a static guard in
 * WporgReviewGuardsTest checks every call site. Each entry is one line,
 * capped in length.
 *
 * @package Outpost
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Debug_Log {

	private const MAX_LENGTH = 300;

	/** Whether diagnostics are on: WP_DEBUG defined as exactly true. */
	public static function enabled(): bool {
		return defined( 'WP_DEBUG' ) && true === WP_DEBUG;
	}

	/**
	 * Log one bounded diagnostic line when debugging is on.
	 *
	 * @param string $message Built only from bounded, non-sensitive fields.
	 */
	public static function write( string $message ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$line = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $message );
		if ( strlen( $line ) > self::MAX_LENGTH ) {
			$line = substr( $line, 0, self::MAX_LENGTH ) . '…';
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin's single debug-gated log path.
		error_log( 'Outpost: ' . $line );
	}

	/**
	 * The stable code of a WP_Error as a safe token, never its message or data.
	 *
	 * @param mixed $error Anything; only a WP_Error has a code.
	 */
	public static function error_code( $error ): string {
		if ( ! $error instanceof WP_Error ) {
			return 'unknown';
		}
		$code = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $error->get_error_code() ) );
		return '' === $code ? 'unknown' : substr( $code, 0, 64 );
	}
}
