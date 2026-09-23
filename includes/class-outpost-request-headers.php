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
	 * fallback some Apache/managed-host setups use, then the SAPI's own
	 * header list for hosts that never copy the header into `$_SERVER`.
	 *
	 * IndieAuth's `Authorize::get_authorization_header()` reads the same
	 * three sources in the same order, so any header IndieAuth
	 * authenticates from is one Outpost can see too.
	 *
	 * The value is a credential: callers regex-validate its shape and
	 * compare tokens — it is never stored or echoed. It is sanitized like
	 * every other `$_SERVER` read. A bearer token is RFC 6750 `b64token`
	 * (`A-Za-z0-9-._~+/=`), which sanitize_text_field() returns unchanged,
	 * so only a malformed header is altered, and that header then fails
	 * token validation.
	 *
	 * @since 1.0.1
	 *
	 * @return string Header value, or '' when absent.
	 */
	public static function authorization(): string {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return self::server_string( 'HTTP_AUTHORIZATION' );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return self::server_string( 'REDIRECT_HTTP_AUTHORIZATION' );
		}
		return self::authorization_from_sapi_headers();
	}

	/**
	 * The Authorization header from `getallheaders()`, matched on name
	 * case-insensitively, or '' when the function or the header is absent.
	 *
	 * `getallheaders()` is not defined under the CLI SAPI, and its values
	 * never pass through wp_magic_quotes(), so there is nothing to unslash.
	 *
	 * @return string
	 */
	private static function authorization_from_sapi_headers(): string {
		$headers = function_exists( 'getallheaders' ) ? getallheaders() : null;
		if ( ! is_array( $headers ) ) {
			return '';
		}
		foreach ( $headers as $name => $value ) {
			if ( is_string( $value ) && 0 === strcasecmp( (string) $name, 'authorization' ) ) {
				return sanitize_text_field( $value );
			}
		}
		return '';
	}

	/**
	 * A `$_SERVER` value, unslashed and sanitized.
	 *
	 * For request metadata (REQUEST_METHOD, REMOTE_ADDR, HTTP_USER_AGENT,
	 * proxy IP headers, Authorization) used for routing checks, rate-limit
	 * keys, and diagnostics. The sender controls all of it. Callers still
	 * validate per use — IP-format checks, method allowlists, token
	 * comparison.
	 *
	 * Not for percent-encoded values such as REQUEST_URI:
	 * sanitize_text_field() strips `%XX` octets.
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
		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	}

	/**
	 * The REST nonce presented with this request, unslashed and sanitized, or
	 * '' when none was sent.
	 *
	 * Mirrors core's `rest_cookie_check_errors()` resolution order exactly: the
	 * `_wpnonce` request parameter first, then the `X-WP-Nonce` header. A
	 * security decision that has to re-derive whether a cookie session carries a
	 * valid nonce — because an earlier `rest_authentication_errors` filter made
	 * core short-circuit before its own check ran — must resolve the nonce the
	 * same way core would, or it will disagree with core on which requests are
	 * nonce-protected.
	 *
	 * This returns the raw nonce for the caller to pass to
	 * `wp_verify_nonce( ..., 'wp_rest' )`; reading it here is the read half of
	 * that verification, not a standalone processing of request input.
	 *
	 * @since 1.0.21
	 *
	 * @return string
	 */
	public static function rest_nonce(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- returns the presented nonce for the caller to pass to wp_verify_nonce(); this is the read half of that verification, not a standalone action. sanitize_text_field() returns '' for a non-string (e.g. an array), so no is_scalar guard is needed.
		$param = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' !== $param ) {
			return $param;
		}
		if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			return self::server_string( 'HTTP_X_WP_NONCE' );
		}
		return '';
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
		// Core matches routes with a case-insensitive regex
		// (WP_REST_Server::dispatch uses '@^' . $route . '$@i'), so an
		// exact comparison here would disagree with the route that
		// actually ran. Route paths are ASCII.
		return 0 === strcasecmp( '/' . $expected, $resolved );
	}

	/**
	 * Resolve a bearer token to a user id, using only token-based
	 * authentication.
	 *
	 * `determine_current_user` is core's whole user-resolution chain, and
	 * cookie validators sit on it. Calling it to validate a token therefore
	 * also re-validates whatever session cookie the request happens to
	 * carry — which resurrects a cookie session that core deliberately
	 * dropped for a REST request with no nonce, so any non-empty token
	 * string would authorize a cookie-bearing request. The cookie and
	 * application-password validators are unhooked for the duration of the
	 * call so only token validators (IndieAuth's) can answer, then restored.
	 *
	 * @return int Resolved user id, or 0 when the token authenticates nobody.
	 */
	public static function resolve_token_user(): int {
		$cookie_validators = array(
			'wp_validate_auth_cookie',
			'wp_validate_logged_in_cookie',
			'wp_validate_application_password',
		);

		$removed = array();
		foreach ( $cookie_validators as $callback ) {
			$priority = has_filter( 'determine_current_user', $callback );
			if ( false !== $priority ) {
				remove_filter( 'determine_current_user', $callback, $priority );
				$removed[ $callback ] = $priority;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP hook.
		$user_id = (int) apply_filters( 'determine_current_user', false );

		foreach ( $removed as $callback => $priority ) {
			add_filter( 'determine_current_user', $callback, $priority );
		}

		return $user_id > 0 ? $user_id : 0;
	}
}
