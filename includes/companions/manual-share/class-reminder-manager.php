<?php
/**
 * Outpost_Manual_Share_Reminder_Manager
 *
 * Helpers for the F13 reminder UX. Three responsibilities:
 *
 *   - Compute "is this entry currently visible in prompts?" by
 *     comparing `reminder_dismissed_until` to now.
 *   - Filter pending-detector results so prompt UI only surfaces
 *     entries the user hasn't snoozed.
 *   - Snooze-all rate-limiting via per-user transient.
 *
 * Reads + writes happen through {@see Outpost_Manual_Share_Audit_Log};
 * this class is computation + visibility filtering, not storage.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Reminder_Manager {

	/** Snooze duration constants (seconds-from-now). */
	public const SNOOZE_ONE_DAY    = 'P1D';
	public const SNOOZE_THREE_DAYS = 'P3D';
	public const SNOOZE_ONE_WEEK   = 'P7D';
	public const SNOOZE_FOREVER    = 'forever';

	/** Transient key prefix for the snooze-all rate-limit. */
	private const SNOOZE_ALL_TRANSIENT_PREFIX = 'outpost_snooze_all_user_';

	/** Snooze-all rate-limit window (seconds since last invocation). */
	private const SNOOZE_ALL_RATE_LIMIT_SECONDS = 300;

	/**
	 * Whether an entry is currently snoozed (reminder timestamp in the
	 * future). Abandoned entries (sentinel timestamp) ALSO return true
	 * here — they're effectively snoozed forever.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function is_snoozed( array $entry ): bool {
		$until = $entry['reminder_dismissed_until'] ?? null;
		if ( ! is_string( $until ) || '' === $until ) {
			return false;
		}
		$ts = strtotime( $until );
		if ( false === $ts ) {
			return false;
		}
		return $ts > time();
	}

	/**
	 * Filter a list of pending audit log entries down to ones currently
	 * visible to the user — drops snoozed and abandoned entries.
	 *
	 * @param array<int, array<string,mixed>> $entries
	 * @return array<int, array<string,mixed>>
	 */
	public static function visible_entries( array $entries ): array {
		return array_values(
			array_filter(
				$entries,
				static fn ( array $entry ): bool => ! self::is_snoozed( $entry )
			)
		);
	}

	/**
	 * Resolve a snooze duration string to a concrete ISO 8601 UTC
	 * timestamp suitable for `reminder_dismissed_until`. Accepts
	 * the SNOOZE_* constants plus any ISO 8601 string the caller
	 * passes through directly.
	 *
	 * @param string $duration Snooze duration constant or ISO 8601 string.
	 * @return string|null ISO 8601 UTC timestamp, or null when input is invalid.
	 */
	public static function resolve_until( string $duration ): ?string {
		if ( '' === $duration ) {
			return null;
		}
		if ( self::SNOOZE_FOREVER === strtolower( $duration ) ) {
			return Outpost_Manual_Share_Audit_Log::ABANDONED_REMINDER_SENTINEL;
		}
		// PHP DateInterval shorthand (P1D etc.)
		if ( 1 === preg_match( '/^P\d+[DWMY]$/', $duration ) ) {
			try {
				$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				$end = $now->add( new \DateInterval( $duration ) );
				return $end->format( 'c' );
			} catch ( \Exception $e ) {
				unset( $e );
				return null;
			}
		}
		// Otherwise treat as a strtotime-able string.
		$ts = strtotime( $duration );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'c', $ts );
	}

	/**
	 * Whether the user can run snooze-all again. F13 rate-limits to
	 * once per ~5 minutes per user via a transient — guards against
	 * accidental double-clicks snoozing weeks of pending state.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function can_snooze_all( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return false === get_transient( self::transient_key( $user_id ) );
	}

	/**
	 * Mark snooze-all as recently used for rate-limit purposes.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function record_snooze_all( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			self::transient_key( $user_id ),
			time(),
			self::SNOOZE_ALL_RATE_LIMIT_SECONDS
		);
	}

	/**
	 * Reset rate limit for tests.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function reset_rate_limit_for_tests( int $user_id ): void {
		delete_transient( self::transient_key( $user_id ) );
	}

	private static function transient_key( int $user_id ): string {
		return self::SNOOZE_ALL_TRANSIENT_PREFIX . $user_id;
	}
}
