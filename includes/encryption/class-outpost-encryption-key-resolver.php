<?php
/**
 * Outpost_Encryption_Key_Resolver (G3.5a).
 *
 * Resolves the 32-byte encryption key. Order of preference:
 *
 *   1. defined( 'OUTPOST_ENCRYPTION_KEY' ) — preferred. Place in
 *      wp-config.php so the key never lands in the database.
 *   2. get_option( 'outpost_encryption_key' ) — fallback. Auto-
 *      generated and stored on first use. Triggers a persistent
 *      admin notice recommending migration to wp-config.
 *   3. Generate a new key via sodium_crypto_secretbox_keygen(),
 *      store in wp_options, return.
 *
 * The constant value, when set, MUST be 32 raw bytes. The wp_options
 * value is base64-encoded for safe storage; resolver decodes on read.
 *
 * @package Outpost
 * @since   0.1.69
 * @internal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Encryption_Key_Resolver {

	private const OPTION_KEY = 'outpost_encryption_key';

	private const KEY_BYTES = 32;

	/**
	 * Whether the resolved key came from the wp_options fallback path.
	 * Used by the admin notice to decide whether to render.
	 *
	 * Reset on every call to resolve(); reflects only the most recent call.
	 *
	 * @var bool
	 */
	private static bool $last_resolution_used_fallback = false;

	/**
	 * Resolve the encryption key as raw 32 bytes.
	 *
	 * @since 0.1.69
	 * @return string Raw 32-byte key.
	 */
	public static function resolve(): string {
		self::$last_resolution_used_fallback = false;

		if ( defined( 'OUTPOST_ENCRYPTION_KEY' ) ) {
			$constant_value = (string) constant( 'OUTPOST_ENCRYPTION_KEY' );
			if ( self::KEY_BYTES === strlen( $constant_value ) ) {
				return $constant_value;
			}
			// Constant set but wrong length — fall through. Don't trust it.
		}

		$stored = get_option( self::OPTION_KEY );
		if ( is_string( $stored ) && '' !== $stored ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own stored key, not obfuscated payload.
			$decoded = base64_decode( $stored, true );
			if ( false !== $decoded && self::KEY_BYTES === strlen( $decoded ) ) {
				self::$last_resolution_used_fallback = true;
				return $decoded;
			}
		}

		// Generate + persist.
		if ( ! function_exists( 'sodium_crypto_secretbox_keygen' ) ) {
			// Sodium ships with PHP 7.2+ via Sodium Compat in WP core
			// since 5.2. Defensive fallback: random_bytes is always
			// available on PHP 8.2+ which is Outpost's floor.
			$key = random_bytes( self::KEY_BYTES );
		} else {
			$key = sodium_crypto_secretbox_keygen();
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding our own key for safe wp_options storage.
		update_option( self::OPTION_KEY, base64_encode( $key ), false );
		self::$last_resolution_used_fallback = true;
		return $key;
	}

	/**
	 * Whether the most recent resolve() returned the wp_options fallback
	 * value (meaning the admin notice should render).
	 *
	 * @since 0.1.69
	 */
	public static function used_fallback_on_last_resolve(): bool {
		return self::$last_resolution_used_fallback;
	}

	/**
	 * Whether the constant is defined (regardless of validity). Cheap
	 * check used by the admin notice to render even before resolve()
	 * has been called.
	 *
	 * @since 0.1.69
	 */
	public static function constant_is_defined(): bool {
		return defined( 'OUTPOST_ENCRYPTION_KEY' );
	}

	/**
	 * Whether a key value exists in wp_options. Doesn't decode or
	 * validate; just presence-check. Used by the admin notice.
	 *
	 * @since 0.1.69
	 */
	public static function option_value_exists(): bool {
		$stored = get_option( self::OPTION_KEY );
		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Test seam: clear the fallback-flag tracking. Tests reset this
	 * between cases so per-test resolution is independent.
	 *
	 * @since 0.1.69
	 */
	public static function reset_for_tests(): void {
		self::$last_resolution_used_fallback = false;
	}
}
