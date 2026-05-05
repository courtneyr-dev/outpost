<?php
/**
 * Outpost_Mode_Controller
 *
 * Reads + writes the user's day/night/system mode preference. The
 * preference is per-user (user-meta), not per-site (option), so two
 * contributors on the same WordPress install can have independent
 * appearance settings — a personal-blog tool that forces one user's
 * dark-mode preference on another would feel wrong.
 *
 * Three modes:
 *   - 'day'    — composer always renders day token set
 *   - 'night'  — composer always renders night token set
 *   - 'system' — composer respects `prefers-color-scheme`
 *
 * Default for new users: 'system'. CSS class on the composer root
 * is `outpost-mode-day`, `outpost-mode-night`, or `outpost-mode-system`
 * accordingly; the system class plus the dark-preference media query
 * handles client-side switching.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Mode_Controller {

	public const META_KEY = 'outpost_appearance_mode';

	public const MODE_DAY    = 'day';
	public const MODE_NIGHT  = 'night';
	public const MODE_SYSTEM = 'system';

	/** @var string[] All valid mode values. */
	public const VALID_MODES = array(
		self::MODE_DAY,
		self::MODE_NIGHT,
		self::MODE_SYSTEM,
	);

	/**
	 * Return the user's mode preference. Falls back to 'system' for
	 * users who haven't picked one yet, and for any value that isn't
	 * one of the three valid modes.
	 *
	 * @param int $user_id User to read; passing 0 means "current user."
	 */
	public static function get_mode( int $user_id ): string {
		if ( 0 === $user_id ) {
			$user_id = (int) get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return self::MODE_SYSTEM;
		}
		$value = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_string( $value ) ) {
			return self::MODE_SYSTEM;
		}
		$value = strtolower( $value );
		if ( ! in_array( $value, self::VALID_MODES, true ) ) {
			return self::MODE_SYSTEM;
		}
		return $value;
	}

	/**
	 * Persist the user's mode preference. Rejects invalid values to
	 * keep user-meta storage shape stable.
	 *
	 * @param int    $user_id User to write.
	 * @param string $mode    'day' | 'night' | 'system'.
	 * @return bool True on successful write, false on invalid input or persistence failure.
	 */
	public static function set_mode( int $user_id, string $mode ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$normalized = strtolower( trim( $mode ) );
		if ( ! in_array( $normalized, self::VALID_MODES, true ) ) {
			return false;
		}
		$result = update_user_meta( $user_id, self::META_KEY, $normalized );
		return false !== $result;
	}

	/**
	 * Return the CSS class the composer's root element should carry
	 * for the requesting user's mode preference.
	 *
	 * @param int $user_id User to read; 0 means "current user."
	 */
	public static function root_class_for_user( int $user_id ): string {
		return 'outpost-mode-' . self::get_mode( $user_id );
	}
}
