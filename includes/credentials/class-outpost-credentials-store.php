<?php
/**
 * Outpost_Credentials_Store (G3.5a).
 *
 * Stores per-provider credentials encrypted at rest. Default scope is
 * per-user (usermeta); the
 * `outpost_credentials_storage_scope_{provider}` filter switches
 * specific providers to site-wide (wp_options). Use case: api.bible /
 * sunnah.com where one site-wide key serves all editors; vs. Notion
 * where every user authenticates with their own workspace.
 *
 * Public API surface:
 *
 *   set( $provider, $creds, $user_id = null ): bool
 *   get( $provider, $user_id = null ): ?array
 *   delete( $provider, $user_id = null ): bool
 *   is_configured( $provider, $user_id = null ): bool
 *
 * is_configured() returns boolean WITHOUT decrypting (just key
 * presence). UI checks call is_configured cheaply; consumers needing
 * the actual creds call get().
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Credentials_Store {

	private const META_KEY_PREFIX   = 'outpost_creds_';
	private const OPTION_KEY_PREFIX = 'outpost_creds_';

	/**
	 * Persist credentials for a provider. Encrypts before write.
	 *
	 * @since 0.1.69
	 *
	 * @param string              $provider Provider id (e.g. 'notion').
	 * @param array<string,mixed> $creds    Credentials array. JSON-encoded then encrypted.
	 * @param int|null            $user_id  User id, or null to resolve to current user
	 *                                      (or to site-wide when scope filter says 'site').
	 * @return bool True on success.
	 */
	public static function set( string $provider, array $creds, ?int $user_id = null ): bool {
		$json = wp_json_encode( $creds );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$ciphertext = Outpost_Encryption::encrypt( $json );
		if ( self::is_site_scope( $provider ) ) {
			return (bool) update_option( self::option_key( $provider ), $ciphertext, false );
		}
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		$result = update_user_meta( $user_id, self::meta_key( $provider ), $ciphertext );
		return false !== $result;
	}

	/**
	 * Read credentials for a provider. Returns null when nothing stored
	 * or when decryption fails (e.g., key changed since write).
	 *
	 * @since 0.1.69
	 *
	 * @param string   $provider Provider id.
	 * @param int|null $user_id  User id, or null.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $provider, ?int $user_id = null ): ?array {
		$ciphertext = self::raw_value( $provider, $user_id );
		if ( null === $ciphertext ) {
			return null;
		}
		try {
			$json = Outpost_Encryption::decrypt( $ciphertext );
		} catch ( Outpost_Encryption_Exception $e ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Delete stored credentials for a provider.
	 *
	 * @since 0.1.69
	 *
	 * @param string   $provider Provider id.
	 * @param int|null $user_id  User id, or null.
	 * @return bool True on success.
	 */
	public static function delete( string $provider, ?int $user_id = null ): bool {
		if ( self::is_site_scope( $provider ) ) {
			return (bool) delete_option( self::option_key( $provider ) );
		}
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) delete_user_meta( $user_id, self::meta_key( $provider ) );
	}

	/**
	 * Cheap presence check without decrypting. UI surfaces use this to
	 * decide between "Connect {Platform}" and "Disconnect" buttons.
	 *
	 * @since 0.1.69
	 *
	 * @param string   $provider Provider id.
	 * @param int|null $user_id  User id, or null.
	 * @return bool
	 */
	public static function is_configured( string $provider, ?int $user_id = null ): bool {
		$ciphertext = self::raw_value( $provider, $user_id );
		return is_string( $ciphertext ) && '' !== $ciphertext;
	}

	/**
	 * Resolve the storage scope for a provider via filter. Defaults to
	 * 'user'; providers needing one shared site-wide key (api.bible,
	 * sunnah.com) override via filter.
	 *
	 * @param string $provider Provider id.
	 * @return string 'user' | 'site'
	 */
	private static function resolve_scope( string $provider ): string {
		/**
		 * Filter the credentials storage scope for a provider.
		 *
		 * @param string $scope    Default 'user'.
		 * @param string $provider Provider id.
		 */
		$scope = apply_filters( 'outpost_credentials_storage_scope_' . $provider, 'user', $provider );
		return ( 'site' === $scope ) ? 'site' : 'user';
	}

	private static function is_site_scope( string $provider ): bool {
		return 'site' === self::resolve_scope( $provider );
	}

	/**
	 * Get the raw stored ciphertext (without decrypting).
	 *
	 * @return string|null
	 */
	private static function raw_value( string $provider, ?int $user_id ): ?string {
		if ( self::is_site_scope( $provider ) ) {
			$value = get_option( self::option_key( $provider ) );
			return is_string( $value ) ? $value : null;
		}
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}
		$value = get_user_meta( $user_id, self::meta_key( $provider ), true );
		return is_string( $value ) ? $value : null;
	}

	private static function resolve_user_id( ?int $user_id ): int {
		if ( null !== $user_id ) {
			return $user_id;
		}
		return (int) get_current_user_id();
	}

	private static function meta_key( string $provider ): string {
		return self::META_KEY_PREFIX . sanitize_key( $provider );
	}

	private static function option_key( string $provider ): string {
		return self::OPTION_KEY_PREFIX . sanitize_key( $provider );
	}
}
