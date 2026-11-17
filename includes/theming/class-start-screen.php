<?php
/**
 * Outpost_Start_Screen
 *
 * Per-user choice of the screen the composer opens to on a normal launch:
 * Home (the launcher), one of the six composing tabs, or About. The values
 * are the PWA's destination ids, so Post is `note` and Doing is `listen`.
 *
 * Only a normal launch reads it. A share-sheet or bookmarklet launch opens
 * the tab its content belongs in, and the preference never moves someone
 * who is already composing.
 *
 * Not the same setting as `default_post_variant` in `outpost_settings`,
 * which is site-wide and picks the style the Post tab starts on.
 *
 * Lives beside the appearance preferences because the PWA reads and saves
 * all three through `/wp-json/outpost/v1/appearance/tokens`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Start_Screen {

	public const META_KEY = 'outpost_start_screen';

	public const DEFAULT_SCREEN = 'home';

	/** @var string[] Home, the six composing tabs in tab order, then About. */
	public const VALID_SCREENS = array( 'home', 'note', 'reply', 'photo', 'listen', 'life', 'recipe', 'about' );

	/**
	 * The user's start screen; Home when nothing valid is stored.
	 *
	 * @param int $user_id User; 0 means current user.
	 */
	public static function get( int $user_id ): string {
		$stored = self::stored( $user_id );
		return null === $stored ? self::DEFAULT_SCREEN : $stored;
	}

	public static function has_stored( int $user_id ): bool {
		return null !== self::stored( $user_id );
	}

	public static function is_valid( string $screen ): bool {
		return in_array( $screen, self::VALID_SCREENS, true );
	}

	/**
	 * Persist the user's choice. Rejects unknown values.
	 */
	public static function set( int $user_id, string $screen ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$normalized = strtolower( trim( $screen ) );
		if ( ! self::is_valid( $normalized ) ) {
			return false;
		}
		if ( get_user_meta( $user_id, self::META_KEY, true ) === $normalized ) {
			return true;
		}
		return false !== update_user_meta( $user_id, self::META_KEY, $normalized );
	}

	private static function stored( int $user_id ): ?string {
		if ( 0 === $user_id ) {
			$user_id = (int) get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return null;
		}
		$value = get_user_meta( $user_id, self::META_KEY, true );
		return is_string( $value ) && self::is_valid( $value ) ? $value : null;
	}
}
