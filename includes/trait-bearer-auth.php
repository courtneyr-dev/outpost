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

	/**
	 * Resolve a bearer token to a real WP user before the capability check.
	 *
	 * Delegates validation to the `determine_current_user` filter (the same
	 * hook IndieAuth's plugin uses to authenticate bearer tokens), so an
	 * unvalidated token can never authorize the request. On managed-WP hosts
	 * that strip the Authorization header (GoDaddy), the Micropub-spec body
	 * `access_token` is restored to the header first so IndieAuth can read it.
	 *
	 * This is the plugin's one `wp_set_current_user()` call. WordPress
	 * resolves the user once, before it dispatches the route, and two cases
	 * leave a valid token unrecognized by then:
	 *
	 *   1. The host stripped the Authorization header and the PWA's token
	 *      rides in a JSON body. IndieAuth's `determine_current_user`
	 *      callback reads the header and form-encoded `$_POST` only.
	 *   2. The request also carries a wp-admin cookie with no REST nonce, so
	 *      core's `rest_cookie_check_errors()` already set the user to 0.
	 *
	 * Core has no hook that resolves the user again after that point, so
	 * this re-runs the `determine_current_user` chain and applies its
	 * answer. The user id only ever comes from that chain: this trait never
	 * maps a token to a user. It lasts for the request; no cookie or session
	 * is written.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private static function authenticate_bearer_token( WP_REST_Request $request ): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$token = self::bearer_token( $request );
		if ( '' === $token ) {
			return;
		}
		// Restore a stripped Authorization header so IndieAuth's
		// determine_current_user callback can read and validate the token.
		if ( '' === Outpost_Request_Headers::authorization() ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		$user_id = Outpost_Request_Headers::resolve_token_user();
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Extract the bearer token from the Authorization header, or the
	 * Micropub-spec `access_token` request body on hosts that strip the
	 * header. Returns '' when no token is present.
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
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+(\S+)/i', $header, $matches ) ) {
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
