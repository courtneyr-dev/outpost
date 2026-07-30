<?php
/**
 * Outpost_IOS_Shortcut_Settings_Page
 *
 * WP admin settings page at Settings → Outpost iOS Shortcut Bridge.
 * Renders the per-user token, the iCloud Shortcut link, the
 * connection status, and a regenerate-token action.
 *
 * UI POSTURE
 *
 * Hard Contract per CLAUDE.md: zero color values in CSS. The page
 * uses standard WP admin styles (`.wrap`, `.notice`, `.form-table`,
 * `.button`, `.button-primary`). No custom CSS file.
 *
 * AUTH
 *
 * Page renders for users with `manage_options`. On personal blogs
 * (Outpost's primary surface) the admin is the same user who shares
 * URLs from iOS, so admin-only fits. Multi-user installs would
 * surface this under Users → Profile in a future revision.
 *
 * REGENERATE FLOW
 *
 * Form submission with nonce + capability check. On valid POST:
 * regenerate the token (which also clears the first-seen marker per
 * Token::regenerate), redirect-after-POST so refresh doesn't replay.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_Settings_Page {

	public const PAGE_SLUG     = 'outpost-ios-shortcut';
	public const NONCE_ACTION  = 'outpost_ios_shortcut_regenerate';
	public const NONCE_NAME    = 'outpost_ios_shortcut_nonce';
	public const REGEN_ACTION  = 'outpost_ios_shortcut_regenerate_token';
	public const REVOKE_ACTION = 'outpost_ios_shortcut_revoke_token';

	private const REQUIRED_CAPABILITY = 'manage_options';

	private const BASE_LINK_FILE = 'assets/ios-shortcut-base-link.txt';

	/**
	 * Hook registration. Called once during plugin bootstrap.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::REGEN_ACTION, array( __CLASS__, 'handle_regenerate' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( __CLASS__, 'handle_revoke' ) );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Outpost iOS Shortcut Bridge', 'outpost-mobile-publishing' ),
			__( 'Outpost iOS Shortcut', 'outpost-mobile-publishing' ),
			self::REQUIRED_CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page renderer. Outputs HTML directly (admin-page convention).
	 */
	public static function render(): void {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost-mobile-publishing' ) );
		}

		$user_id    = get_current_user_id();
		$token      = Outpost_IOS_Shortcut_Token::get_token( $user_id );
		$first_seen = Outpost_IOS_Shortcut_Token::get_first_seen( $user_id );
		$base_link  = self::read_base_link();
		$site_url   = home_url();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Outpost iOS Shortcut Bridge', 'outpost-mobile-publishing' ); ?></h1>

			<?php self::render_admin_notices(); ?>

			<p>
				<?php esc_html_e( 'iOS Safari does not support the Web Share Target API, so iOS users need a small Shortcut to send share-sheet content to Outpost. Set up the bridge here.', 'outpost-mobile-publishing' ); ?>
			</p>

			<h2><?php esc_html_e( 'Step 1 — Install the Shortcut', 'outpost-mobile-publishing' ); ?></h2>
			<?php if ( self::is_real_link( $base_link ) ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $base_link ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open the Outpost Share Bridge Shortcut', 'outpost-mobile-publishing' ); ?>
					</a>
				</p>
				<p class="description">
					<?php esc_html_e( 'Open the link on your iPhone or iPad. iOS will offer to add the Shortcut to your library.', 'outpost-mobile-publishing' ); ?>
				</p>
			<?php else : ?>
				<p>
					<em><?php esc_html_e( 'iCloud Shortcut link is not yet published. Check back after the next plugin release.', 'outpost-mobile-publishing' ); ?></em>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Step 2 — Site URL + token', 'outpost-mobile-publishing' ); ?></h2>
			<p>
				<?php esc_html_e( 'After installing the Shortcut, open it once and paste these values into the prompts:', 'outpost-mobile-publishing' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="outpost-ios-shortcut-site-url">
								<?php esc_html_e( 'Site URL', 'outpost-mobile-publishing' ); ?>
							</label>
						</th>
						<td>
							<input
								id="outpost-ios-shortcut-site-url"
								type="text"
								class="regular-text code"
								readonly
								value="<?php echo esc_attr( $site_url ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'The Shortcut appends /wp-json/outpost/v1/shortcut to this URL.', 'outpost-mobile-publishing' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="outpost-ios-shortcut-token">
								<?php esc_html_e( 'Token', 'outpost-mobile-publishing' ); ?>
							</label>
						</th>
						<td>
							<?php if ( null !== $token ) : ?>
								<input
									id="outpost-ios-shortcut-token"
									type="text"
									class="regular-text code"
									readonly
									value="<?php echo esc_attr( $token ); ?>"
								>
								<p class="description">
									<?php esc_html_e( 'Paste this into the token prompt of the Shortcut. Treat it like a password.', 'outpost-mobile-publishing' ); ?>
								</p>
							<?php else : ?>
								<p>
									<em><?php esc_html_e( 'No token issued yet.', 'outpost-mobile-publishing' ); ?></em>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Step 3 — Connection status', 'outpost-mobile-publishing' ); ?></h2>
			<?php self::render_status( $first_seen ); ?>

			<h2><?php esc_html_e( 'Token actions', 'outpost-mobile-publishing' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REGEN_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<p>
					<button type="submit" class="button button-primary">
						<?php
						echo null === $token
							? esc_html__( 'Generate token', 'outpost-mobile-publishing' )
							: esc_html__( 'Regenerate token', 'outpost-mobile-publishing' );
						?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Regenerating immediately invalidates the previous token. You will need to update the Shortcut on your iPhone with the new value.', 'outpost-mobile-publishing' ); ?>
				</p>
			</form>

			<?php if ( null !== $token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<p>
						<button type="submit" class="button">
							<?php esc_html_e( 'Revoke token', 'outpost-mobile-publishing' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Revoking removes the token entirely. Use this if you no longer want the iOS Shortcut to work, or if you suspect the token was leaked.', 'outpost-mobile-publishing' ); ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the connection-status block.
	 *
	 * @param string|null $first_seen ISO 8601 timestamp or null.
	 */
	private static function render_status( ?string $first_seen ): void {
		if ( null === $first_seen ) {
			?>
			<p>
				<strong><?php esc_html_e( 'Not connected yet.', 'outpost-mobile-publishing' ); ?></strong>
				<?php esc_html_e( 'Open the Shortcut on your iPhone and run a test share. The status will update on the next page load.', 'outpost-mobile-publishing' ); ?>
			</p>
			<?php
			return;
		}

		$timestamp = strtotime( $first_seen );
		if ( false === $timestamp ) {
			?>
			<p>
				<strong><?php esc_html_e( 'Connected.', 'outpost-mobile-publishing' ); ?></strong>
			</p>
			<?php
			return;
		}

		$human = wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $timestamp );
		?>
		<p>
			<strong><?php esc_html_e( 'Connected.', 'outpost-mobile-publishing' ); ?></strong>
			<?php
			printf(
				/* translators: %s: human-readable date/time of first successful connection. */
				esc_html__( 'First successful share: %s', 'outpost-mobile-publishing' ),
				esc_html( (string) $human )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render `?notice=...` query-string-driven admin notices after
	 * the redirect-after-POST hop.
	 */
	private static function render_admin_notices(): void {
		// Read-only notice flags from the redirect target. Sanitized
		// against an enum to prevent reflected-XSS-via-query-string.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag, not a state-mutating action.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		switch ( $notice ) {
			case 'regenerated':
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Token regenerated. Update your iOS Shortcut with the new value.', 'outpost-mobile-publishing' )
				);
				break;
			case 'revoked':
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Token revoked.', 'outpost-mobile-publishing' )
				);
				break;
		}
	}

	/**
	 * `admin-post.php` handler for token regeneration.
	 */
	public static function handle_regenerate(): void {
		self::guard_post_request();
		Outpost_IOS_Shortcut_Token::regenerate( get_current_user_id() );
		self::redirect_with_notice( 'regenerated' );
	}

	/**
	 * `admin-post.php` handler for token revocation.
	 */
	public static function handle_revoke(): void {
		self::guard_post_request();
		Outpost_IOS_Shortcut_Token::revoke( get_current_user_id() );
		self::redirect_with_notice( 'revoked' );
	}

	/**
	 * Verify nonce + capability for token-write requests. Bails with
	 * wp_die() if either fails.
	 */
	private static function guard_post_request(): void {
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'outpost-mobile-publishing' ) );
		}
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Outpost iOS Shortcut tokens.', 'outpost-mobile-publishing' ) );
		}
	}

	/**
	 * Redirect back to the settings page after a successful action.
	 *
	 * @param string $notice Notice slug surfaced via ?notice=.
	 */
	private static function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'notice' => $notice,
			),
			admin_url( 'options-general.php' )
		);
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Read the bundled iCloud Shortcut link. Returns empty string
	 * when the file is missing or unreadable so the settings page
	 * gracefully shows the placeholder state.
	 */
	private static function read_base_link(): string {
		$path = OUTPOST_PLUGIN_DIR . self::BASE_LINK_FILE;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, not a remote URL.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return '';
		}
		return trim( $contents );
	}

	/**
	 * Whether the read link is the live URL (vs the placeholder
	 * sentinel that ships with this commit).
	 */
	private static function is_real_link( string $link ): bool {
		if ( '' === $link ) {
			return false;
		}
		if ( false !== strpos( $link, 'PLACEHOLDER' ) ) {
			return false;
		}
		return 0 === strpos( $link, 'https://www.icloud.com/shortcuts/' );
	}
}
