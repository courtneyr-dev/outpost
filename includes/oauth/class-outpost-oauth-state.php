<?php
/**
 * Outpost_OAuth_State (G3.5a).
 *
 * Single-use OAuth state parameter generation + validation. Stored as a
 * WordPress transient keyed on the state value itself, with `user_id`
 * and `provider` carried in the value. State value is 32 bytes of
 * random, base64url-encoded.
 *
 * Why state-keyed (not user-keyed): OAuth callbacks are external
 * redirects from the provider, so they can't carry a WP REST nonce.
 * Looking up state by user_id requires authenticating the user first,
 * which is the chicken-and-egg the nonce was for. Keying on the state
 * value lets the callback handler look up the state with no auth, then
 * derive the user_id from the stored value, then `wp_set_current_user`.
 * The state value is high-entropy (32 random bytes) so guessing is
 * infeasible.
 *
 * On successful validation: state is cleared (single-use). Replay of
 * the same state on a second call returns null. Expired state
 * (TTL > 10 minutes since issuance) is rejected.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_State {

	private const TRANSIENT_PREFIX = 'outpost_oauth_state_';

	private const TTL_SECONDS = 600;

	/**
	 * Generate, store, and return a fresh state value.
	 *
	 * @since 0.1.69
	 *
	 * @param string $provider Provider id.
	 * @param int    $user_id  User the state belongs to.
	 * @return string Base64url-encoded random state.
	 */
	public static function generate( string $provider, int $user_id ): string {
		$random = random_bytes( 32 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url-encoding our own random bytes for OAuth state, not obfuscation.
		$state = rtrim( strtr( base64_encode( $random ), '+/', '-_' ), '=' );
		set_transient(
			self::transient_key( $state ),
			array(
				'user_id'  => $user_id,
				'provider' => sanitize_key( $provider ),
			),
			self::TTL_SECONDS
		);
		return $state;
	}

	/**
	 * Validate a state value. Returns the stored `user_id` on match (and
	 * clears the transient — single-use), or null when the state is
	 * unknown, expired, or for a different provider.
	 *
	 * @since 0.1.69
	 *
	 * @param string $provider  Provider id (must match the value stored at
	 *                          generate-time).
	 * @param string $candidate State value from the OAuth callback.
	 * @return int|null         User id when valid; null otherwise.
	 */
	public static function validate( string $provider, string $candidate ): ?int {
		if ( '' === $candidate ) {
			return null;
		}
		$key   = self::transient_key( $candidate );
		$value = get_transient( $key );
		// Always clear after a validation attempt — single-use semantics.
		delete_transient( $key );

		if ( ! is_array( $value ) ) {
			return null;
		}
		$stored_provider = isset( $value['provider'] ) ? (string) $value['provider'] : '';
		$stored_user_id  = isset( $value['user_id'] ) ? (int) $value['user_id'] : 0;
		if ( '' === $stored_provider || ! hash_equals( $stored_provider, sanitize_key( $provider ) ) ) {
			return null;
		}
		if ( $stored_user_id <= 0 ) {
			return null;
		}
		return $stored_user_id;
	}

	/**
	 * Clear a state value. Used on disconnect or to invalidate a flow
	 * mid-stream.
	 *
	 * @since 0.1.69
	 */
	public static function clear( string $candidate ): void {
		if ( '' === $candidate ) {
			return;
		}
		delete_transient( self::transient_key( $candidate ) );
	}

	private static function transient_key( string $state ): string {
		// Hash the state for the transient key so case-sensitive base64url
		// values don't collide under sanitize_key's lowercase coercion.
		return self::TRANSIENT_PREFIX . hash( 'sha256', $state );
	}
}
