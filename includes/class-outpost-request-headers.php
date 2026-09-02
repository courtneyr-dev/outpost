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
			return self::server_string( 'HTTP_AUTHORIZATION' );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return self::server_string( 'REDIRECT_HTTP_AUTHORIZATION' );
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

	/**
	 * The REST route WordPress resolved for this request, normalized for exact
	 * comparison, or null when none was resolved.
	 *
	 * This is the ONLY route identity a security decision may key on.
	 * WordPress dispatches on `$GLOBALS['wp']->query_vars['rest_route']`, which
	 * `$_GET`/`$_POST` override ahead of the `/wp-json/` permalink rewrite
	 * (`WP::parse_request()`), so REQUEST_URI, path substrings, and hand-parsed
	 * query strings can all disagree with the route that actually runs. The
	 * pre-1.0.4 audit fired exactly that differential: a request to the
	 * composer-config PATH carrying `?rest_route=/wp/v2/posts/N&_method=DELETE`
	 * cleared core's invalid-nonce error for the posts route.
	 *
	 * `WP::parse_request()` populates the query var before `rest_api_loaded()`
	 * serves the request, so every `rest_authentication_errors` callback and
	 * every permission callback sees the authoritative value. Callers that can
	 * run earlier (e.g. `determine_current_user` at `init`) must not rely on
	 * this and should scope themselves by construction instead — see
	 * `Outpost_Bearer_Auth`.
	 *
	 * Normalization: one leading slash, no trailing slash, no surrounding
	 * whitespace. Anything else (absent, empty, non-string) yields null so
	 * callers fail closed.
	 *
	 * @since 1.0.4
	 *
	 * @return string|null
	 */
	public static function resolved_rest_route(): ?string {
		if ( ! isset( $GLOBALS['wp'] ) || ! is_object( $GLOBALS['wp'] ) ) {
			return null;
		}
		$vars = $GLOBALS['wp']->query_vars ?? null;
		if ( ! is_array( $vars ) ) {
			return null;
		}
		$route = $vars['rest_route'] ?? null;
		if ( ! is_string( $route ) ) {
			return null;
		}
		$route = trim( $route, " \t\n\r\0\x0B/" );
		if ( '' === $route ) {
			return null;
		}
		return '/' . $route;
	}

	/**
	 * Whether the resolved REST route is exactly `$route`.
	 *
	 * Exact comparison after normalizing both sides; never a prefix or
	 * substring test. Fails closed (false) when no route was resolved.
	 *
	 * @since 1.0.4
	 *
	 * @param string $route Route to compare, e.g. `/outpost/v1/composer-config`
	 *                      (leading/trailing slashes optional).
	 * @return bool
	 */
	public static function is_rest_route( string $route ): bool {
		$resolved = self::resolved_rest_route();
		if ( null === $resolved ) {
			return false;
		}
		$expected = trim( $route, " \t\n\r\0\x0B/" );
		if ( '' === $expected ) {
			return false;
		}
		return '/' . $expected === $resolved;
	}
}
