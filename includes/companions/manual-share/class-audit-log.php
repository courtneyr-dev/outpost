<?php
/**
 * Outpost_Manual_Share_Audit_Log
 *
 * Read/write helper for the `outpost_manual_share_log` post-meta key.
 * F10 establishes the write path on chip-tap (intent firing); F12 will
 * extend it with completion-tracking when the user comes back with a
 * silo URL; F13 surfaces the log in admin notices and composer UI.
 *
 * Entry shape (versioned):
 *
 *     array(
 *         'id'           => 'a1b2c3d4-...',  // server-generated, returned to PWA
 *         'version'      => 1,
 *         'platform_id'  => 'instagram-feed',
 *         'fired_at'     => '2026-05-04T18:32:11+00:00',
 *         'strategy'     => 'navigator_share' | 'intent_url' | 'two_tap_fallback',
 *         'outcome'      => 'fired' | 'rejected' | 'aborted' | 'unknown',
 *         'completed_at' => null,    // F12 sets when user returns with silo URL
 *         'silo_url'     => null,    // F12 sets
 *     )
 *
 * The log is per-post — `outpost_manual_share_log` post meta holds an
 * array of these entries. Each chip-tap appends. Per-post layout means
 * a single post's share history is one query; aggregate analytics
 * across all posts is a future concern (Phase H+).
 *
 * Why per-meta vs. a custom table: post-meta auto-cleans on post
 * deletion (no orphan analytics rows), preserves on post-restore from
 * trash, and integrates with WP's standard backup/export tooling. The
 * downside (cross-post queries are slow) doesn't matter for F10's
 * write-and-display-on-the-same-post use case.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Audit_Log {

	private const META_KEY      = 'outpost_manual_share_log';
	private const ENTRY_VERSION = 1;
	/**
	 * Version 2 introduced in F13 — adds the optional
	 * `reminder_dismissed_until` field. Entries without the field
	 * stay at v1; entries written with the field bump to v2 in
	 * {@see self::update_entry}.
	 */
	private const ENTRY_VERSION_WITH_REMINDER = 2;

	/**
	 * Far-future timestamp used as the "abandoned" sentinel value
	 * for `reminder_dismissed_until`. Picking 2200 keeps the entry
	 * shape parseable by `strtotime` while comfortably outside any
	 * realistic snooze duration.
	 */
	public const ABANDONED_REMINDER_SENTINEL = '2200-01-01T00:00:00+00:00';

	/**
	 * Allowed strategy values. Mirrors the strategies the PWA reports
	 * via /manual-share/intent/log telemetry.
	 */
	public const STRATEGY_NAVIGATOR_SHARE = 'navigator_share';
	public const STRATEGY_INTENT_URL      = 'intent_url';
	public const STRATEGY_TWO_TAP         = 'two_tap_fallback';

	/**
	 * Allowed outcome values.
	 *
	 * - `fired`    — share invocation succeeded server-side; PWA may
	 *                follow up with telemetry confirming user actually
	 *                completed the share.
	 * - `rejected` — PWA reported the share was rejected by the
	 *                browser/OS (user cancelled, no apps to handle, etc.).
	 * - `aborted`  — PWA reported an error during share (network failure,
	 *                clipboard write blocked, etc.).
	 * - `unknown`  — placeholder when no telemetry has come back yet.
	 */
	public const OUTCOME_FIRED    = 'fired';
	public const OUTCOME_REJECTED = 'rejected';
	public const OUTCOME_ABORTED  = 'aborted';
	public const OUTCOME_UNKNOWN  = 'unknown';

	/**
	 * Append a new entry. Generates the entry id, fills `fired_at`,
	 * persists, and returns the full entry shape so callers can pass
	 * the id back to the PWA in the intent payload.
	 *
	 * @param int    $post_id     Post the share is for.
	 * @param string $platform_id Platform id (e.g. 'instagram-feed').
	 * @param string $strategy    One of the STRATEGY_* constants.
	 * @return array<string,mixed> The new entry shape.
	 */
	public static function add_entry( int $post_id, string $platform_id, string $strategy ): array {
		$entry = array(
			'id'           => self::generate_id(),
			'version'      => self::ENTRY_VERSION,
			'platform_id'  => $platform_id,
			'fired_at'     => gmdate( 'c' ),
			'strategy'     => $strategy,
			'outcome'      => self::OUTCOME_UNKNOWN,
			'completed_at' => null,
			'silo_url'     => null,
		);

		$existing   = self::get_entries( $post_id );
		$existing[] = $entry;
		update_post_meta( $post_id, self::META_KEY, $existing );

		return $entry;
	}

	/**
	 * Update an existing entry by id. Currently used by the F10
	 * telemetry endpoint to record the user-reported outcome and by
	 * F12 to record `completed_at` + `silo_url`.
	 *
	 * Only the keys listed in `$patch` get touched; unknown keys are
	 * dropped to prevent the PWA from injecting arbitrary data into
	 * post meta. Allowed patch keys: `outcome`, `completed_at`,
	 * `silo_url`.
	 *
	 * @param int                  $post_id  Post id.
	 * @param string               $entry_id Entry id from `add_entry()`.
	 * @param array<string,mixed> $patch    Fields to update.
	 * @return bool True on success, false when the entry id was not found.
	 */
	public static function update_entry( int $post_id, string $entry_id, array $patch ): bool {
		$entries = self::get_entries( $post_id );
		$found   = false;
		foreach ( $entries as &$entry ) {
			if ( ( $entry['id'] ?? '' ) === $entry_id ) {
				if ( array_key_exists( 'outcome', $patch ) ) {
					$entry['outcome'] = self::sanitize_outcome( (string) $patch['outcome'] );
				}
				if ( array_key_exists( 'completed_at', $patch ) ) {
					$entry['completed_at'] = self::sanitize_completed_at( $patch['completed_at'] );
				}
				if ( array_key_exists( 'silo_url', $patch ) ) {
					$entry['silo_url'] = self::sanitize_silo_url( $patch['silo_url'] );
				}
				if ( array_key_exists( 'reminder_dismissed_until', $patch ) ) {
					$entry['reminder_dismissed_until'] = self::sanitize_reminder_until(
						$patch['reminder_dismissed_until']
					);
					// Bump entry to v2 once the field is set so future
					// readers can rely on its presence to know which
					// schema applies.
					$entry['version'] = self::ENTRY_VERSION_WITH_REMINDER;
				}
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, $entries );
		return true;
	}

	/**
	 * Read all entries for a post. Returns an empty array when the post
	 * has none.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_entries( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['version'] ) ) {
				continue;
			}
			// F13: backwards-compatible read — v1 entries don't have
			// `reminder_dismissed_until`; default to null so callers
			// can rely on the key always being present.
			if ( ! array_key_exists( 'reminder_dismissed_until', $entry ) ) {
				$entry['reminder_dismissed_until'] = null;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Generate an opaque entry id. Uses wp_generate_uuid4() when
	 * available, falls back to a hash for early-load test paths.
	 */
	private static function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		// Test-time fallback: random 32-char hex.
		return bin2hex( random_bytes( 16 ) );
	}

	private static function sanitize_outcome( string $outcome ): string {
		$allowed = array(
			self::OUTCOME_FIRED,
			self::OUTCOME_REJECTED,
			self::OUTCOME_ABORTED,
			self::OUTCOME_UNKNOWN,
		);
		return in_array( $outcome, $allowed, true ) ? $outcome : self::OUTCOME_UNKNOWN;
	}

	/**
	 * Coerce a `completed_at` value to ISO 8601 string or null.
	 *
	 * @param mixed $value
	 */
	private static function sanitize_completed_at( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		// Accept ISO 8601 / RFC 3339 timestamps. strtotime returns
		// false on invalid; we re-emit canonical form via gmdate.
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'c', $ts );
	}

	/**
	 * Coerce a silo URL to a sanitized https?:// URL or null.
	 *
	 * @param mixed $value
	 */
	/**
	 * Coerce a `reminder_dismissed_until` value to ISO 8601 string,
	 * the abandoned sentinel, or null. Accepts the literal string
	 * 'forever' as a convenience for callers (mapped to the
	 * abandoned sentinel constant).
	 *
	 * @param mixed $value
	 */
	private static function sanitize_reminder_until( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 'forever' === strtolower( $value ) ) {
			return self::ABANDONED_REMINDER_SENTINEL;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'c', $ts );
	}

	/**
	 * @param mixed $value Raw value from patch input.
	 */
	private static function sanitize_silo_url( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$clean = esc_url_raw( $value );
		if ( '' === $clean ) {
			return null;
		}
		// Restrict to http(s) — silo URLs should never be javascript:,
		// data:, or app-scheme URLs in audit-log persistence.
		if ( 0 !== strpos( $clean, 'http://' ) && 0 !== strpos( $clean, 'https://' ) ) {
			return null;
		}
		return $clean;
	}
}
