<?php
/**
 * Outpost_Pending_Syndication_Notice
 *
 * WP admin notice surface for the F12 phase-2 capture flow. When a
 * user views a post's edit screen AND the post has pending audit log
 * entries, render an admin notice listing the platforms that need a
 * silo URL. The notice complements the composer's PendingSyndications
 * panel — users who edit posts in WP admin (rather than in the PWA)
 * get the same prompt.
 *
 * The notice is plain HTML — it does NOT include an inline form
 * (that would require an admin-side JS bundle for form handling +
 * REST submission). Instead, the notice points the user at the PWA's
 * composer view where the capture form lives. Future Phase H session
 * may add a true inline admin form if the PWA round-trip becomes
 * an issue.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Pending_Syndication_Notice {

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Render the notice when:
	 *
	 *   - Current screen is the post editor (post.php or post-new.php)
	 *   - A post object exists in the request
	 *   - The post has at least one pending entry that's older than
	 *     the grace period
	 */
	public static function maybe_render(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || 'post' !== $screen->base ) {
			return;
		}

		$post_id = self::current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		// Reuse the detector by scoping to a single post — pass the
		// post author + filter results to this post.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$results = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user(
			(int) $post->post_author
		);
		$entries = self::entries_for_post( $results, $post_id );
		if ( empty( $entries ) ) {
			return;
		}

		self::render( $post_id, $entries );
	}

	/**
	 * @param array<int, array<string,mixed>> $results
	 * @return array<int, array<string,mixed>>
	 */
	private static function entries_for_post( array $results, int $post_id ): array {
		foreach ( $results as $row ) {
			if ( ( $row['post_id'] ?? 0 ) === $post_id && is_array( $row['entries'] ?? null ) ) {
				return $row['entries'];
			}
		}
		return array();
	}

	/**
	 * @param array<int, array<string,mixed>> $entries
	 */
	private static function render( int $post_id, array $entries ): void {
		$count = count( $entries );
		?>
		<div class="notice notice-info outpost-pending-syndication-notice">
			<p>
				<?php
				echo Outpost_Syndication_Admin_Column::render_badge_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<strong>
					<?php
					/* translators: %d: number of pending platforms. */
					$message = _n(
						'This post has %d pending syndication.',
						'This post has %d pending syndications.',
						$count,
						'outpost-mobile-publishing'
					);
					printf( esc_html( $message ), (int) $count );
					?>
				</strong>
			</p>
			<ul class="outpost-pending-syndication-notice__list">
				<?php foreach ( $entries as $entry ) : ?>
					<li>
						<?php
						$platform_id = (string) ( $entry['platform_id'] ?? '' );
						$fired_at    = (string) ( $entry['fired_at'] ?? '' );
						$strategy    = (string) ( $entry['strategy'] ?? '' );
						echo esc_html( self::label_for( $platform_id ) );
						if ( '' !== $fired_at ) {
							echo ' — ';
							echo esc_html( self::human_diff( $fired_at ) );
						}
						if ( '' !== $strategy ) {
							echo ' <span class="outpost-pending-syndication-notice__strategy">(';
							echo esc_html( $strategy );
							echo ')</span>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<?php esc_html_e( 'Open the Outpost composer to paste the silo URLs and complete syndication.', 'outpost-mobile-publishing' ); ?>
				<a href="<?php echo esc_url( home_url( '/post/' ) ); ?>" class="outpost-pending-syndication-notice__detail-link">
					<?php esc_html_e( 'View detail', 'outpost-mobile-publishing' ); ?>
				</a>
			</p>
		</div>
		<?php
		unset( $post_id );
	}

	private static function current_post_id(): int {
		// post=NN on post.php; new posts have no id yet.
		if ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ) {
			return (int) $GLOBALS['post']->ID;
		}
		return 0;
	}

	private static function label_for( string $platform_id ): string {
		$labels = array(
			'instagram-feed'    => 'Instagram',
			'instagram-stories' => 'Instagram Stories',
			'facebook'          => 'Facebook',
			'x-twitter'         => 'X',
			'linkedin'          => 'LinkedIn',
			'threads'           => 'Threads',
			'tiktok'            => 'TikTok',
			'pinterest'         => 'Pinterest',
			'reddit-manual'     => 'Reddit',
			'flickr-manual'     => 'Flickr',
		);
		return $labels[ $platform_id ] ?? ucwords( str_replace( '-', ' ', $platform_id ) );
	}

	private static function human_diff( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return '';
		}
		return sprintf(
			/* translators: %s: human time diff (e.g. "2 hours"). */
			__( 'fired %s ago', 'outpost-mobile-publishing' ),
			human_time_diff( $ts, time() )
		);
	}
}
