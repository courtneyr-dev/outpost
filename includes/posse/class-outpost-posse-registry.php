<?php
/**
 * Outpost_POSSE_Registry (G3.5b).
 *
 * Static registry of POSSE-outbound destinations. Concrete destinations
 * register themselves on the `init` hook (or via the
 * `outpost_posse_destinations` filter for site-config-only registration).
 *
 * The dispatcher reads the registry by destination id when it fires a
 * scheduled cron event, so destinations must be registered BEFORE the
 * cron event fires. `init` is the natural spot.
 *
 * @package Outpost
 * @since   0.1.78
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Registry {

	/**
	 * @var array<string,Outpost_POSSE_Destination_Base>
	 */
	private static array $destinations = array();

	/**
	 * Register a destination. Idempotent — re-registering the same id
	 * overwrites the previous instance (useful for tests).
	 *
	 * @since 0.1.78
	 */
	public static function register( Outpost_POSSE_Destination_Base $destination ): void {
		self::$destinations[ $destination->id() ] = $destination;
	}

	/**
	 * Look up a destination by id, applying the
	 * `outpost_posse_destinations` filter so site owners can extend or
	 * replace registered destinations declaratively.
	 *
	 * @since 0.1.78
	 */
	public static function get( string $id ): ?Outpost_POSSE_Destination_Base {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Return every registered destination, applying the
	 * `outpost_posse_destinations` filter for site overrides.
	 *
	 * @since 0.1.78
	 *
	 * @return array<string,Outpost_POSSE_Destination_Base>
	 */
	public static function all(): array {
		/**
		 * Filter the registered POSSE destinations.
		 *
		 * @since 0.1.78
		 *
		 * @param array<string,Outpost_POSSE_Destination_Base> $destinations Keyed by id.
		 */
		$filtered = apply_filters( 'outpost_posse_destinations', self::$destinations );
		if ( ! is_array( $filtered ) ) {
			return self::$destinations;
		}
		// Defensive: keep only Destination_Base instances even if a filter
		// returns garbage.
		$out = array();
		foreach ( $filtered as $id => $instance ) {
			if ( is_string( $id ) && $instance instanceof Outpost_POSSE_Destination_Base ) {
				$out[ $id ] = $instance;
			}
		}
		return $out;
	}

	/**
	 * Test seam: clear the registry between tests.
	 *
	 * @since 0.1.78
	 */
	public static function reset_for_tests(): void {
		self::$destinations = array();
	}
}
