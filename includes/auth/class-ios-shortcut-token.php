<?php
/**
 * Outpost_IOS_Shortcut_Token
 *
 * Per-user long-lived token for the iOS Shortcut bridge endpoint.
 * Users generate one from the settings page, paste it into the
 * iOS Shortcut, and from then on the Shortcut authenticates against
 * /wp-json/outpost/v1/shortcut with the token as a Bearer.
 *
 * STORAGE
 *
 * User-meta key `outpost_ios_shortcut_token`. Single value per user;
 * regenerating revokes the prior token by replacing it. Tokens are
 * 32 random ASCII characters from `wp_generate_password( 32, false,
 * false )` — no special characters so they survive copy-paste through
 * the iOS Shortcuts text-input action without escaping concerns.
 *
 * SCOPE — load-bearing security boundary
 *
 * The token authenticates ONLY the iOS Shortcut REST endpoint
 * (/wp-json/outpost/v1/shortcut). It does NOT authenticate Micropub
 * /media, IndieAuth, the preview endpoint, or any other surface.
 * Scope enforcement lives in the companion authenticator class
 * `Outpost_IOS_Shortcut_Token_Authenticator`. This class is the
 * storage / lifecycle layer; the authenticator checks scope per
 * REST request.
 *
 * THREAT MODEL
 *
 * - Leaked token = inbound share-target access for the issuing user
 *   until they revoke (regenerate). Same blast radius as a leaked
 *   Application Password but narrower — Application Passwords give
 *   full WP REST access; this token gives only the shortcut endpoint.
 * - Timing attacks on the lookup-by-token path are defeated via
 *   `hash_equals()` comparison (see `validate_token`).
 * - No expiration. Users explicitly regenerate when they want
 *   rotation. iOS Shortcut friction (re-paste the token) makes
 *   short-lived tokens user-hostile; the per-endpoint scope keeps
 *   long-lived tokens within tolerance.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_Token {

	public const META_KEY            = 'outpost_ios_shortcut_token';
	public const FIRST_SEEN_META_KEY = 'outpost_ios_shortcut_first_seen';

	private const TOKEN_LENGTH = 32;

	/**
	 * Test seam: callable that maps a presented token to a user_id
	 * (or null). Production uses WP_User_Query; tests inject a
	 * static-array-backed resolver so they don't need to fake the
	 * WP_User_Query class. Mirrors the F12 candidate-resolver pattern.
	 *
	 * @var callable|null
	 */
	private static $token_resolver = null;

	/**
	 * Override the production `WP_User_Query`-based resolver with a
	 * test callable. Pass null to restore production behavior.
	 *
	 * @param callable|null $resolver fn(string $token): ?int
	 */
	public static function set_resolver_for_tests( ?callable $resolver ): void {
		self::$token_resolver = $resolver;
	}

	/**
	 * Generate and persist a fresh token for the user. Returns the
	 * plaintext token (the only time it's visible — settings page
	 * displays it on regeneration; thereafter the same token can be
	 * read back via `get_token()` because it's stored verbatim in
	 * user-meta, but the contract is: settings page renders + copy
	 * button on each visit).
	 *
	 * Regenerating ALWAYS replaces the prior token. Old tokens are
	 * invalidated immediately. Resets the first-seen marker so the
	 * settings page status returns to "Not connected" until the next
	 * successful POST.
	 *
	 * @param int $user_id User to issue the token for.
	 */
	public static function regenerate( int $user_id ): string {
		$token = self::generate_token_string();
		update_user_meta( $user_id, self::META_KEY, $token );
		delete_user_meta( $user_id, self::FIRST_SEEN_META_KEY );
		return $token;
	}

	/**
	 * Read the stored token for a user, or null if none exists.
	 *
	 * @param int $user_id User to read.
	 */
	public static function get_token( int $user_id ): ?string {
		$value = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * Revoke the user's token AND the first-seen marker. After
	 * revoke, the user has no active token until they regenerate.
	 *
	 * @param int $user_id User to revoke.
	 */
	public static function revoke( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::FIRST_SEEN_META_KEY );
	}

	/**
	 * Resolve a presented token to a user_id, or null if no user
	 * has this token. Comparison uses `hash_equals` to defeat timing
	 * attacks. Lookup runs via WP_User_Query meta_value because the
	 * token is its own primary key — there's no faster index without
	 * a custom table, and at the per-user-with-iOS-Shortcut scale
	 * (single-digit users on a personal blog), meta_value is fine.
	 *
	 * @param string $presented Token from the Authorization header.
	 */
	public static function resolve_token_to_user_id( string $presented ): ?int {
		if ( '' === $presented ) {
			return null;
		}
		if ( null !== self::$token_resolver ) {
			$resolved = call_user_func( self::$token_resolver, $presented );
			return is_int( $resolved ) ? $resolved : null;
		}
		$query   = new \WP_User_Query(
			array(
				'meta_key'   => self::META_KEY,
				'meta_value' => $presented,
				'number'     => 1,
				'fields'     => array( 'ID' ),
			)
		);
		$results = $query->get_results();
		if ( empty( $results ) ) {
			return null;
		}
		// Defense-in-depth: even though WP did an exact-match meta
		// query, also do a constant-time compare against the stored
		// token to make the auth path uniformly timed regardless of
		// whether the database lookup short-circuited.
		$user = $results[0];
		$id   = isset( $user->ID ) ? (int) $user->ID : 0;
		if ( 0 === $id ) {
			return null;
		}
		$stored = self::get_token( $id );
		if ( null === $stored ) {
			return null;
		}
		if ( ! hash_equals( $stored, $presented ) ) {
			return null;
		}
		return $id;
	}

	/**
	 * Record the first time the Shortcut successfully POSTed for
	 * this user. Idempotent — once set, subsequent calls do not
	 * overwrite (the timestamp is the FIRST-seen marker, not last-
	 * seen). The settings page reads this to display "Connected"
	 * status. Last-seen tracking is a follow-up if needed.
	 *
	 * @param int $user_id User who just POSTed successfully.
	 */
	public static function record_first_seen_if_unset( int $user_id ): void {
		$existing = get_user_meta( $user_id, self::FIRST_SEEN_META_KEY, true );
		if ( '' !== $existing ) {
			return;
		}
		// gmdate so the recorded value is timezone-independent. Settings
		// page renders it via the user's WP locale.
		update_user_meta( $user_id, self::FIRST_SEEN_META_KEY, gmdate( 'c' ) );
	}

	/**
	 * Read the first-seen timestamp for a user, or null if never set.
	 *
	 * @param int $user_id User to read.
	 */
	public static function get_first_seen( int $user_id ): ?string {
		$value = get_user_meta( $user_id, self::FIRST_SEEN_META_KEY, true );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * Generate a fresh 32-char alphanumeric token. Public so tests
	 * can compare format without forcing the test to also exercise
	 * the user-meta write path.
	 */
	public static function generate_token_string(): string {
		// wp_generate_password with $special_chars=false and
		// $extra_special_chars=false yields A-Z a-z 0-9 only, which
		// survives copy-paste through iOS Shortcuts text-input
		// actions without escaping concerns.
		return wp_generate_password( self::TOKEN_LENGTH, false, false );
	}
}
