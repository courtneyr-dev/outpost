<?php
/**
 * Outpost_Request_Headers — the single place raw request metadata is read.
 *
 * Every REST endpoint used to open-code the same Authorization-header
 * dance (HTTP_AUTHORIZATION with the REDIRECT_ fallback that managed
 * hosts produce) plus assorted `$_SERVER` reads for rate-limit keys and
 * request routing. Centralizing the superglobal access gives one audited,
 * documented exemption from the ValidatedSanitizedInput sniffs instead of
 * forty scattered ones.
 *
 * @package Outpost
 * @since   1.0.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Request_Headers {

	/**
	 * Raw Authorization header, with the REDIRECT_HTTP_AUTHORIZATION
	 * fallback some Apache/managed-host setups use.
	 *
	 * The value is a credential: callers regex-validate its shape and
	 * compare tokens — it is never stored or echoed. It is unslashed but
	 * deliberately NOT run through a sanitizer, because sanitizers can
	 * alter token bytes and break constant-time comparison.
	 *
	 * @since 1.0.1
	 *
	 * @return string Header value, or '' when absent.
	 */
	public static function authorization(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credential compared/validated by callers; sanitizing would corrupt it. Unslashed here, never stored or output.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return Outpost_Request_Headers::server_string( 'HTTP_AUTHORIZATION' );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return Outpost_Request_Headers::server_string( 'REDIRECT_HTTP_AUTHORIZATION' );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return '';
	}

	/**
	 * A `$_SERVER` value as an unslashed string.
	 *
	 * For request metadata (REQUEST_URI, REQUEST_METHOD, REMOTE_ADDR,
	 * HTTP_USER_AGENT, proxy IP headers) used for routing checks,
	 * rate-limit keys, and diagnostics. Callers validate per use —
	 * e.g. strpos route matching or IP-format checks — so no generic
	 * sanitizer is applied here.
	 *
	 * @since 1.0.1
	 *
	 * @param string $key    `$_SERVER` key.
	 * @param string $fallback Value when the key is absent.
	 * @return string
	 */
	public static function server_string( string $key, string $fallback = '' ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed request metadata; callers validate per use (route matching, IP keying). Never stored or output raw.
		return (string) wp_unslash( $_SERVER[ $key ] );
	}
}
