<?php
/**
 * Outpost_Manual_Share_Status_Computer
 *
 * Derives a single per-post syndication status string from the audit
 * log entries. The status drives badge rendering in the composer's
 * Recent Posts sidebar + the WP admin posts-list "Syndication"
 * column. Status is computed on read; never stored.
 *
 * Status states:
 *
 *   'no_syndication' — post has no audit log entries at all
 *   'complete'       — every entry has completed_at set (silo URL captured)
 *   'pending'        — at least one entry not completed AND not
 *                      everything is abandoned
 *   'partial'        — some entries completed, some not (mix)
 *   'abandoned'      — no entries completed AND every entry is
 *                      abandoned (reminder_dismissed_until > +10y)
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Status_Computer {

	public const STATUS_NO_SYNDICATION = 'no_syndication';
	public const STATUS_COMPLETE       = 'complete';
	public const STATUS_PARTIAL        = 'partial';
	public const STATUS_PENDING        = 'pending';
	public const STATUS_ABANDONED      = 'abandoned';

	/**
	 * Compute the syndication status for a post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function compute_status_for_post( int $post_id ): string {
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( $post_id );
		return self::compute_status_for_entries( $entries );
	}

	/**
	 * Same as {@see self::compute_status_for_post} but operates on a
	 * pre-fetched entries array. Used by the admin posts-list column
	 * which fetches all rows up front.
	 *
	 * @param array<int, array<string,mixed>> $entries
	 */
	public static function compute_status_for_entries( array $entries ): string {
		if ( empty( $entries ) ) {
			return self::STATUS_NO_SYNDICATION;
		}

		$total     = count( $entries );
		$complete  = 0;
		$abandoned = 0;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['completed_at'] ) ) {
				++$complete;
				continue;
			}
			if ( self::is_abandoned( $entry ) ) {
				++$abandoned;
			}
		}

		if ( $complete === $total ) {
			return self::STATUS_COMPLETE;
		}
		if ( 0 === $complete && $abandoned === $total ) {
			return self::STATUS_ABANDONED;
		}
		if ( $complete > 0 ) {
			return self::STATUS_PARTIAL;
		}
		return self::STATUS_PENDING;
	}

	/**
	 * Summary counts for an entries array — used by the badge to
	 * render "X/Y" labels.
	 *
	 * @param array<int, array<string,mixed>> $entries
	 * @return array{total:int,complete:int,pending:int,abandoned:int}
	 */
	public static function summarize_entries( array $entries ): array {
		$total     = count( $entries );
		$complete  = 0;
		$abandoned = 0;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['completed_at'] ) ) {
				++$complete;
				continue;
			}
			if ( self::is_abandoned( $entry ) ) {
				++$abandoned;
			}
		}
		$pending = $total - $complete - $abandoned;
		return array(
			'total'     => $total,
			'complete'  => $complete,
			'pending'   => $pending,
			'abandoned' => $abandoned,
		);
	}

	/**
	 * Whether an audit log entry is "abandoned" — reminder snoozed
	 * past the abandoned-sentinel threshold (effectively forever).
	 *
	 * The 10-year cutoff means short snoozes (1 day / 1 week) DO
	 * NOT count as abandoned — those entries are still pending,
	 * just temporarily hidden from prompt UX.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function is_abandoned( array $entry ): bool {
		$until = $entry['reminder_dismissed_until'] ?? null;
		if ( ! is_string( $until ) || '' === $until ) {
			return false;
		}
		$ts = strtotime( $until );
		if ( false === $ts ) {
			return false;
		}
		return $ts > strtotime( '+10 years' );
	}
}
