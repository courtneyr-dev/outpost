<?php
/**
 * Outpost_IOS_Shortcut_Token_Authenticator
 *
 * REST authentication filter that recognizes the iOS Shortcut
 * Bearer token on /wp-json/outpost/v1/shortcut requests ONLY.
 *
 * SCOPE — load-bearing security boundary
 *
 * The token authenticates one route. If a Bearer token presented as
 * an iOS Shortcut token reaches any other REST endpoint, this
 * authenticator returns a WP_Error to mark the request as unauth-
 * enticated, regardless of how the rest of WP would resolve it.
 * The rationale: a leaked Shortcut token must NOT escalate to full
 * REST API access (Micropub, IndieAuth callback, preview, etc.).
 *
 * AUTH FLOW
 *
 * 1. Hooks `rest_authentication_errors` at default priority.
 * 2. If the request has no Authorization Bearer header → returns
 *    null (passthrough; other auth filters handle cookie / IndieAuth).
 * 3. If the request IS for the shortcut endpoint AND the token
 *    resolves to a user → sets the current user, returns null
 *    (auth succeeded; downstream permission_callback runs).
 * 4. If the request IS for the shortcut endpoint AND the token
 *    does NOT resolve → returns WP_Error 401.
 * 5. If the request is for ANY OTHER endpoint AND the Bearer token
 *    resolves to an iOS-Shortcut-token user → returns WP_Error 401.
 *    The token is scoped to the shortcut endpoint; presenting it
 *    elsewhere is rejected.
 * 6. If the request is for any other endpoint AND the Bearer token
 *    does NOT match the iOS Shortcut token format/storage →
 *    returns null (passthrough; the IndieAuth plugin's bearer-token
 *    middleware handles its own tokens).
 *
 * REQUEST IDENTIFICATION
 *
 * The shortcut REST route is `/outpost/v1/shortcut`. Scope is decided by
 * the route WordPress resolved for the request
 * ({@see Outpost_Request_Headers::is_rest_route()}), never by
 * REQUEST_URI: `rest_authentication_errors` fires after
 * `WP::parse_request()` has populated the `rest_route` query var, so
 * that value is authoritative, and it fails closed when absent.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_Token_Authenticator {

	public const REST_ROUTE_PATH = '/outpost/v1/shortcut';

	/**
	 * Hook registration. Called once during plugin bootstrap.
	 */
	public static function register(): void {
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate' ) );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed $result Existing auth result from upstream filters.
	 *                      WP_Error means an earlier filter rejected
	 *                      the request; true means an earlier filter
	 *                      succeeded; null means no decision yet.
	 * @return mixed Same shape: WP_Error, true, or null.
	 */
	public static function authenticate( $result ) {
		// If an upstream filter already produced a definitive result
		// (WP_Error to reject, true to accept), respect it. Only act
		// when the auth chain is still undecided.
		if ( null !== $result ) {
			return $result;
		}

		$presented = self::extract_bearer_token();
		if ( null === $presented ) {
			return null;
		}

		$user_id = Outpost_IOS_Shortcut_Token::resolve_token_to_user_id( $presented );

		if ( null === $user_id ) {
			// The Bearer token isn't an iOS Shortcut token — let the
			// next auth filter (IndieAuth, app-passwords, etc.)
			// decide. Returning null is the "I don't know this token"
			// signal in the rest_authentication_errors contract.
			return null;
		}

		// Token IS an iOS Shortcut token. Now check scope.
		if ( ! self::request_targets_shortcut_endpoint() ) {
			return new \WP_Error(
				'outpost_ios_shortcut_token_out_of_scope',
				__( 'This token is scoped to the iOS Shortcut endpoint and cannot authenticate other requests.', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}

		// Set the current user for the duration of this request so
		// downstream permission_callback / capability checks see it.
		wp_set_current_user( $user_id );
		Outpost_IOS_Shortcut_Token::record_first_seen_if_unset( $user_id );
		return true;
	}

	/**
	 * Read the Bearer token from the Authorization header. Returns
	 * null if no Bearer header is present. Tolerates the
	 * REDIRECT_HTTP_AUTHORIZATION variant some Apache configurations
	 * emit instead of HTTP_AUTHORIZATION.
	 */
	private static function extract_bearer_token(): ?string {
		$header = Outpost_Request_Headers::authorization();
		if ( '' === $header ) {
			return null;
		}
		if ( 1 !== preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return null;
		}
		return $matches[1];
	}

	/**
	 * Whether the current request targets the shortcut REST route.
	 *
	 * Keys on the route WordPress actually resolved for this request, never on a
	 * substring of REQUEST_URI. WordPress routes on the `rest_route` query var,
	 * which `$_GET`/`$_POST` override ahead of the `/wp-json/` permalink rewrite,
	 * so the raw URI and the dispatched route can diverge. An earlier substring
	 * check let a leaked (admin-issued) token authenticate arbitrary REST routes
	 * by smuggling `rest_route=/outpost/v1/shortcut` into a decoy query key while
	 * WordPress dispatched, e.g., `/wp/v2/users` — a parser-differential scope
	 * bypass. The comparison now lives in one place for every route-scoped
	 * decision in the plugin; absent a resolved route it fails closed.
	 *
	 * @return bool
	 */
	private static function request_targets_shortcut_endpoint(): bool {
		return Outpost_Request_Headers::is_rest_route( self::REST_ROUTE_PATH );
	}
}
