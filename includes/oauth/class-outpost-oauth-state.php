<?php
/**
 * Outpost_OAuth_State (G3.5a).
 *
 * Single-use OAuth state parameter generation + validation. Stored
 * in user-meta keyed `outpost_oauth_state_{provider}` with a separate
 * `_expires` companion meta carrying the unix-time deadline. State
 * value is 32 bytes of random, base64url-encoded.
 *
 * On successful validation: state is cleared (single-use). Replay of
 * the same state on a second call returns false. Expired state
 * (TTL > 10 minutes since issuance) is rejected and cleared.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_State {

	private const META_PREFIX         = 'outpost_oauth_state_';
	private const META_EXPIRES_SUFFIX = '_expires';

	private const TTL_SECONDS = 600;

	/**
	 * Generate, store, and return a fresh state value for a provider.
	 * Overwrites any pending state for the same (user, provider) tuple.
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
		$state   = rtrim( strtr( base64_encode( $random ), '+/', '-_' ), '=' );
		$expires = time() + self::TTL_SECONDS;
		update_user_meta( $user_id, self::meta_key( $provider ), $state );
		update_user_meta( $user_id, self::expires_meta_key( $provider ), $expires );
		return $state;
	}

	/**
	 * Validate a state value against the stored state for (user, provider).
	 * Single-use: clears the stored state on success or expired-rejection.
	 *
	 * @since 0.1.69
	 *
	 * @param string $provider Provider id.
	 * @param int    $user_id  User id.
	 * @param string $candidate State value from the OAuth callback.
	 * @return bool True when the candidate matches and is unexpired.
	 */
	public static function validate( string $provider, int $user_id, string $candidate ): bool {
		$stored  = (string) get_user_meta( $user_id, self::meta_key( $provider ), true );
		$expires = (int) get_user_meta( $user_id, self::expires_meta_key( $provider ), true );

		// Always clear after a validation attempt — single-use semantics.
		self::clear( $provider, $user_id );

		if ( '' === $stored || '' === $candidate ) {
			return false;
		}
		if ( $expires <= time() ) {
			return false;
		}
		return hash_equals( $stored, $candidate );
	}

	/**
	 * Clear stored state for (user, provider). Called after validation
	 * (successful or otherwise) and on disconnect.
	 *
	 * @since 0.1.69
	 */
	public static function clear( string $provider, int $user_id ): void {
		delete_user_meta( $user_id, self::meta_key( $provider ) );
		delete_user_meta( $user_id, self::expires_meta_key( $provider ) );
	}

	private static function meta_key( string $provider ): string {
		return self::META_PREFIX . sanitize_key( $provider );
	}

	private static function expires_meta_key( string $provider ): string {
		return self::META_PREFIX . sanitize_key( $provider ) . self::META_EXPIRES_SUFFIX;
	}
}
