<?php
/**
 * Outpost_IOS_Shortcut_Settings_Page
 *
 * WP admin settings page at Settings → Outpost iOS Shortcut Bridge.
 * Renders the iCloud Shortcut link, the site URL and user name the
 * Shortcut needs, and the person's Shortcut application password.
 *
 * UI POSTURE
 *
 * Hard Contract per CLAUDE.md: zero color values in CSS. The page
 * uses standard WP admin styles (`.wrap`, `.notice`, `.form-table`,
 * `.button`, `.button-primary`). No custom CSS file.
 *
 * AUTH
 *
 * The Shortcut signs in with a WordPress Application Password that
 * Outpost creates through core (`Outpost_IOS_Shortcut_App_Password`).
 * The page renders for users with `manage_options`.
 *
 * CREATE FLOW
 *
 * The form posts back to this page. The `load-{$hook}` handler checks
 * the capability, then the nonce, then reads the requested action. A
 * new password is printed once in that same response and never stored
 * by Outpost; core keeps only its hash.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_Settings_Page {

	public const PAGE_SLUG    = 'outpost-ios-shortcut';
	public const NONCE_ACTION = 'outpost_ios_shortcut_password';
	public const NONCE_NAME   = 'outpost_ios_shortcut_nonce';
	public const ACTION_FIELD = 'outpost_ios_shortcut_action';

	private const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Path (relative to the plugin root) of the iCloud Shortcut link.
	 */
	private const BASE_LINK_FILE = 'assets/ios-shortcut-base-link.txt';

	/** Password created in this request, shown once. */
	private static ?string $new_password = null;

	/** Result notice for this request: created, revoked, or error. */
	private static string $notice = '';

	/** Error message when creation failed. */
	private static string $error_message = '';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Register the settings page and its POST handler.
	 */
	public static function register_menu(): void {
		$hook = add_options_page(
			__( 'Outpost iOS Shortcut Bridge', 'outpost-mobile-publishing' ),
			__( 'Outpost iOS Shortcut', 'outpost-mobile-publishing' ),
			self::REQUIRED_CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_post' ) );
		}
	}

	/**
	 * Handle the create / revoke form. Capability, then nonce, then input.
	 */
	public static function handle_post(): void {
		if ( 'POST' !== Outpost_Request_Headers::server_string( 'REQUEST_METHOD' ) ) {
			return;
		}
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Outpost iOS Shortcut.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$action  = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';
		$user_id = get_current_user_id();

		if ( 'create' === $action ) {
			$created = Outpost_IOS_Shortcut_App_Password::create( $user_id );
			if ( is_wp_error( $created ) ) {
				self::$notice        = 'error';
				self::$error_message = $created->get_error_message();
				return;
			}
			self::$new_password = $created;
			self::$notice       = 'created';
			return;
		}
		if ( 'revoke' === $action ) {
			Outpost_IOS_Shortcut_App_Password::revoke( $user_id );
			self::$notice = 'revoked';
		}
	}

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost-mobile-publishing' ) );
		}

		$user      = wp_get_current_user();
		$user_id   = (int) $user->ID;
		$available = Outpost_IOS_Shortcut_App_Password::is_available( $user_id );
		$item      = $available ? Outpost_IOS_Shortcut_App_Password::find( $user_id ) : null;
		$base_link = self::read_base_link();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Outpost iOS Shortcut Bridge', 'outpost-mobile-publishing' ); ?></h1>

			<?php self::render_result_notice(); ?>

			<p>
				<?php esc_html_e( 'iOS Safari does not support the Web Share Target API, so iOS users need a small Shortcut to send share-sheet content to Outpost. The ready-made Shortcut signs in with a WordPress application password that works only for Outpost\'s Shortcut endpoint.', 'outpost-mobile-publishing' ); ?>
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

			<h2><?php esc_html_e( 'Step 2 — Site URL, user name, and password', 'outpost-mobile-publishing' ); ?></h2>
			<p>
				<?php esc_html_e( 'After installing the Shortcut, open it once and paste these values into its prompts:', 'outpost-mobile-publishing' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="outpost-ios-shortcut-site-url"><?php esc_html_e( 'Site URL', 'outpost-mobile-publishing' ); ?></label>
						</th>
						<td>
							<input id="outpost-ios-shortcut-site-url" type="text" class="regular-text code" readonly value="<?php echo esc_attr( home_url() ); ?>">
							<p class="description"><?php esc_html_e( 'The Shortcut appends /wp-json/outpost/v1/shortcut to this URL.', 'outpost-mobile-publishing' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="outpost-ios-shortcut-user"><?php esc_html_e( 'User name', 'outpost-mobile-publishing' ); ?></label>
						</th>
						<td>
							<input id="outpost-ios-shortcut-user" type="text" class="regular-text code" readonly value="<?php echo esc_attr( $user->user_login ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="outpost-ios-shortcut-password"><?php esc_html_e( 'Application password', 'outpost-mobile-publishing' ); ?></label>
						</th>
						<td>
							<?php self::render_password_cell( $available, $item ); ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( $available ) : ?>
				<h2><?php esc_html_e( 'Password actions', 'outpost-mobile-publishing' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::ACTION_FIELD ); ?>" value="create">
					<p>
						<button type="submit" class="button button-primary">
							<?php
							echo null === $item
								? esc_html__( 'Create Shortcut password', 'outpost-mobile-publishing' )
								: esc_html__( 'Replace Shortcut password', 'outpost-mobile-publishing' );
							?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Replacing the password stops the old one at once. Update the Shortcut on your iPhone with the new value.', 'outpost-mobile-publishing' ); ?>
					</p>
				</form>
				<?php if ( null !== $item ) : ?>
					<form method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<input type="hidden" name="<?php echo esc_attr( self::ACTION_FIELD ); ?>" value="revoke">
						<p>
							<button type="submit" class="button"><?php esc_html_e( 'Revoke Shortcut password', 'outpost-mobile-publishing' ); ?></button>
						</p>
						<p class="description">
							<?php esc_html_e( 'Revoking deletes the password. You can also manage it under Users → Profile → Application Passwords.', 'outpost-mobile-publishing' ); ?>
						</p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The password row: the new password once, otherwise the status.
	 *
	 * @param bool                      $available Whether application passwords are available.
	 * @param array<string, mixed>|null $item      Current Outpost Shortcut password record.
	 */
	private static function render_password_cell( bool $available, ?array $item ): void {
		if ( ! $available && ! Outpost_IOS_Shortcut_App_Password::network_allows() ) {
			?>
			<p><em><?php esc_html_e( 'On a multisite network, the Shortcut password needs Outpost network-activated, so the rule that limits it to the Shortcut works on every site.', 'outpost-mobile-publishing' ); ?></em></p>
			<?php
			return;
		}
		if ( ! $available ) {
			?>
			<p><em><?php esc_html_e( 'WordPress application passwords are not available on this site. They need HTTPS (or a local development environment), and a security plugin may have turned them off.', 'outpost-mobile-publishing' ); ?></em></p>
			<?php
			return;
		}
		if ( null !== self::$new_password ) {
			?>
			<input id="outpost-ios-shortcut-password" type="text" class="regular-text code" readonly value="<?php echo esc_attr( self::$new_password ); ?>">
			<p class="description"><strong><?php esc_html_e( 'Copy this password now. It will not be shown again.', 'outpost-mobile-publishing' ); ?></strong></p>
			<?php
			return;
		}
		if ( null === $item ) {
			?>
			<p><em><?php esc_html_e( 'No Shortcut password yet.', 'outpost-mobile-publishing' ); ?></em></p>
			<?php
			return;
		}
		$format    = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
		$created   = isset( $item['created'] ) ? (int) $item['created'] : 0;
		$last_used = isset( $item['last_used'] ) ? (int) $item['last_used'] : 0;
		?>
		<p>
			<?php
			printf(
				/* translators: %s: date the password was created. */
				esc_html__( 'Created %s.', 'outpost-mobile-publishing' ),
				esc_html( (string) wp_date( $format, $created ) )
			);
			echo ' ';
			if ( $last_used > 0 ) {
				printf(
					/* translators: %s: date the password was last used. */
					esc_html__( 'Last used %s.', 'outpost-mobile-publishing' ),
					esc_html( (string) wp_date( $format, $last_used ) )
				);
			} else {
				esc_html_e( 'Not used yet. Run a test share from your iPhone.', 'outpost-mobile-publishing' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Result notice for a create / revoke in this request.
	 */
	private static function render_result_notice(): void {
		if ( 'created' === self::$notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Shortcut password created. Copy it below; it will not be shown again.', 'outpost-mobile-publishing' ) . '</p></div>';
		} elseif ( 'revoked' === self::$notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Shortcut password revoked.', 'outpost-mobile-publishing' ) . '</p></div>';
		} elseif ( 'error' === self::$notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::$error_message ) . '</p></div>';
		}
	}

	/**
	 * Reset per-request state. Test seam only.
	 */
	public static function reset_for_tests(): void {
		self::$new_password  = null;
		self::$notice        = '';
		self::$error_message = '';
	}

	/**
	 * Read the iCloud Shortcut link from the shipped text file.
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
	 * sentinel that ships until the Shortcut is published).
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
