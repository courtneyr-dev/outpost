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
	 * Whether this request's {@see self::authenticate_bearer_token()} call
	 * actually resolved a WP user from a bearer token (header or the
	 * Micropub-spec body fallback). False for a cookie-authenticated (nonce)
	 * session — that path returns before any token is even looked at — for
	 * a fully unauthenticated request, and for a token that failed to
	 * resolve a user. Reset at the top of every
	 * `authenticate_bearer_token()` call so a stale `true` from an earlier
	 * request on the same worker (or, in a long-running PHPUnit process,
	 * an earlier test) can never leak into the next permission check.
	 *
	 * {@see self::bearer_has_scope()} reads this to decide whether a scope
	 * check applies at all: a cookie/nonce session was never issued an
	 * IndieAuth scope, so it has nothing to check and keeps its existing
	 * `edit_posts` (+ nonce) gate unchanged.
	 */
	private static bool $bearer_token_resolved_user = false;

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
		self::$bearer_token_resolved_user = false;
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
			self::$bearer_token_resolved_user = true;
		}
	}

	/**
	 * Whether the token that authenticated this request carries any of the
	 * given IndieAuth scopes.
	 *
	 * Discovery (H6, read-only against the deployed IndieAuth 4.7.2 source
	 * at includes/class-authorize.php + includes/functions.php): IndieAuth's
	 * `determine_current_user` callback (`Authorize::determine_current_user()`,
	 * hooked at priority 15) explodes the token response's `scope` string
	 * onto `$this->scopes` on its own singleton, and separately hooks the
	 * `indieauth_scopes` filter (`Authorize::get_indieauth_scopes()`, priority
	 * 9) to expose it: `$scopes ? $scopes : $this->scopes`. The plugin's own
	 * public accessor, `indieauth_get_scopes()`, is exactly
	 * `apply_filters( 'indieauth_scopes', null )` — there is no per-request
	 * $_SERVER value or global; the filter is the only exposed source, and
	 * it is only populated once `determine_current_user` has actually run
	 * for a bearer credential (which `authenticate_bearer_token()` triggers
	 * via `Outpost_Request_Headers::resolve_token_user()` before this can be
	 * called).
	 *
	 * A cookie-authenticated (nonce) session never attempted bearer
	 * resolution — `authenticate_bearer_token()` returns before touching a
	 * token — so it carries no scope to check: `edit_posts` + the REST
	 * nonce remain its whole gate, unchanged by this method. A bearer token
	 * that DID resolve a user but exposes no scope (any
	 * `determine_current_user` authority other than IndieAuth, or
	 * IndieAuth's filter returning empty) is treated as scope-less and
	 * rejected: an unscoped token must never fall through to full access.
	 *
	 * @param array<int, string> $any_of Scopes to accept; any one present authorizes.
	 */
	protected static function bearer_has_scope( array $any_of ): bool {
		if ( ! self::$bearer_token_resolved_user ) {
			return true;
		}
		$scopes = apply_filters( 'indieauth_scopes', null );
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
