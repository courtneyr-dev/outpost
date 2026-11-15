<?php
/**
 * Outpost_POSSE_Dispatcher (G3.5b).
 *
 * Wp-cron-driven dispatcher for POSSE-outbound. Hooks
 * `transition_post_status` to schedule a single-event cron 30 seconds
 * after publish (locked decision #1; gives the user a "wait, typo"
 * window before syndication fires).
 *
 * Lifecycle (per G3.5b prompt):
 *
 *   1. transition_post_status to 'publish' fires for a public post: an
 *      allowed type (`post` by default, see `outpost_posse_post_types`)
 *      with no password. Anything else schedules nothing.
 *   2. read _outpost_posse_targets from post_meta.
 *   3. for each target NOT already in _outpost_posse_in_flight:
 *      - add to _outpost_posse_in_flight (idempotency seal)
 *      - schedule wp-cron event 'outpost_posse_dispatch' at +30s with
 *        args [ post_id, destination_id, attempt = 1 ].
 *   4. cron fires → handle_dispatch( post_id, destination_id, attempt ):
 *      - re-read the post; unless it's still public (published, no
 *        password, allowed type), record the non-retrying failure
 *        `post_not_public`, remove in-flight, and exit. This runs on every
 *        attempt, retries included, so a post made draft, private,
 *        trashed or password-protected after scheduling never leaves.
 *      - resolve destination via registry; if missing, record failure + exit
 *      - call destination->dispatch( post_id )
 *      - on success: write to _outpost_syndication_urls, remove in-flight
 *      - on retryable failure with attempts < 3: schedule next retry at
 *        next_retry_timestamp( attempt + 1 )
 *      - on non-retryable failure OR retryable+attempts==3: write to
 *        _outpost_posse_failures, remove in-flight
 *
 * Retry timing per locked decision #3: 30s, 5min, 30min.
 *
 * Idempotency by destination id + post id + post_modified — re-publish
 * only re-fires when the post is genuinely re-published (not just
 * resaved without status change).
 *
 * @package Outpost
 * @since   0.1.78
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Dispatcher {

	public const CRON_HOOK = 'outpost_posse_dispatch';

	public const SCHEDULE_DELAY_SECONDS = 30;

	public const MAX_ATTEMPTS = 3;

	/**
	 * Failure reason recorded when a post is no longer public at dispatch.
	 */
	public const NOT_PUBLIC = 'post_not_public';

	/**
	 * Hook everything onto WordPress: the publish-trigger that schedules
	 * dispatches, and the cron handler that runs them.
	 *
	 * @since 0.1.78
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_schedule_on_publish' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'handle_dispatch' ), 10, 3 );
	}

	/**
	 * Backoff schedule. Test seam: pure function on `attempt`.
	 *
	 * Attempt 1 → +30s (initial schedule),
	 * Attempt 2 → +5 minutes,
	 * Attempt 3 → +30 minutes.
	 *
	 * Returns absolute Unix timestamp.
	 *
	 * @since 0.1.78
	 */
	public static function next_retry_timestamp( int $attempt ): int {
		$delays = array(
			1 => self::SCHEDULE_DELAY_SECONDS,
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 30 * MINUTE_IN_SECONDS,
		);
		$delay  = $delays[ $attempt ] ?? 30 * MINUTE_IN_SECONDS;
		return time() + $delay;
	}

	/**
	 * Whether a post may leave the site through POSSE: it's published, has
	 * no password, and is of an allowed type. Fails closed on anything that
	 * isn't a post object or lacks one of those fields.
	 *
	 * The scheduler, the dispatcher (on every attempt), and each direct
	 * destination all call this.
	 *
	 * @since 1.0.28
	 *
	 * @param mixed $post WP_Post (or a post-shaped object).
	 */
	public static function post_is_public( $post ): bool {
		if ( ! is_object( $post ) || ! isset( $post->post_status, $post->post_type ) ) {
			return false;
		}
		if ( 'publish' !== (string) $post->post_status ) {
			return false;
		}
		if ( ! isset( $post->post_password ) || '' !== (string) $post->post_password ) {
			return false;
		}
		/**
		 * Filters the post types POSSE may send.
		 *
		 * @since 1.0.28
		 *
		 * @param string[] $types Post type names. Default `array( 'post' )`.
		 */
		$types = apply_filters( 'outpost_posse_post_types', array( 'post' ) );
		if ( ! is_array( $types ) ) {
			return false;
		}
		return in_array( (string) $post->post_type, array_map( 'strval', $types ), true );
	}

	/**
	 * Hooked on transition_post_status. Schedules a dispatch for each
	 * registered target on the post's _outpost_posse_targets list, when
	 * the transition is into `publish` (and out of any non-publish state).
	 *
	 * @since 0.1.78
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param mixed   $post       WP_Post instance.
	 */
	public static function maybe_schedule_on_publish( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}
		if ( $new_status === $old_status ) {
			// Edits to an already-published post don't re-fire; only
			// transitions INTO publish trigger dispatch. Per locked
			// decision #8 (idempotency by destination + post + post_modified):
			// a re-publish is a fresh transition; an edit is not.
			return;
		}
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return;
		}
		// Only a public post is scheduled: a password-protected post, or a
		// type the site hasn't allowed (pages, by default), never is.
		if ( ! self::post_is_public( $post ) ) {
			return;
		}
		$post_id = (int) $post->ID;

		// When a real user drove this transition, they must be able to edit
		// the post. Without this the check below authorizes the post's
		// author, not the actor, so a user who can flip another author's
		// post into `publish` syndicates content they cannot edit.
		// Cron and WP-CLI have no current user; those keep the author check.
		if ( get_current_user_id() > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$publishing_user_id = (int) ( $post->post_author ?? get_current_user_id() );
		if ( ! user_can( $publishing_user_id, 'publish_posts' ) ) {
			return;
		}

		$targets = Outpost_POSSE_Meta::get_targets( $post_id );
		if ( empty( $targets ) ) {
			return;
		}

		$in_flight = Outpost_POSSE_Meta::get_in_flight( $post_id );

		foreach ( $targets as $destination_id ) {
			if ( in_array( $destination_id, $in_flight, true ) ) {
				continue;
			}
			/**
			 * Veto a single destination dispatch. Returning false skips
			 * the destination for this post (e.g., third-party plugin
			 * decides "skip POSSE for posts older than 24h").
			 *
			 * @since 0.1.78
			 *
			 * @param bool   $should_dispatch  True to proceed, false to veto.
			 * @param int    $post_id          Post id.
			 * @param string $destination_id   Destination id.
			 */
			$should = (bool) apply_filters( 'outpost_posse_should_dispatch', true, $post_id, $destination_id );
			if ( ! $should ) {
				continue;
			}

			Outpost_POSSE_Meta::add_in_flight( $post_id, $destination_id );
			wp_schedule_single_event(
				self::next_retry_timestamp( 1 ),
				self::CRON_HOOK,
				array( $post_id, $destination_id, 1 )
			);
		}
	}

	/**
	 * Hooked on the wp-cron event. Resolves the destination, calls
	 * dispatch(), and routes the result to the appropriate post-meta
	 * accountant.
	 *
	 * @since 0.1.78
	 *
	 * @param int    $post_id        Post id.
	 * @param string $destination_id Destination id.
	 * @param int    $attempt        Attempt number (1-indexed).
	 */
	public static function handle_dispatch( int $post_id, string $destination_id, int $attempt ): void {
		// Re-read the post on every attempt. A post deleted since scheduling
		// has no meta left to update, so there is nothing to record.
		$post = get_post( $post_id );
		if ( null === $post ) {
			return;
		}
		if ( ! self::post_is_public( $post ) ) {
			Outpost_POSSE_Meta::add_failure( $post_id, $destination_id, self::NOT_PUBLIC, $attempt );
			Outpost_POSSE_Meta::remove_in_flight( $post_id, $destination_id );
			return;
		}

		$destination = Outpost_POSSE_Registry::get( $destination_id );
		if ( null === $destination ) {
			Outpost_POSSE_Meta::add_failure(
				$post_id,
				$destination_id,
				'Destination not registered when cron fired.',
				$attempt
			);
			Outpost_POSSE_Meta::remove_in_flight( $post_id, $destination_id );
			return;
		}

		/**
		 * Fired before a destination's dispatch() runs. Useful for
		 * logging / instrumentation.
		 *
		 * @since 0.1.78
		 */
		do_action( 'outpost_posse_before_dispatch', $post_id, $destination_id, $attempt );

		$result = $destination->dispatch( $post_id );

		/**
		 * Fired after a destination's dispatch() runs, regardless of
		 * outcome.
		 *
		 * @since 0.1.78
		 */
		do_action( 'outpost_posse_after_dispatch', $post_id, $destination_id, $attempt, $result );

		$success         = (bool) ( $result['success'] ?? false );
		$retryable       = (bool) ( $result['retryable'] ?? false );
		$syndication_url = isset( $result['syndication_url'] ) ? (string) $result['syndication_url'] : '';
		$error           = isset( $result['error'] ) ? (string) $result['error'] : '';

		if ( $success && '' !== $syndication_url ) {
			Outpost_POSSE_Meta::add_syndication_url( $post_id, $destination_id, $syndication_url );
			Outpost_POSSE_Meta::remove_in_flight( $post_id, $destination_id );
			return;
		}

		// Non-retryable failures go straight to permanent.
		if ( ! $retryable || $attempt >= self::MAX_ATTEMPTS ) {
			Outpost_POSSE_Meta::add_failure(
				$post_id,
				$destination_id,
				'' === $error ? 'Destination dispatch failed without an error message.' : $error,
				$attempt
			);
			Outpost_POSSE_Meta::remove_in_flight( $post_id, $destination_id );
			return;
		}

		// Retryable + still under MAX_ATTEMPTS: reschedule.
		wp_schedule_single_event(
			self::next_retry_timestamp( $attempt + 1 ),
			self::CRON_HOOK,
			array( $post_id, $destination_id, $attempt + 1 )
		);
	}
}
