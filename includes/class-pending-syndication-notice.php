<?php
/**
 * Outpost_Pending_Syndication_Notice
 *
 * WP admin notice surface for the F12 phase-2 capture flow. When a
 * user views a post's edit screen AND the post has pending audit log
 * entries, surface the platforms that still need a silo URL. The
 * reminder complements the composer's PendingSyndications panel —
 * users who edit posts in WP admin (rather than in the PWA) get the
 * same prompt.
 *
 * TWO SURFACES, one per editor:
 *
 *   - Classic editor: `admin_notices` renders the HTML below.
 *   - Block editor: `admin_notices` is a dead end there — core prints
 *     it inside `.wrap.hide-if-js.block-editor-no-js`, the no-JS
 *     fallback container, which is hidden whenever the editor loads.
 *     So `enqueue_editor_notice()` attaches the same data to the
 *     sidebar bundle instead, and the bundle raises a `core/notices`
 *     notice. `maybe_render()` bails on block-editor screens rather
 *     than emitting markup nobody can see.
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

	/**
	 * Handle of the inline payload the block-editor bundle reads.
	 */
	public const PAYLOAD_GLOBAL = 'outpostPendingSyndication';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
		// `admin_notices` is a dead surface in the block editor: core
		// prints it inside `.wrap.hide-if-js.block-editor-no-js`, the
		// no-JS fallback container, which is hidden the moment the
		// editor loads. Hand the same data to the editor bundle so it
		// can raise a real `core/notices` notice. Priority 20 so the
		// sidebar handle is already enqueued by
		// Outpost_Sidebar_Assets::enqueue() at the default 10.
		add_action(
			'enqueue_block_editor_assets',
			array( self::class, 'enqueue_editor_notice' ),
			20
		);
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
		$screen = self::post_editor_screen();
		if ( null === $screen ) {
			return;
		}
		if ( self::is_block_editor_screen( $screen ) ) {
			// Output here would land in the hidden no-JS container.
			// enqueue_editor_notice() covers this screen instead.
			return;
		}

		$post_id = self::current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$entries = self::pending_entries( $post_id );
		if ( empty( $entries ) ) {
			return;
		}

		self::render( $post_id, $entries );
	}

	/**
	 * Attach the pending-syndication payload to the block-editor
	 * bundle. The bundle turns it into a `core/notices` notice, which
	 * is the only notice surface the block editor actually renders.
	 *
	 * No-ops when the sidebar bundle isn't enqueued (missing build) —
	 * wp_add_inline_script() returns false for an unknown handle.
	 */
	public static function enqueue_editor_notice(): void {
		if ( null === self::post_editor_screen() ) {
			return;
		}

		$post_id = self::current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$payload = self::editor_notice_payload( $post_id );
		if ( array() === $payload ) {
			return;
		}

		wp_add_inline_script(
			Outpost_Sidebar_Assets::HANDLE,
			sprintf(
				'window.%s = %s;',
				self::PAYLOAD_GLOBAL,
				wp_json_encode( $payload )
			),
			'before'
		);
	}

	/**
	 * Build the JS-facing payload for a post. Strings are localized
	 * here so the bundle doesn't have to reimplement the platform
	 * label map or the plural rules.
	 *
	 * Returns an empty array when the post has nothing pending.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_notice_payload( int $post_id ): array {
		$entries = self::pending_entries( $post_id );
		if ( empty( $entries ) ) {
			return array();
		}

		$platforms = array();
		foreach ( $entries as $entry ) {
			$platform_id = (string) ( $entry['platform_id'] ?? '' );
			$fired_at    = (string) ( $entry['fired_at'] ?? '' );
			$platforms[] = array(
				'id'         => $platform_id,
				'label'      => self::label_for( $platform_id ),
				'firedAt'    => $fired_at,
				'firedHuman' => '' === $fired_at ? '' : self::human_diff( $fired_at ),
				'strategy'   => (string) ( $entry['strategy'] ?? '' ),
			);
		}

		$count = count( $platforms );

		/* translators: %d: number of pending platforms. */
		$message = _n(
			'This post has %d pending syndication.',
			'This post has %d pending syndications.',
			$count,
			'outpost-mobile-publishing'
		);

		return array(
			'postId'      => $post_id,
			'count'       => $count,
			'message'     => sprintf( $message, $count ),
			'platforms'   => $platforms,
			'composerUrl' => esc_url_raw( home_url( '/post/' ) ),
			'actionLabel' => __( 'Open the Outpost composer', 'outpost-mobile-publishing' ),
		);
	}

	/**
	 * The current screen when it's the post editor, else null.
	 *
	 * @return WP_Screen|null
	 */
	private static function post_editor_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		if ( null === $screen || 'post' !== $screen->base ) {
			return null;
		}
		return $screen;
	}

	/**
	 * @param object $screen Current screen.
	 */
	private static function is_block_editor_screen( $screen ): bool {
		return method_exists( $screen, 'is_block_editor' )
			&& (bool) $screen->is_block_editor();
	}

	/**
	 * Pending entries on one post. Reuses the detector by scoping to
	 * the post author, then filtering results down to this post.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private static function pending_entries( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$results = Outpost_Manual_Share_Pending_Capture_Detector::pending_for_user(
			(int) $post->post_author
		);
		return self::entries_for_post( $results, $post_id );
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
