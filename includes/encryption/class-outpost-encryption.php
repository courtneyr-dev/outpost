<?php
/**
 * Outpost_Encryption (G3.5a).
 *
 * Authenticated symmetric encryption via XChaCha20-Poly1305 (libsodium
 * secretbox). 24-byte random nonce per encryption; nonce prepended to
 * ciphertext; whole envelope base64-encoded for safe storage in
 * wp_options or usermeta.
 *
 * @internal
 *
 * Not part of Outpost's public API. Third-party plugins MUST NOT call
 * these methods directly; the credentials store is the supported
 * surface for third-party consumers needing encrypted storage.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Encryption {

	private const NONCE_BYTES = 24;

	/**
	 * Encrypt a plaintext string. Returns base64-encoded
	 * `nonce || ciphertext` envelope. Random nonce per call; identical
	 * plaintext encrypted twice produces different ciphertext.
	 *
	 * @since 0.1.69
	 * @internal
	 *
	 * @param string $plaintext Plaintext to encrypt. May contain any bytes.
	 * @return string Base64-encoded envelope.
	 */
	public static function encrypt( string $plaintext ): string {
		$key   = Outpost_Encryption_Key_Resolver::resolve();
		$nonce = random_bytes( self::NONCE_BYTES );
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} else {
			// PHP 8.2+ ships sodium core; this path is defensive only.
			throw new Outpost_Encryption_Exception( 'Sodium extension unavailable.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Authenticated ciphertext envelope, not obfuscation.
		return base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a ciphertext envelope. Throws on tampered or corrupted
	 * input — auth tag validation is part of secretbox.
	 *
	 * @since 0.1.69
	 * @internal
	 *
	 * @param string $ciphertext_b64 Base64 envelope produced by encrypt().
	 * @return string Plaintext.
	 * @throws Outpost_Encryption_Exception When base64 decode fails, when
	 *                                       the envelope is too short, or
	 *                                       when the auth tag check fails.
	 */
	public static function decrypt( string $ciphertext_b64 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own ciphertext envelope, not obfuscated payload.
		$envelope = base64_decode( $ciphertext_b64, true );
		if ( false === $envelope || strlen( $envelope ) <= self::NONCE_BYTES ) {
			throw new Outpost_Encryption_Exception( 'Ciphertext envelope is too short or not base64.' );
		}
		$nonce  = substr( $envelope, 0, self::NONCE_BYTES );
		$cipher = substr( $envelope, self::NONCE_BYTES );
		$key    = Outpost_Encryption_Key_Resolver::resolve();

		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		} else {
			throw new Outpost_Encryption_Exception( 'Sodium extension unavailable.' );
		}

		if ( false === $plaintext ) {
			throw new Outpost_Encryption_Exception( 'Decryption failed (auth tag mismatch or corrupted ciphertext).' );
		}
		return $plaintext;
	}
}
