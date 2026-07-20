<?php
/**
 * Outpost_Manual_Share_Pending_Capture_Detector
 *
 * Finds posts with manual-share audit log entries that are still
 * pending phase-2 capture (the user fired an intent but hasn't yet
 * pasted back the silo URL). The composer's "Pending Syndications"
 * panel and the WP admin edit-screen notice both consume this output.
 *
 * Pending criteria:
 *
 *   - `completed_at` is null (no user-supplied silo URL yet)
 *   - `fired_at` is older than the grace period (30 seconds default)
 *     so the prompt doesn't appear during the share itself
 *   - `fired_at` is newer than the retention window (30 days default)
 *     so abandoned shares don't accumulate forever
 *
 * Why per-user scope: the natural query is "show me MY pending
 * captures." A site-wide aggregate query is a future Phase H concern;
 * F12 ships per-author scope. Posts the user can edit but didn't
 * author are NOT shown — the prompt nudges the original sharer.
 *
 * Implementation uses `WP_Query` with `meta_query` for `EXISTS`
 * matching on the `outpost_manual_share_log` meta key, then iterates
 * candidate posts in PHP to filter by date + completion. Manual-share
 * is low-volume; the in-PHP filter is fine until volume justifies a
 * sidecar index meta key (future Phase H if it becomes hot).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Pending_Capture_Detector {

	/** Default grace period before a fired entry surfaces in the prompt. */
	public const DEFAULT_GRACE_SECONDS = 30;

	/** Default retention window after which abandoned entries are hidden. */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Optional override for the candidate-post-id resolver. Used by
	 * unit tests to stub the WP_Query layer without touching globals.
	 *
	 * @var (callable(int): int[])|null
	 */
	private static $candidate_resolver = null;

	/**
	 * Inject a candidate-post-id resolver. Test seam — production
	 * uses the default WP_Query path. Resolver returns post IDs for
	 * a given user ID.
	 *
	 * @param (callable(int): int[])|null $resolver
	 */
	public static function set_candidate_resolver_for_tests( ?callable $resolver ): void {
		self::$candidate_resolver = $resolver;
	}

	/**
	 * Find pending entries for a user. Returns one record per post
	 * with at least one pending audit-log entry; the record carries
	 * the post-level data + a list of pending entries on that post.
	 *
	 * Shape:
	 *
	 *     array(
	 *         array(
	 *             'post_id'    => 42,
	 *             'post_title' => 'Sample post title',
	 *             'permalink'  => 'https://example.com/posts/42',
	 *             'entries'    => array(
	 *                 array(
	 *                     'id'           => 'uuid-...',
	 *                     'platform_id'  => 'instagram-feed',
	 *                     'fired_at'     => '2026-05-04T18:32:11+00:00',
	 *                     'strategy'     => 'navigator_share',
	 *                     ...
	 *                 ),
	 *             ),
	 *         ),
	 *     )
	 *
	 * Sort: posts ordered by most-recent pending fired_at descending.
	 *
	 * @param int $user_id        WordPress user ID (post author).
	 * @param int $grace_seconds  Hide entries fired within this window.
	 * @param int $retention_days Hide entries older than this window.
	 * @return array<int, array<string,mixed>>
	 */
	public static function pending_for_user(
		int $user_id,
		int $grace_seconds = self::DEFAULT_GRACE_SECONDS,
		int $retention_days = self::DEFAULT_RETENTION_DAYS
	): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$candidate_post_ids = null !== self::$candidate_resolver
			? array_map( 'intval', ( self::$candidate_resolver )( $user_id ) )
			: self::candidate_post_ids( $user_id );
		if ( empty( $candidate_post_ids ) ) {
			return array();
		}

		$now           = time();
		$grace_cutoff  = $now - max( 0, $grace_seconds );
		$retention_cut = $now - max( 0, $retention_days * DAY_IN_SECONDS );

		$results = array();
		foreach ( $candidate_post_ids as $post_id ) {
			$entries = self::pending_entries_for_post(
				$post_id,
				$grace_cutoff,
				$retention_cut
			);
			if ( empty( $entries ) ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$results[] = array(
				'post_id'    => $post_id,
				'post_title' => (string) $post->post_title,
				'permalink'  => (string) get_permalink( $post_id ),
				'entries'    => $entries,
			);
		}

		// Sort posts by most-recent pending fired_at descending.
		usort(
			$results,
			static function ( array $a, array $b ): int {
				$most_recent_a = self::most_recent_fired_at( $a['entries'] );
				$most_recent_b = self::most_recent_fired_at( $b['entries'] );
				return $most_recent_b <=> $most_recent_a;
			}
		);

		return $results;
	}

	/**
	 * @return int[]
	 */
	private static function candidate_post_ids( int $user_id ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed lookup required; result set is small.
				'meta_query'     => array(
					array(
						'key'     => 'outpost_manual_share_log',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Filter the audit log entries for a single post down to the ones
	 * still pending capture within the grace + retention windows AND
	 * not currently snoozed/abandoned (F13 visibility filter).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private static function pending_entries_for_post(
		int $post_id,
		int $grace_cutoff,
		int $retention_cut
	): array {
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( $post_id );
		$out     = array();
		foreach ( $entries as $entry ) {
			if ( null !== ( $entry['completed_at'] ?? null ) ) {
				continue;
			}
			if ( ! isset( $entry['fired_at'] ) || ! is_string( $entry['fired_at'] ) ) {
				continue;
			}
			$fired_ts = strtotime( $entry['fired_at'] );
			if ( false === $fired_ts ) {
				continue;
			}
			if ( $fired_ts > $grace_cutoff ) {
				// Within grace period — too recent to nudge.
				continue;
			}
			if ( $fired_ts < $retention_cut ) {
				// Past retention window.
				continue;
			}
			// F13: drop entries the user has snoozed or marked abandoned.
			// Detail view still shows them; this filter governs the
			// prompt UX only.
			if ( Outpost_Manual_Share_Reminder_Manager::is_snoozed( $entry ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * @param array<int, array<string,mixed>> $entries
	 */
	private static function most_recent_fired_at( array $entries ): int {
		$max = 0;
		foreach ( $entries as $entry ) {
			$ts = isset( $entry['fired_at'] ) && is_string( $entry['fired_at'] )
				? (int) strtotime( $entry['fired_at'] )
				: 0;
			if ( $ts > $max ) {
				$max = $ts;
			}
		}
		return $max;
	}
}
