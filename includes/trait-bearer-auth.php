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
	 * Whether the token that authenticated this request carries any of the
	 * given IndieAuth scopes; true when no token authenticated it.
	 *
	 * Scope source (H6, read against the deployed IndieAuth 4.7.2 source,
	 * includes/class-authorize.php and includes/functions.php): IndieAuth's
	 * `determine_current_user` callback (`Authorize::determine_current_user()`,
	 * priority 15) stores a verified token's response on its own instance
	 * (`$this->response`) and splits the response's `scope` string onto
	 * `$this->scopes`. The `indieauth_response` and `indieauth_scopes`
	 * filters (both priority 9) expose those two values, and IndieAuth's
	 * accessors `indieauth_get_response()` and `indieauth_get_scopes()` are
	 * exactly `apply_filters( ..., null )` on them. No `$_SERVER` value or
	 * global carries either. This method reads both through the accessors,
	 * behind `function_exists()`: with IndieAuth inactive they are
	 * undefined and both values are null.
	 *
	 * The checks run in this order.
	 *
	 * First, a cookie session with a valid REST nonce skips the scope check,
	 * whatever Authorization header rides along
	 * ({@see self::is_cookie_session_with_rest_nonce()}). The nonce is
	 * derived from the cookie user's id and session token, so it already
	 * binds the request to that browser session, which is all CSRF
	 * protection needs. Scope is a property of a token, and a first-party
	 * browser session has none. A stray token IndieAuth doesn't verify (an
	 * iOS Shortcut token sent off its route, say, or one in a JSON body)
	 * doesn't turn that session into a token request.
	 *
	 * An IndieAuth-verified token always meets the scope gate, whichever
	 * user it belongs to. IndieAuth resolves it on `determine_current_user`
	 * at priority 15; core's `wp_validate_logged_in_cookie()` (priority 20)
	 * returns early once a user is set, so the logged-in cookie is never
	 * validated, and the auth cookie that priority 10 reads is path-scoped
	 * to wp-admin and the plugins directory, so it never reaches a REST URL.
	 * Core therefore never sets `$wp_rest_auth_cookie` for that request and
	 * this step doesn't apply. A bearer-only client sends no cookie, so it
	 * never applies there either.
	 *
	 * Otherwise the request is a bearer request when either of two signals
	 * is present, and whether a scope check applies never depends on which
	 * code resolved the current user. IndieAuth's callback runs during
	 * WordPress's own early user resolution, before any permission callback,
	 * so a header or form-encoded token is usually resolved already and
	 * `authenticate_bearer_token()` returns early without resolving anything
	 * itself.
	 *
	 *   1. {@see self::bearer_token()} finds a token in the Authorization
	 *      header or in the `access_token` body parameter.
	 *   2. `indieauth_response` is non-empty. IndieAuth sets it only after a
	 *      token verified, so it covers every token source IndieAuth accepts,
	 *      including any header form or header source this trait's reader
	 *      misses. IndieAuth's own `Scopes::map_meta_cap()` treats a request
	 *      as token-authenticated on the same signal.
	 *
	 * A bearer request whose scope list is missing or empty (IndieAuth
	 * inactive, or another `determine_current_user` authority resolved the
	 * token) fails closed.
	 *
	 * A request with no valid cookie nonce and neither signal (an anonymous
	 * request, or a logged-in session with no valid nonce and no credential)
	 * has no scope to check, so this returns true and the caller's
	 * `current_user_can( 'edit_posts' )` decides alone, as every caller did
	 * before H6. Core's `rest_cookie_check_errors()` normally demotes a
	 * cookie session that sent no nonce to anonymous before any permission
	 * callback runs.
	 *
	 * Interplay with the `edit_posts` check every caller makes: IndieAuth's
	 * `Scopes::map_meta_cap()` grants `edit_posts` to a token only when one
	 * of its scopes maps to it. Of the built-in scopes, `create` and `draft`
	 * do; `read` and `update` alone never do (`update` maps to
	 * `edit_published_posts` and `edit_others_posts`). So a real `draft`
	 * token passes the capability check and is refused on mutating routes
	 * by this method alone, while a real `read`-only token is refused by
	 * the capability check before this method's answer matters.
	 *
	 * @param WP_REST_Request    $request Current REST request.
	 * @param array<int, string> $any_of  Scopes to accept; any one present authorizes.
	 */
	protected static function bearer_has_scope( WP_REST_Request $request, array $any_of ): bool {
		if ( self::is_cookie_session_with_rest_nonce() ) {
			return true;
		}
		$is_bearer = '' !== self::bearer_token( $request )
			|| ! empty( function_exists( 'indieauth_get_response' ) ? indieauth_get_response() : null );
		if ( ! $is_bearer ) {
			return true;
		}
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

	/**
	 * Whether core authenticated this request with the wp-admin auth cookie
	 * and the request carries a valid REST nonce: the two conditions core's
	 * `rest_cookie_check_errors()` checks for a cookie session.
	 * `$wp_rest_auth_cookie` is core's own flag, which
	 * `rest_cookie_collect_status()` sets to true when the auth cookie
	 * validated, and {@see Outpost_Request_Headers::rest_nonce()} reads the
	 * nonce in core's order (`_wpnonce`, then `X-WP-Nonce`).
	 */
	private static function is_cookie_session_with_rest_nonce(): bool {
		global $wp_rest_auth_cookie;
		return true === $wp_rest_auth_cookie
			&& is_user_logged_in()
			&& false !== wp_verify_nonce( Outpost_Request_Headers::rest_nonce(), 'wp_rest' );
	}

	/**
	 * Extract the bearer token from the Authorization header, or the
	 * Micropub-spec `access_token` request body on hosts that strip the
	 * header. Returns '' when no token is present.
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
