<?php
/**
 * Shared bearer-token authentication for Outpost REST endpoints.
 *
 * @package Outpost
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Outpost_Bearer_Auth {

	/*
	 * This trait never resolves or switches users. WordPress resolves the
	 * current user once, through the `determine_current_user` filter chain,
	 * before it dispatches a REST route: IndieAuth's callback (priority 15)
	 * reads a bearer token from the Authorization header or from a
	 * form-encoded `access_token` body field, the two places RFC 6750 and the
	 * Micropub spec put it. Every Outpost permission callback checks the user
	 * WordPress resolved and the scope of the token that authenticated it.
	 * A token sent anywhere else (a JSON body, the query string) does not
	 * authenticate the request.
	 */

	/**
	 * Whether this request may use a route that needs one of the given
	 * IndieAuth scopes. Every Outpost REST permission callback calls this
	 * after its own capability check. The checks run top to bottom and each
	 * one returns:
	 *
	 * 1. Cookie session: WordPress authenticated the request with the
	 *    wp-admin cookie (core sets `$wp_rest_auth_cookie` to true). A cookie
	 *    request needs a valid REST nonce, its CSRF protection, so this fails
	 *    closed when the nonce is missing or `wp_verify_nonce()` returns false.
	 *    Outpost checks this itself rather than relying on core's
	 *    `rest_cookie_check_errors()`, which any `rest_authentication_errors`
	 *    callback that returns a non-empty value first can skip. A valid
	 *    nonce (`wp_verify_nonce()` returns 1 or 2) passes: a first-party
	 *    browser session has no token scopes.
	 * 2. Bearer token: a token in the Authorization header or the
	 *    form-encoded `access_token` field, or an IndieAuth-verified response.
	 *    The token must carry one of `$any_of`. A token whose scopes can't be
	 *    read fails closed. A token-authenticated request never sets
	 *    `$wp_rest_auth_cookie` (IndieAuth resolves the user before core
	 *    validates the cookie), so it never needs a cookie nonce.
	 * 3. Anything else (an Application Password over Basic auth, or no
	 *    credential at all) has no scope to check, so the caller's capability
	 *    check decides.
	 *
	 * This method never resolves or switches users; it reads the user
	 * WordPress already resolved and IndieAuth's `indieauth_response` and
	 * `indieauth_scopes` accessors (null when IndieAuth is inactive).
	 *
	 * @param WP_REST_Request    $request Current REST request.
	 * @param array<int, string> $any_of  Scopes to accept; any one present authorizes.
	 */
	protected static function bearer_has_scope( WP_REST_Request $request, array $any_of ): bool {
		global $wp_rest_auth_cookie;

		// 1. Cookie session: a missing or invalid REST nonce fails here.
		if ( true === $wp_rest_auth_cookie ) {
			$nonce = Outpost_Request_Headers::rest_nonce();
			if ( '' === $nonce ) {
				return false;
			}
			if ( false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return false;
			}
			return true;
		}

		// 2. Bearer token: the token must carry an accepted scope.
		$is_bearer = '' !== self::bearer_token( $request )
			|| ! empty( function_exists( 'indieauth_get_response' ) ? indieauth_get_response() : null );
		if ( $is_bearer ) {
			$scopes = function_exists( 'indieauth_get_scopes' ) ? indieauth_get_scopes() : null;
			if ( ! is_array( $scopes ) || array() === $scopes ) {
				return false;
			}
			foreach ( $any_of as $scope ) {
				if ( in_array( $scope, $scopes, true ) ) {
					return true;
				}
			}
			return false;
		}

		// 3. No cookie session and no token: the capability check decides.
		return true;
	}

	/**
	 * Find a bearer token in the Authorization header or an `access_token`
	 * body field. Returns '' when no token is present.
	 *
	 * This only decides whether a scope check applies; it never
	 * authenticates anything. A JSON-body token counts here so that a
	 * request presenting one is held to the scope gate (fail closed), even
	 * though IndieAuth only authenticates header and form-encoded tokens.
	 *
	 * The header pattern matches every header text IndieAuth's
	 * `Authorize::get_token_from_bearer_header()` matches. IndieAuth's
	 * pattern, `/Bearer ([\x20-\x7E]+)/`, is unanchored, so it reads a token
	 * after any prefix (`X Bearer <token>`); this one is unanchored too, and
	 * case-insensitive. A `Basic` credential never matches: its base64
	 * payload contains no whitespace, so `Bearer` followed by whitespace
	 * cannot appear in it. IndieAuth reads the header raw while
	 * `Outpost_Request_Headers::authorization()` sanitizes it, which can
	 * drop tag-like text or invalid UTF-8; for those, the
	 * `indieauth_response` signal in bearer_has_scope() marks the request.
	 *
	 * The body is read through WP_REST_Request, which WordPress has already
	 * parsed (form-encoded or JSON). Query-string parameters are never
	 * consulted: bodies don't appear in access logs, browser history, or CDN
	 * cache keys, unlike query strings.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private static function bearer_token( WP_REST_Request $request ): string {
		$header = Outpost_Request_Headers::authorization();
		if ( '' !== $header && preg_match( '/Bearer\s+(\S+)/i', $header, $matches ) ) {
			return $matches[1];
		}
		foreach ( array( $request->get_body_params(), $request->get_json_params() ) as $body ) {
			if ( is_array( $body ) && isset( $body['access_token'] ) && is_string( $body['access_token'] ) ) {
				$body_token = sanitize_text_field( $body['access_token'] );
				if ( '' !== $body_token ) {
					return $body_token;
				}
			}
		}
		return '';
	}
}
