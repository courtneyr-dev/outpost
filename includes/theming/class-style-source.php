<?php
/**
 * Outpost_Style_Source
 *
 * Per-user choice of where the composer's paint comes from:
 *
 *   - 'outpost' — Outpost Default, the plugin's own design tokens.
 *   - 'site'    — Use Site Theme, the active theme's palette and fonts
 *                 resolved through {@see Outpost_Token_Resolver}.
 *
 * Independent of the Light / Dark / System choice that
 * {@see Outpost_Mode_Controller} stores.
 *
 * NEW USERS VS. UPGRADES
 *
 * Before this setting existed, every composer followed the site theme.
 * The `outpost_appearance_schema` option records when this site first
 * ran a version that has the setting:
 *
 *   - On a fresh activation, `legacy_cutoff` is 0: nobody predates the
 *     setting, so everyone without a stored choice gets Outpost Default.
 *   - On an upgrade (Outpost ran here before: `outpost_rewrite_version`
 *     is set), `legacy_cutoff` is the upgrade time. A user registered at
 *     or before it, with no stored choice, keeps Site Theme, which is
 *     what they saw before. A user registered later gets Outpost Default.
 *
 * Whether the user stored a day/night/system mode plays no part.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Style_Source {

	public const META_KEY = 'outpost_appearance_source';

	public const SCHEMA_OPTION = 'outpost_appearance_schema';

	public const SCHEMA_VERSION = 1;

	public const SOURCE_OUTPOST = 'outpost';
	public const SOURCE_SITE    = 'site';

	/** @var string[] */
	public const VALID_SOURCES = array( self::SOURCE_OUTPOST, self::SOURCE_SITE );

	/**
	 * Test seam for "now".
	 *
	 * @var callable|null
	 */
	private static $clock_for_tests = null;

	public static function set_clock_for_tests( ?callable $clock ): void {
		self::$clock_for_tests = $clock;
	}

	/**
	 * Record the schema marker once. Runs on `init` before the rewrite
	 * flush (which writes `outpost_rewrite_version`) and on activation.
	 */
	public static function ensure_schema(): void {
		$existing = get_option( self::SCHEMA_OPTION, false );
		if ( is_array( $existing ) ) {
			return;
		}
		$ran_before = false !== get_option( 'outpost_rewrite_version', false );
		add_option(
			self::SCHEMA_OPTION,
			array(
				'version'       => self::SCHEMA_VERSION,
				'legacy_cutoff' => $ran_before ? self::now() : 0,
			)
		);
	}

	/**
	 * The user's style source. Stored choice first, then the
	 * new-user / upgrade default described in the class comment.
	 *
	 * @param int $user_id User; 0 means current user.
	 */
	public static function get_source( int $user_id ): string {
		if ( 0 === $user_id ) {
			$user_id = (int) get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return self::SOURCE_OUTPOST;
		}
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( is_string( $stored ) && in_array( $stored, self::VALID_SOURCES, true ) ) {
			return $stored;
		}
		return self::default_for_user( $user_id );
	}

	/**
	 * Whether the user has stored an explicit choice.
	 */
	public static function has_stored_source( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		return is_string( $stored ) && in_array( $stored, self::VALID_SOURCES, true );
	}

	/**
	 * Persist the user's choice. Rejects unknown values.
	 */
	public static function set_source( int $user_id, string $source ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$normalized = strtolower( trim( $source ) );
		if ( ! in_array( $normalized, self::VALID_SOURCES, true ) ) {
			return false;
		}
		if ( get_user_meta( $user_id, self::META_KEY, true ) === $normalized ) {
			return true;
		}
		return false !== update_user_meta( $user_id, self::META_KEY, $normalized );
	}

	/**
	 * Default for a user without a stored choice.
	 */
	public static function default_for_user( int $user_id ): string {
		$cutoff = self::legacy_cutoff();
		if ( $cutoff <= 0 ) {
			return self::SOURCE_OUTPOST;
		}
		$registered = self::registered_timestamp( $user_id );
		if ( null !== $registered && $registered <= $cutoff ) {
			return self::SOURCE_SITE;
		}
		return self::SOURCE_OUTPOST;
	}

	private static function legacy_cutoff(): int {
		$schema = get_option( self::SCHEMA_OPTION, false );
		if ( ! is_array( $schema ) ) {
			// No marker yet (ensure_schema hasn't run on this request):
			// a site where Outpost ran before is an upgrade.
			return false !== get_option( 'outpost_rewrite_version', false ) ? PHP_INT_MAX : 0;
		}
		return isset( $schema['legacy_cutoff'] ) ? (int) $schema['legacy_cutoff'] : 0;
	}

	private static function registered_timestamp( int $user_id ): ?int {
		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) || ! isset( $user->user_registered ) ) {
			return null;
		}
		$stamp = strtotime( (string) $user->user_registered . ' UTC' );
		return false === $stamp ? null : $stamp;
	}

	private static function now(): int {
		if ( null !== self::$clock_for_tests ) {
			return (int) call_user_func( self::$clock_for_tests );
		}
		return time();
	}
}
