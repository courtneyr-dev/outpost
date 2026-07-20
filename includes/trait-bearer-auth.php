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
	 */
	private static function authenticate_bearer_token(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$token = self::bearer_token();
		if ( '' === $token ) {
			return;
		}
		// Restore a stripped Authorization header so IndieAuth's
		// determine_current_user callback can read and validate the token.
		if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) && empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		$user_id = (int) apply_filters( 'determine_current_user', false );
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Extract the bearer token from the Authorization header, or the
	 * Micropub-spec `access_token` request body on hosts that strip the
	 * header. Returns '' when no token is present.
	 *
	 * Bodies don't appear in access logs, browser history, or CDN cache
	 * keys, unlike query strings, so the body fallback is spec-compliant
	 * and leak-safe.
	 */
	private static function bearer_token(): string {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+(\S+)/i', $header, $matches ) ) {
			return $matches[1];
		}
		// Bearer-token auth path; nonces don't apply to token-authenticated
		// requests, and managed-WP hosts strip the header so the token rides
		// in the body.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$body_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : null;
		if ( null === $body_token ) {
			$raw = file_get_contents( 'php://input' );
			if ( false !== $raw && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['access_token'] ) && is_string( $decoded['access_token'] ) ) {
					$body_token = sanitize_text_field( $decoded['access_token'] );
				}
			}
		}
		if ( is_string( $body_token ) && '' !== $body_token ) {
			return $body_token;
		}
		return '';
	}
}
