<?php
/**
 * Outpost_Bridgy_Publish_Settings
 *
 * Per-silo opt-in toggles persisted in WP options. F14's authoritative
 * "is this Bridgy silo configured?" signal — settings drive chip
 * visibility (P5 of CLAUDE.md F14). Default state: every silo
 * disabled. User must explicitly opt in after configuring brid.gy.
 *
 * Storage shape (single option, JSON-style array):
 *
 *     get_option( 'outpost_bridgy_silos_enabled', array() ) =>
 *         array(
 *             'bridgy-mastodon' => true,
 *             'bridgy-flickr'   => true,
 *             // unset silos default false
 *         )
 *
 * Static-only API. Tests can reset via `clear_for_tests()`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Settings {

	private const OPTION_KEY = 'outpost_bridgy_silos_enabled';

	/**
	 * Whether a specific Bridgy silo chip is enabled.
	 *
	 * @param string $silo_chip_id Silo chip id (e.g. 'bridgy-mastodon').
	 */
	public static function is_enabled( string $silo_chip_id ): bool {
		$enabled = self::all_enabled();
		return ! empty( $enabled[ $silo_chip_id ] );
	}

	/**
	 * Set the toggle for a specific silo chip. Persists to options.
	 */
	public static function set_enabled( string $silo_chip_id, bool $enabled ): void {
		$current = self::all_enabled();
		if ( $enabled ) {
			$current[ $silo_chip_id ] = true;
		} else {
			unset( $current[ $silo_chip_id ] );
		}
		update_option( self::OPTION_KEY, $current );
	}

	/**
	 * Read the full enabled-silo map.
	 *
	 * @return array<string, bool>
	 */
	public static function all_enabled(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $key => $value ) {
			if ( is_string( $key ) && true === $value ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Test hook. Production code never needs this.
	 */
	public static function clear_for_tests(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * The option key — exposed so the admin page + tests can reference
	 * one constant.
	 */
	public static function option_key(): string {
		return self::OPTION_KEY;
	}
}
